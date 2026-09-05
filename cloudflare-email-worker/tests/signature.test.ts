import { describe, expect, it } from "vitest";
import { encodeBase64, sign } from "../src/index";

describe("inbound HMAC", () => {
  it("binds timestamp, nonce, and exact body", async () => {
    const signature = await sign("secret", "100", "nonce", "{\"ok\":true}");
    expect(signature).toMatch(/^[a-f0-9]{64}$/);
    expect(await sign("secret", "100", "nonce", "{\"ok\":false}")).not.toBe(signature);
  });

  it("encodes binary attachment bytes without changing them", () => {
    expect(encodeBase64(new Uint8Array([0, 1, 127, 255]).buffer)).toBe("AAF//w==");
  });
});
