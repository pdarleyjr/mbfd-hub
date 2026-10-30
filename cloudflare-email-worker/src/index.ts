import PostalMime from "postal-mime";

interface Env {
  HUB_INBOUND_URL: string;
  HUB_INBOUND_SECRET: string;
  MAX_RAW_BYTES: string;
  MAX_ATTACHMENT_BYTES: string;
}

function bytesToHex(bytes: ArrayBuffer): string {
  return [...new Uint8Array(bytes)].map((byte) => byte.toString(16).padStart(2, "0")).join("");
}

export async function sign(secret: string, timestamp: string, nonce: string, body: string): Promise<string> {
  const key = await crypto.subtle.importKey(
    "raw",
    new TextEncoder().encode(secret),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign"],
  );
  return bytesToHex(await crypto.subtle.sign("HMAC", key, new TextEncoder().encode(`${timestamp}\n${nonce}\n${body}`)));
}

export function encodeBase64(content: string | ArrayBuffer | Uint8Array): string {
  const bytes = typeof content === "string"
    ? new TextEncoder().encode(content)
    : content instanceof Uint8Array ? content : new Uint8Array(content);
  let binary = "";
  for (let offset = 0; offset < bytes.byteLength; offset += 32768) {
    binary += String.fromCharCode(...bytes.subarray(offset, offset + 32768));
  }

  return btoa(binary);
}

const EVIDENCE_MAX_LENGTH = 4096;

const CLOUDFLARE_AUTHSERV_ID = "mx.cloudflare.net";

type RawHeader = { key: string; value: string };

function cleanHeaderValue(value: string): string {
  return value.replace(/[\u0000-\u001f\u007f-\u009f]/g, "");
}

// Forwards the first occurrence in raw header order. message.headers merges duplicates, but
// postal-mime's parsed.headers keeps the raw top-to-bottom order. A value over the cap is never cut:
// half a results header reads as a different verdict, so it goes as null with truncated set.
export function firstHeaderValue(headers: readonly RawHeader[], name: string): { value: string | null; truncated: boolean } {
  const raw = headers.find((header) => header.key === name)?.value;
  const clean = raw === undefined ? "" : cleanHeaderValue(raw);
  if (clean.length > EVIDENCE_MAX_LENGTH) {
    return { value: null, truncated: true };
  }

  return { value: clean === "" ? null : clean, truncated: false };
}

// RFC 8601 comments may hold anything, including text shaped like an authserv-id. Null means quotes or
// parentheses do not balance. Mirrors TeleStaffMailClassifier::stripComments in the Hub, so both sides
// agree on what a header claims.
function stripComments(header: string): string | null {
  let output = "";
  let depth = 0;
  let inQuote = false;
  for (let index = 0; index < header.length; index++) {
    const character = header[index];
    if (character === "\\" && (inQuote || depth > 0)) {
      if (inQuote) {
        output += character + (header[index + 1] ?? "");
      }
      index++;
    } else if (inQuote) {
      output += character;
      inQuote = character !== '"';
    } else if (character === "(") {
      depth++;
    } else if (character === ")") {
      if (--depth < 0) {
        return null;
      }
    } else if (depth === 0) {
      output += character;
      inQuote = character === '"';
    }
  }

  return depth === 0 && !inQuote ? output : null;
}

// Whether this header names Cloudflare as its author. A header too damaged to read counts as a claim
// when it mentions Cloudflare at all: counting too many only holds a message for review.
export function claimsCloudflare(value: string, isArc: boolean): boolean {
  const clean = cleanHeaderValue(value);
  const text = stripComments(clean);
  if (text === null) {
    return clean.toLowerCase().split(/[^a-z0-9.-]+/).some((token) => token === CLOUDFLARE_AUTHSERV_ID);
  }
  const body = isArc ? text.replace(/^[ \t]*i[ \t]*=[ \t]*\d+[ \t]*;/i, "") : text;
  const authservId = /^[ \t]*([^ \t;]+)/.exec(body)?.[1] ?? "";

  return authservId.replace(/^"|"$/g, "").toLowerCase() === CLOUDFLARE_AUTHSERV_ID;
}

function countCloudflareClaims(headers: readonly RawHeader[], name: string, isArc: boolean): number {
  return headers.filter((header) => header.key === name && claimsCloudflare(header.value, isArc)).length;
}

// Which header Cloudflare writes has not been observed live, so nothing here assumes one kind or a
// position. The counts let the Hub see a second claim it was not sent: the real one plus an injected one.
export function authenticationEvidence(headers: readonly RawHeader[]) {
  const results = firstHeaderValue(headers, "authentication-results");
  const arcResults = firstHeaderValue(headers, "arc-authentication-results");
  const receivedSpf = firstHeaderValue(headers, "received-spf");

  return {
    authentication_results: results.value,
    arc_authentication_results: arcResults.value,
    received_spf: receivedSpf.value,
    cloudflare_claims: {
      authentication_results: countCloudflareClaims(headers, "authentication-results", false),
      arc_authentication_results: countCloudflareClaims(headers, "arc-authentication-results", true),
    },
    truncated: results.truncated || arcResults.truncated || receivedSpf.truncated,
  };
}

export default {
  async email(message: ForwardableEmailMessage, env: Env): Promise<void> {
    if (!env.HUB_INBOUND_SECRET || !env.HUB_INBOUND_URL.startsWith("https://")) {
      message.setReject("Inbound delivery is not configured");
      return;
    }
    if (message.to.toLowerCase() !== "info@mbfdhub.com") {
      message.setReject("Unknown recipient");
      return;
    }

    const maxRawBytes = Number(env.MAX_RAW_BYTES || "3000000");
    if (message.rawSize > maxRawBytes) {
      message.setReject("Message too large");
      return;
    }
    const raw = await new Response(message.raw).arrayBuffer();
    if (raw.byteLength > maxRawBytes) {
      message.setReject("Message too large");
      return;
    }
    const parsed = await new PostalMime().parse(raw);
    const maxAttachmentBytes = Number(env.MAX_ATTACHMENT_BYTES || "2000000");
    const attachmentBytes = parsed.attachments.reduce((total, attachment) => total + (
      typeof attachment.content === "string"
        ? new TextEncoder().encode(attachment.content).byteLength
        : attachment.content.byteLength
    ), 0);
    if (attachmentBytes > maxAttachmentBytes) {
      message.setReject("Attachments exceed the 2 MB total limit");
      return;
    }
    const body = JSON.stringify({
      message_id: parsed.messageId || crypto.randomUUID(),
      from: parsed.from?.address || message.from,
      from_name: parsed.from?.name || null,
      envelope_from: message.from,
      authentication_evidence: authenticationEvidence(parsed.headers),
      to: message.to,
      subject: parsed.subject || null,
      text: parsed.text || null,
      html: parsed.html || null,
      received_at: new Date().toISOString(),
      safe_headers: {
        date: parsed.date || null,
        to: parsed.to?.map((entry) => entry.address).filter(Boolean) || [],
        cc: parsed.cc?.map((entry) => entry.address).filter(Boolean) || [],
        "reply-to": parsed.replyTo?.map((entry) => entry.address).join(", ") || null,
      },
      in_reply_to: parsed.inReplyTo || null,
      references: parsed.references || [],
      attachments: parsed.attachments.map((attachment) => ({
        filename: attachment.filename || null,
        mime_type: attachment.mimeType,
        size: typeof attachment.content === "string"
          ? new TextEncoder().encode(attachment.content).byteLength
          : attachment.content.byteLength,
        disposition: attachment.disposition,
        content: encodeBase64(attachment.content),
      })),
    });
    const timestamp = Math.floor(Date.now() / 1000).toString();
    const nonce = crypto.randomUUID();
    const signature = await sign(env.HUB_INBOUND_SECRET, timestamp, nonce, body);
    const response = await fetch(env.HUB_INBOUND_URL, {
      method: "POST",
      headers: {
        "content-type": "application/json",
        "x-mbfd-timestamp": timestamp,
        "x-mbfd-nonce": nonce,
        "x-mbfd-signature": signature,
      },
      body,
    });
    if (!response.ok) {
      throw new Error(`Hub inbound endpoint failed with ${response.status}`);
    }
  },
};
