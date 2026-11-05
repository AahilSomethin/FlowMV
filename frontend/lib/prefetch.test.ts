import { describe, expect, it } from "vitest";
import {
  canSpeculate,
  likelyDestination,
  PrefetchBudget,
  safePrefetchPath,
} from "./prefetch";

describe("bounded route speculation without private data caching", () => {
  it("rejects URLs, query tokens, documents and unrecognized destinations", () => {
    for (const path of [
      "https://evil.test",
      "/requests?token=secret",
      "/connections/unknown",
      "/documents/private",
      "/integrations/slack-callback",
    ])
      expect(safePrefetchPath(path)).toBe(false);
    expect(safePrefetchPath("/connections/telegram")).toBe(true);
  });
  it("limits work and partitions metadata by user, workspace and role", () => {
    const budget = new PrefetchBudget();
    expect(budget.claim("user:org:admin", "/tasks", 0)).toBe(true);
    expect(budget.claim("user:org:admin", "/tasks", 1)).toBe(false);
    expect(budget.claim("other:org:employee", "/tasks", 2)).toBe(true);
    expect(budget.claim("other:other:employee", "/tasks", 3)).toBe(true);
    expect(budget.claim("", "/tasks", 4)).toBe(false);
    for (const path of [
      "/dashboard",
      "/tasks",
      "/requests",
      "/workflows",
      "/connections",
      "/analytics",
      "/team",
      "/settings",
    ])
      budget.claim("same", path, 10);
    expect(budget.claim("same", "/connections/ai", 11)).toBe(false);
    expect(budget.claim("same", "/connections/ai", 60_011)).toBe(true);
  });
  it("avoids slow or metered speculation and prioritizes the role journey", () => {
    expect(canSpeculate({ saveData: true })).toBe(false);
    expect(canSpeculate({ effectiveType: "3g" })).toBe(false);
    expect(canSpeculate({}, false)).toBe(false);
    expect(likelyDestination("/dashboard", "manager")).toBe("/tasks");
    expect(likelyDestination("/dashboard", "employee")).toBe("/requests");
    expect(likelyDestination("/dashboard", "admin")).toBe("/workflows");
  });
});
