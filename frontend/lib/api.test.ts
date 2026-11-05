import { afterEach, describe, expect, it, vi } from "vitest";
import { api, invalidateApiSession, setUnauthorizedHandler } from "@/lib/api";

afterEach(() => {
  document.cookie = "XSRF-TOKEN=; Max-Age=0; path=/";
  setUnauthorizedHandler(null);
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe("API failure recovery", () => {
  it("rejects an old 401 without signing a newer session out", async () => {
    let finish: (response: Response) => void = () => { throw new Error("Request not started"); };
    vi.stubGlobal("fetch", vi.fn(() => new Promise<Response>(resolve => { finish = resolve; })));
    const unauthorized = vi.fn(); setUnauthorizedHandler(unauthorized);
    const pending = api("/api/v1/old-session");
    invalidateApiSession();
    finish(new Response("{}", { status: 401 }));
    await expect(pending).rejects.toMatchObject({ status: 401 });
    expect(unauthorized).not.toHaveBeenCalled();
  });
  it("reuses an existing CSRF cookie while every mutation still sends the token", async () => {
    document.cookie = "XSRF-TOKEN=synthetic-token; path=/";
    const fetcher = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: {} }), { status: 200 }));
    vi.stubGlobal("fetch", fetcher);
    await api("/api/v1/example", { method: "POST", body: "{}" });
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(fetcher.mock.calls[0][1].headers.get("X-XSRF-TOKEN")).toBe("synthetic-token");
    expect(fetcher.mock.calls[0][1].cache).toBe("no-store");
  });

  it("shares only a concurrent CSRF bootstrap, never mutation results", async () => {
    const fetcher = vi.fn().mockImplementation(async (url: string) => url.endsWith("csrf-cookie")
      ? new Response(null, { status: 204 }) : new Response(JSON.stringify({ data: {} }), { status: 200 }));
    vi.stubGlobal("fetch", fetcher);
    await Promise.all([api("/a", { method: "POST" }), api("/b", { method: "POST" })]);
    expect(fetcher.mock.calls.filter(([url]) => url.endsWith("csrf-cookie"))).toHaveLength(1);
    expect(fetcher.mock.calls.filter(([, init]) => init.method === "POST")).toHaveLength(2);
  });

  it("forces renewal after a 419 and retries only an eligible mutation", async () => {
    document.cookie = "XSRF-TOKEN=expired; path=/";
    const fetcher = vi.fn().mockResolvedValueOnce(new Response("{}", { status: 419 }))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(new Response('{"data":{}}', { status: 200 }));
    vi.stubGlobal("fetch", fetcher);
    await api("/a", { method: "PUT", body: "{}" });
    expect(fetcher).toHaveBeenCalledTimes(3);
  });
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
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: { id: 1 } }), { status: 200 }));
    vi.stubGlobal("fetch", fetcher);
    await expect(api("/api/v1/example", { method: "POST", body: "{}" })).rejects.toMatchObject({ status: 419 });
    expect(fetcher.mock.calls.filter(([, init]) => init.method === "POST")).toHaveLength(1);
  });

  it("renews a sibling-profile token for the next explicit attempt without duplicate submission", async () => {
    document.cookie = "XSRF-TOKEN=sibling-profile; path=/";
    let posts = 0;
    const fetcher = vi.fn(async (url: string, init: RequestInit) => {
      if (url.endsWith("csrf-cookie")) {
        document.cookie = "XSRF-TOKEN=owned-profile; path=/";
        return new Response(null, { status: 204 });
      }
      if (init.method === "POST") {
        posts++;
        if (posts === 1) return new Response("{}", { status: 419 });
        expect(new Headers(init.headers).get("X-XSRF-TOKEN")).toBe("owned-profile");
      }
      return new Response('{"data":{}}', { status: 200 });
    });
    vi.stubGlobal("fetch", fetcher);
    await expect(api("/a", { method: "POST" })).rejects.toMatchObject({ status: 419 });
    expect(posts).toBe(1);
    await api("/a", { method: "POST" });
    expect(posts).toBe(2);
  });
});
