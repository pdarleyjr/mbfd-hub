import PostalMime from "postal-mime";

interface Env {
  HUB_INBOUND_URL: string;
  HUB_INBOUND_SECRET: string;
  MAX_RAW_BYTES: string;
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

    const maxRawBytes = Number(env.MAX_RAW_BYTES || "4500000");
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
    const body = JSON.stringify({
      message_id: parsed.messageId || crypto.randomUUID(),
      from: parsed.from?.address || message.from,
      from_name: parsed.from?.name || null,
      to: message.to,
      subject: parsed.subject || null,
      text: parsed.text || null,
      html: parsed.html || null,
      received_at: new Date().toISOString(),
      safe_headers: {
        date: parsed.date || null,
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
