import { afterEach, describe, expect, it, vi } from "vitest";
import { api, setUnauthorizedHandler } from "@/lib/api";

afterEach(() => {
  setUnauthorizedHandler(null);
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe("API failure recovery", () => {
  it("bounds a stalled mutation without replaying it or signing the user out", async () => {
    const deadline = new AbortController();
    vi.spyOn(AbortSignal, "timeout").mockReturnValue(deadline.signal);
    const unauthorized = vi.fn();
    setUnauthorizedHandler(unauthorized);
    const fetcher = vi.fn()
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockImplementationOnce((_url, init: RequestInit) => new Promise((_resolve, reject) => {
        init.signal?.addEventListener("abort", () => reject(new DOMException("Timed out", "AbortError")), { once: true });
      }));
    vi.stubGlobal("fetch", fetcher);
    const request = api("/api/v1/example", { method: "POST", body: "{}" });
    const rejected = expect(request).rejects.toMatchObject({ status: 0 });
    await vi.waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2));
    deadline.abort();
    await rejected;
    expect(fetcher).toHaveBeenCalledTimes(2);
    expect(unauthorized).not.toHaveBeenCalled();
  });

  it("does not present an unreadable successful response as saved data", async () => {
    const fetcher = vi.fn().mockResolvedValue(new Response("broken JSON", { status: 200 }));
    vi.stubGlobal("fetch", fetcher);
    await expect(api("/api/v1/example")).rejects.toMatchObject({ status: 0, message: expect.stringContaining("could not be read") });
    expect(fetcher).toHaveBeenCalledTimes(1);
  });

  it("preserves the restriction against replaying an unkeyed POST after CSRF expiry", async () => {
    const fetcher = vi.fn()
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ message: "Expired" }), { status: 419 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: { id: 1 } }), { status: 200 }));
    vi.stubGlobal("fetch", fetcher);
    await expect(api("/api/v1/example", { method: "POST", body: "{}" })).rejects.toMatchObject({ status: 419 });
    expect(fetcher.mock.calls.filter(([, init]) => init.method === "POST")).toHaveLength(1);
  });
});
