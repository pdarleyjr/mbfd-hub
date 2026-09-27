import { afterEach, expect, it, vi } from "vitest";
import worker from "../src/index";

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

it("rejects raw messages over 3 MB even if the reported size is inaccurate", async () => {
  const fetcher = vi.fn();
  vi.stubGlobal("fetch", fetcher);
  const raw = `From: sender@example.test\r\nTo: info@mbfdhub.com\r\n\r\n${"x".repeat(3_000_000)}`;

  expect(await deliver(raw, 1)).toHaveBeenCalledWith("Message too large");
  expect(fetcher).not.toHaveBeenCalled();
});
