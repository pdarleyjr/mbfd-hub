import { afterEach, expect, it, vi } from "vitest";
import worker, { firstHeaderValue } from "../src/index";

afterEach(() => vi.unstubAllGlobals());

function attachmentMessage(size: number): string {
  const encoded = Buffer.alloc(size, 1).toString("base64").replace(/.{1,76}/g, "$&\r\n");
  return [
    "From: Sender <sender@example.test>",
    "To: info@mbfdhub.com",
    "Message-ID: <attachment@example.test>",
    "Subject: Attachment boundary",
    "MIME-Version: 1.0",
    'Content-Type: multipart/mixed; boundary="mbfd-boundary"',
    "",
    "--mbfd-boundary",
    "Content-Type: image/png",
    'Content-Disposition: attachment; filename="sample.png"',
    "Content-Transfer-Encoding: base64",
    "",
    encoded,
    "--mbfd-boundary--",
    "",
  ].join("\r\n");
}

async function deliver(raw: string, rawSize = Buffer.byteLength(raw)) {
  const reject = vi.fn();
  await worker.email({
    from: "sender@example.test", to: "info@mbfdhub.com", rawSize,
    raw: new Response(raw).body!, setReject: reject,
  } as unknown as ForwardableEmailMessage, {
    HUB_INBOUND_URL: "https://hub.example.test/api/v2/email/inbound",
    HUB_INBOUND_SECRET: "test-only-secret", MAX_RAW_BYTES: "3000000",
    MAX_ATTACHMENT_BYTES: "2000000",
  });
  return reject;
}

it("preserves reply-all envelope and exact threading IDs without forwarding BCC", async () => {
  const raw = [
    "From: Sender <sender@example.test>",
    "To: Hub <info@mbfdhub.com>, Other <other@example.test>",
    "Cc: Colleague <colleague@example.test>",
    "Bcc: hidden@example.test",
    "Reply-To: replies@example.test",
    "Message-ID: <incoming@example.test>",
    "In-Reply-To: <parent@example.test>",
    "References: <first@example.test> <parent@example.test>",
    "Subject: Operational question",
    "", "Please review.",
  ].join("\r\n");
  const fetcher = vi.fn().mockResolvedValue(new Response("{}", { status: 201 }));
  vi.stubGlobal("fetch", fetcher);
  const reject = vi.fn();
  await worker.email({
    from: "sender@example.test", to: "info@mbfdhub.com", rawSize: raw.length,
    raw: new Response(raw).body!, setReject: reject,
  } as unknown as ForwardableEmailMessage, {
    HUB_INBOUND_URL: "https://hub.example.test/api/v2/email/inbound",
    HUB_INBOUND_SECRET: "test-only-secret", MAX_RAW_BYTES: "3000000",
    MAX_ATTACHMENT_BYTES: "2000000",
  });
  expect(reject).not.toHaveBeenCalled();
  const body = JSON.parse(fetcher.mock.calls[0][1].body);
  expect(body.safe_headers.to).toEqual(["info@mbfdhub.com", "other@example.test"]);
  expect(body.safe_headers.cc).toEqual(["colleague@example.test"]);
  expect(body.safe_headers["reply-to"]).toBe("replies@example.test");
  expect(body.in_reply_to).toBe("<parent@example.test>");
  expect(body.references).toContain("<first@example.test>");
  expect(JSON.stringify(body)).not.toContain("hidden@example.test");
});

it("accepts 2 MB of decoded attachments within the 3 MB raw MIME ceiling", async () => {
  const fetcher = vi.fn().mockResolvedValue(new Response("{}", { status: 201 }));
  vi.stubGlobal("fetch", fetcher);
  const raw = attachmentMessage(2_000_000);
  expect(Buffer.byteLength(raw)).toBeLessThan(3_000_000);

  expect(await deliver(raw)).not.toHaveBeenCalled();
  expect(fetcher).toHaveBeenCalledOnce();
  expect(JSON.parse(fetcher.mock.calls[0][1].body).attachments[0].size).toBe(2_000_000);
});

it("rejects attachments over 2 MB even when the raw message fits", async () => {
  const fetcher = vi.fn();
  vi.stubGlobal("fetch", fetcher);
  const raw = attachmentMessage(2_000_001);
  expect(Buffer.byteLength(raw)).toBeLessThan(3_000_000);

  expect(await deliver(raw)).toHaveBeenCalledWith("Attachments exceed the 2 MB total limit");
  expect(fetcher).not.toHaveBeenCalled();
});

function transportMessage(headers: string[]): string {
  return [
    ...headers,
    "From: Sender <header-from@example.test>",
    "To: info@mbfdhub.com",
    "Message-ID: <transport@example.test>",
    "Subject: Transport evidence",
    "", "Body.",
  ].join("\r\n");
}

async function forwardedBody(raw: string) {
  const fetcher = vi.fn().mockResolvedValue(new Response("{}", { status: 201 }));
  vi.stubGlobal("fetch", fetcher);
  expect(await deliver(raw)).not.toHaveBeenCalled();

  return JSON.parse(fetcher.mock.calls[0][1].body);
}

it("forwards the envelope sender separately from the header From", async () => {
  const body = await forwardedBody(transportMessage([]));

  expect(body.envelope_from).toBe("sender@example.test");
  expect(body.from).toBe("header-from@example.test");
});

it("forwards only the topmost occurrence of each authentication header", async () => {
  const body = await forwardedBody(transportMessage([
    "Authentication-Results: mx.cloudflare.net; dmarc=pass header.from=example.test",
    "ARC-Authentication-Results: i=2; mx.cloudflare.net; dkim=pass header.d=example.test",
    "Received-SPF: pass (mx.cloudflare.net: domain designates sender) client-ip=192.0.2.1;",
    "Authentication-Results: forged.example; dmarc=fail",
    "ARC-Authentication-Results: i=1; forged.example; dkim=fail",
    "Received-SPF: fail (forged.example)",
  ]));

  expect(body.authentication_evidence).toEqual({
    authentication_results: "mx.cloudflare.net; dmarc=pass header.from=example.test",
    arc_authentication_results: "i=2; mx.cloudflare.net; dkim=pass header.d=example.test",
    received_spf: "pass (mx.cloudflare.net: domain designates sender) client-ip=192.0.2.1;",
  });
});

it("forwards null for authentication headers the message does not have", async () => {
  const body = await forwardedBody(transportMessage([]));

  expect(body.authentication_evidence).toEqual({
    authentication_results: null,
    arc_authentication_results: null,
    received_spf: null,
  });
});

it("unfolds, strips control characters from, and caps authentication headers", async () => {
  const folded = await forwardedBody(transportMessage([
    "Authentication-Results: mx.cloudflare.net;\r\n\tdkim=pass\r\n header.d=example.test\u0007",
    `Received-SPF: ${"x".repeat(3000)}`,
  ]));

  expect(folded.authentication_evidence.authentication_results).toBe("mx.cloudflare.net; dkim=pass header.d=example.test");
  expect(folded.authentication_evidence.received_spf).toHaveLength(2048);
});

it("takes the first occurrence and treats an empty value as absent", () => {
  expect(firstHeaderValue([
    { key: "authentication-results", value: "first" },
    { key: "authentication-results", value: "second" },
  ], "authentication-results")).toBe("first");
  expect(firstHeaderValue([], "authentication-results")).toBeNull();
  expect(firstHeaderValue([{ key: "received-spf", value: "\u0001" }], "received-spf")).toBeNull();
});

it("rejects raw messages over 3 MB even if the reported size is inaccurate", async () => {
  const fetcher = vi.fn();
  vi.stubGlobal("fetch", fetcher);
  const raw = `From: sender@example.test\r\nTo: info@mbfdhub.com\r\n\r\n${"x".repeat(3_000_000)}`;

  expect(await deliver(raw, 1)).toHaveBeenCalledWith("Message too large");
  expect(fetcher).not.toHaveBeenCalled();
});
