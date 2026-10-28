import { describe, expect, it } from "vitest";
import { defaultDataFor, nodeSummary, paletteNodeTypes, sourceHandlesFor } from "@/lib/workflows/node-registry";

describe("node presentation contracts", () => {
  it("keeps historical V1 nodes and handle identifiers while offering V2 additions", () => {
    expect(paletteNodeTypes(1).sort()).toEqual(["approval", "condition", "end", "revision", "start"]);
    expect(paletteNodeTypes(2)).toHaveLength(8);
    expect(paletteNodeTypes(2)).not.toContain("integration_action");
    expect(paletteNodeTypes(3)).toContain("integration_action");
    expect(sourceHandlesFor("condition")).toEqual(["true", "false"]);
    expect(sourceHandlesFor("revision")).toEqual(["return", "exhausted"]);
  });

  it("summarizes member assignments without exposing numeric identifiers", () => {
    const summary = nodeSummary("assignment", { ...defaultDataFor("assignment"), assignee_mode: "member", assignee_user_id: 92834 });
    expect(summary).toBe("Assigned to a selected member");
    expect(summary).not.toContain("92834");
  });

  it("shows meaningful waiting periods without changing the stored duration", () => {
    const data = { ...defaultDataFor("delay"), duration_seconds: 86400 };
    expect(nodeSummary("delay", data)).toBe("Wait 1 day");
    expect(data.duration_seconds).toBe(86400);
  });
});
