import { afterEach, expect, it, vi } from "vitest";
import worker from "../src/index";

afterEach(() => vi.unstubAllGlobals());

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
    HUB_INBOUND_SECRET: "test-only-secret", MAX_RAW_BYTES: "4500000",
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
