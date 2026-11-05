import {workflowNodeTypes} from "@/components/workflows/workflow-node";
import { describe, expect, it } from "vitest";
import { defaultDataFor, nodeSummary, paletteNodeTypes, sourceHandlesFor } from "@/lib/workflows/node-registry";

describe("node presentation contracts", () => {
  it("registers a React Flow renderer for every authorable node",()=>{for(const type of paletteNodeTypes(4)){expect(workflowNodeTypes).toHaveProperty(type);}});
  it("isolates seven AI node types to V4 with explicit human-review branches",()=>{
    const ai=paletteNodeTypes(4).filter(type=>type.startsWith("ai_"));
    expect(ai).toHaveLength(7);
    for(const type of ai){expect(paletteNodeTypes(3)).not.toContain(type);expect(sourceHandlesFor(type)).toEqual(["complete","missing","uncertain","unavailable"]);expect(defaultDataFor(type).configuration?.data_categories).toEqual([]);}
  });
  it("keeps historical V1 nodes and handle identifiers while offering V2 additions", () => {
    expect(paletteNodeTypes(1).sort()).toEqual(["approval", "condition", "end", "revision", "start"]);
    expect(paletteNodeTypes(2)).toHaveLength(8);
    expect(paletteNodeTypes(2)).not.toContain("integration_action");
    expect(paletteNodeTypes(3)).toContain("integration_action");
    for (const type of ["telegram_trigger", "telegram_filter", "telegram_send_message", "telegram_notification"] as const) {
      expect(paletteNodeTypes(3)).toContain(type);
      expect(paletteNodeTypes(2)).not.toContain(type);
    }
    expect(sourceHandlesFor("telegram_filter")).toEqual(["matched", "unmatched"]);
    for (const type of ["slack_trigger", "slack_filter", "slack_send_message", "slack_notification"] as const) {
      expect(paletteNodeTypes(3)).toContain(type);
      expect(paletteNodeTypes(2)).not.toContain(type);
    }
    expect(sourceHandlesFor("slack_filter")).toEqual(["matched", "unmatched"]);
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
