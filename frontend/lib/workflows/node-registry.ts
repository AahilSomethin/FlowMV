import { defaultNodeData } from "@/lib/workflows/definition";
import type { NodeType, WorkflowNodeData } from "@/lib/workflows/types";
import { SOURCE_HANDLES } from "@/lib/workflows/types";
import { Play, ShieldCheck, GitBranch, RotateCcw, Flag, Bell, UserRoundCheck, Clock3, type LucideIcon } from "lucide-react";
import type { FormField } from "@/lib/workflows/types";

export const NODE_REGISTRY_VERSION = 2;

export type NodeCategoryId = "flow" | "people" | "communication" | "timing";

export const NODE_CATEGORY_ORDER: NodeCategoryId[] = ["flow", "people", "communication", "timing"];

export const NODE_CATEGORY_LABELS: Record<NodeCategoryId, string> = {
  flow: "Flow control",
  people: "People & approvals",
  communication: "Communication",
  timing: "Timing",
};

export const NODES_BY_CATEGORY: Record<NodeCategoryId, NodeType[]> = {
  flow: ["start", "condition", "end"],
  people: ["approval", "assignment", "revision"],
  communication: ["notification"],
  timing: ["delay"],
};

// Presentation only: handle identifiers and stored registry versions stay unchanged.
export const NODE_PRESENTATION: Record<NodeType, {
  icon: LucideIcon;
  title: string;
  description: string;
  accent: string;
  inputLabel: string;
  outcomes: Record<string, string>;
}> = {
  start: { icon: Play, title: "Start", description: "Begin when someone submits a request.", accent: "var(--node-start)", inputLabel: "", outcomes: { out: "Next" } },
  condition: { icon: GitBranch, title: "Condition", description: "Choose a path using the request’s answers.", accent: "var(--node-condition)", inputLabel: "Request", outcomes: { true: "Yes", false: "No" } },
  end: { icon: Flag, title: "End", description: "Complete or reject the request.", accent: "var(--node-end)", inputLabel: "Request", outcomes: {} },
  approval: { icon: ShieldCheck, title: "Approval", description: "Wait for an authorized person’s decision.", accent: "var(--node-approval)", inputLabel: "Request", outcomes: { approved: "Approved", rejected: "Rejected" } },
  assignment: { icon: UserRoundCheck, title: "Assignment", description: "Ask someone to complete a task.", accent: "var(--node-assignment)", inputLabel: "Request", outcomes: { out: "Completed" } },
  revision: { icon: RotateCcw, title: "Revision", description: "Ask the submitter to update their answers.", accent: "var(--node-revision)", inputLabel: "Needs revision", outcomes: { return: "Resubmitted", exhausted: "Limit reached" } },
  notification: { icon: Bell, title: "Notification", description: "Send an update to the people involved.", accent: "var(--node-notification)", inputLabel: "Request", outcomes: { out: "Next" } },
  delay: { icon: Clock3, title: "Delay", description: "Pause before continuing to the next step.", accent: "var(--node-delay)", inputLabel: "Request", outcomes: { out: "Continue" } },
};

export function nodeSummary(type: NodeType, data: WorkflowNodeData, fields: FormField[] = []): string {
  const roles = (data.assignee_roles ?? []).map((role) => role === "admin" ? "Administrator" : role.charAt(0).toUpperCase() + role.slice(1)).join(", ");
  switch (type) {
    case "start": return "Request submitted";
    case "end": return data.result === "rejected" ? "Request rejected" : data.result === "completed" ? "Request completed" : "Choose a result";
    case "approval": return roles ? `Decision by ${roles}` : "Choose who can approve";
    case "assignment": return data.assignee_mode === "member" ? (data.assignee_user_id ? "Assigned to a selected member" : "Choose a member") : roles ? `Task for ${roles}` : "Choose who completes this task";
    case "revision": return `Up to ${data.max_returns ?? 0} revisions${data.return_to_node_id ? ", then return to approval" : " · Choose an approval to return to"}`;
    case "notification": return data.channels?.length ? data.channels.map((channel) => channel === "email" ? "Email" : "In-app notification").join(" + ") : "Choose a delivery channel";
    case "delay": {
      if (data.resume_mode === "until") return data.resume_at ? `Until ${new Date(data.resume_at).toLocaleString()}` : "Choose when to resume";
      const seconds = data.duration_seconds;
      if (!seconds) return "Choose a waiting period";
      const [amount, unit] = seconds % 86400 === 0 ? [seconds / 86400, "day"] : seconds % 3600 === 0 ? [seconds / 3600, "hour"] : seconds % 60 === 0 ? [seconds / 60, "minute"] : [seconds, "second"];
      return `Wait ${amount} ${unit}${amount === 1 ? "" : "s"}`;
    }
    case "condition": {
      const rules = data.rules ?? [];
      if (!rules.length) return "Add a rule using a form answer";
      const rule = rules[0];
      const label = fields.find((field) => field.id === rule.field_id)?.label ?? "Answer";
      return `${label} ${rule.operator.replaceAll("_", " ")}${rule.value == null ? "" : ` ${rule.value}`}${rules.length > 1 ? ` + ${rules.length - 1} more (${data.match === "any" ? "any" : "all"})` : ""}`;
    }
  }
}

const INTRODUCED_IN: Partial<Record<NodeType, number>> = {
  notification: 2,
  assignment: 2,
  delay: 2,
};

export function nodeRegistryVersionFor(type: NodeType): number {
  return INTRODUCED_IN[type] ?? 1;
}

export function isNodeTypeAllowed(type: NodeType, registryVersion = NODE_REGISTRY_VERSION): boolean {
  return nodeRegistryVersionFor(type) <= registryVersion;
}

export function paletteNodeTypes(registryVersion = NODE_REGISTRY_VERSION): NodeType[] {
  return NODE_CATEGORY_ORDER.flatMap((category) =>
    NODES_BY_CATEGORY[category].filter((type) => isNodeTypeAllowed(type, registryVersion)),
  );
}

export function paletteCategories(registryVersion = NODE_REGISTRY_VERSION): Array<{
  id: NodeCategoryId;
  label: string;
  types: NodeType[];
}> {
  return NODE_CATEGORY_ORDER.map((id) => ({
    id,
    label: NODE_CATEGORY_LABELS[id],
    types: NODES_BY_CATEGORY[id].filter((type) => isNodeTypeAllowed(type, registryVersion)),
  })).filter((category) => category.types.length > 0);
}

export function sourceHandlesFor(type: NodeType): string[] {
  return SOURCE_HANDLES[type];
}

export function defaultDataFor(type: NodeType): WorkflowNodeData {
  return defaultNodeData(type);
}

export function nodeTitle(type: NodeType): string {
  return NODE_PRESENTATION[type].title;
}

export function nodeDescription(type: NodeType): string {
  return NODE_PRESENTATION[type].description;
}
