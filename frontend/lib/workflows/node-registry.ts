import {AI_NODES,isAiNode} from "@/lib/ai";

import { defaultNodeData } from "@/lib/workflows/definition";

import type { NodeType, WorkflowNodeData } from "@/lib/workflows/types";

import { SOURCE_HANDLES } from "@/lib/workflows/types";

import { Play, ShieldCheck, GitBranch, RotateCcw, Flag, Bell, UserRoundCheck, Clock3, MessageSquare, type LucideIcon } from "lucide-react";

import type { FormField } from "@/lib/workflows/types";



export const NODE_REGISTRY_VERSION = 4;



export type NodeCategoryId = "ai" | "flow" | "people" | "communication" | "timing" | "telegram" | "email" | "documents" | "slack" | "sms";



export const NODE_CATEGORY_ORDER: NodeCategoryId[] = ["ai", "flow", "people", "communication", "email", "documents", "timing", "telegram", "slack", "sms"];



export const NODE_CATEGORY_LABELS: Record<NodeCategoryId, string> = {

  ai:"AI assistance",

  email: "Email", documents: "Documents",

  sms: "SMS",

  slack: "Slack",

  telegram: "Telegram Bot",

  flow: "Flow control",

  people: "People & approvals",

  communication: "Communication",

  timing: "Timing",

};



export const NODES_BY_CATEGORY: Record<NodeCategoryId, NodeType[]> = {

  ai:Object.keys(AI_NODES) as import("@/lib/ai").AiNodeType[],

  email: ["email_trigger", "email_filter", "email_send", "email_reply"], documents: ["attachment_inspector", "document_validation"],

  sms: ["sms_notification", "sms_send_message"],

  slack: ["slack_trigger", "slack_filter", "slack_send_message", "slack_notification"],

  telegram: ["telegram_trigger", "telegram_filter", "telegram_send_message", "telegram_notification"],

  flow: ["start", "condition", "end"],

  people: ["approval", "assignment", "revision"],

  communication: ["notification", "integration_action"],

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

  ai_email_analysis: {icon:ShieldCheck,title:AI_NODES.ai_email_analysis.title,description:"Analyze approved information; advice never grants authority.",accent:"var(--node-condition)",inputLabel:"Authorized source",outcomes:{complete:"Ready for review",missing:"Missing information",uncertain:"Human review",unavailable:"Unavailable / budget"}},

  ai_document_analysis: {icon:ShieldCheck,title:AI_NODES.ai_document_analysis.title,description:"Analyze approved information; advice never grants authority.",accent:"var(--node-condition)",inputLabel:"Authorized source",outcomes:{complete:"Ready for review",missing:"Missing information",uncertain:"Human review",unavailable:"Unavailable / budget"}},

  ai_slack_analysis: {icon:ShieldCheck,title:AI_NODES.ai_slack_analysis.title,description:"Analyze approved information; advice never grants authority.",accent:"var(--node-condition)",inputLabel:"Authorized source",outcomes:{complete:"Ready for review",missing:"Missing information",uncertain:"Human review",unavailable:"Unavailable / budget"}},

  ai_summarization: {icon:ShieldCheck,title:AI_NODES.ai_summarization.title,description:"Analyze approved information; advice never grants authority.",accent:"var(--node-condition)",inputLabel:"Authorized source",outcomes:{complete:"Ready for review",missing:"Missing information",uncertain:"Human review",unavailable:"Unavailable / budget"}},

  ai_classification: {icon:ShieldCheck,title:AI_NODES.ai_classification.title,description:"Analyze approved information; advice never grants authority.",accent:"var(--node-condition)",inputLabel:"Authorized source",outcomes:{complete:"Ready for review",missing:"Missing information",uncertain:"Human review",unavailable:"Unavailable / budget"}},

  ai_field_extraction: {icon:ShieldCheck,title:AI_NODES.ai_field_extraction.title,description:"Analyze approved information; advice never grants authority.",accent:"var(--node-condition)",inputLabel:"Authorized source",outcomes:{complete:"Ready for review",missing:"Missing information",uncertain:"Human review",unavailable:"Unavailable / budget"}},

  ai_reply_draft: {icon:ShieldCheck,title:AI_NODES.ai_reply_draft.title,description:"Analyze approved information; advice never grants authority.",accent:"var(--node-condition)",inputLabel:"Authorized source",outcomes:{complete:"Ready for review",missing:"Missing information",uncertain:"Human review",unavailable:"Unavailable / budget"}},

  email_trigger: { icon: Play, title: "Email Trigger", description: "Read an authorized mailbox. A recognized sender confirms inside Orqestra before a request starts.", accent: "var(--node-start)", inputLabel: "", outcomes: {out: "Confirmed email"} },

  email_filter: { icon: GitBranch, title: "Email Filter", description: "Match sender, recipient, subject, text or attachments.", accent: "var(--node-condition)", inputLabel: "Email", outcomes: {matched: "Matches", unmatched: "Does not match"} },

  email_send: { icon: Bell, title: "Email Send", description: "Queue a plain text email to an explicitly approved recipient.", accent: "var(--node-notification)", inputLabel: "Request", outcomes: {out: "Queued"} },

  email_reply: { icon: Bell, title: "Email Reply", description: "Reply only to the authorized original sender.", accent: "var(--node-notification)", inputLabel: "Request", outcomes: {out: "Queued"} },

  attachment_inspector: { icon: ShieldCheck, title: "Attachment Inspector", description: "Require private, malware-scanned PDF or DOCX evidence.", accent: "var(--node-approval)", inputLabel: "Email", outcomes: {inspected: "Inspected", missing: "Missing", blocked: "Blocked"} },

  document_validation: { icon: ShieldCheck, title: "Document Validation", description: "Check required fields and dates using a pinned document template.", accent: "var(--node-condition)", inputLabel: "Evidence", outcomes: {complete: "Complete", missing: "Missing information", invalid: "Invalid information", unknown: "Unable to determine"} },

  telegram_trigger: { icon: Play, title: "Telegram Trigger", description: "Start a request from a confirmed linked member in an approved chat. Replaces Start.", accent: "var(--node-start)", inputLabel: "", outcomes: {out:"Message received"} },

  telegram_filter: { icon: GitBranch, title: "Telegram Filter", description: "Match a command, text or chat type. Messages cannot approve internal tasks.", accent: "var(--node-condition)", inputLabel: "Incoming event", outcomes: {matched:"Matches",unmatched:"Does not match"} },

  telegram_send_message: { icon: Bell, title: "Telegram Send Message", description: "Queue a plain text message for an approved chat.", accent: "var(--node-notification)", inputLabel: "Request", outcomes: {out:"Queued"} },

  telegram_notification: { icon: Bell, title: "Telegram Notification", description: "Notify an approved person or group after this step.", accent: "var(--node-notification)", inputLabel: "Request", outcomes: {out:"Queued"} },

  sms_notification: { icon: MessageSquare, title: "SMS Notification", description: "Send an approved short template to an authorized recipient. Organization volume limits apply.", accent: "var(--node-notification)", inputLabel: "Request", outcomes: {out:"Queued"} },

  sms_send_message: { icon: MessageSquare, title: "SMS Send Message", description: "Queue one approved SMS using organization credentials and exact workflow grants.", accent: "var(--node-notification)", inputLabel: "Request", outcomes: {out:"Queued"} },

  slack_trigger: { icon: Play, title: "Slack Trigger", description: "Start a request from a confirmed linked member in an approved channel. Replaces Start.", accent: "var(--node-start)", inputLabel: "", outcomes: {out:"Message received"} },

  slack_filter: { icon: GitBranch, title: "Slack Message Filter", description: "Match a command, text or event type. Messages cannot approve internal tasks.", accent: "var(--node-condition)", inputLabel: "Incoming event", outcomes: {matched:"Matches",unmatched:"Does not match"} },

  slack_send_message: { icon: Bell, title: "Slack Send Message", description: "Queue a plain text message for an approved channel.", accent: "var(--node-notification)", inputLabel: "Request", outcomes: {out:"Queued"} },

  slack_notification: { icon: Bell, title: "Slack Notification", description: "Notify an approved person or group after this step.", accent: "var(--node-notification)", inputLabel: "Request", outcomes: {out:"Queued"} },

  integration_action: { icon: Bell, title: "Connected action", description: "Use a connection approved for this workflow.", accent: "var(--node-notification)", inputLabel: "Request", outcomes: { out: "Next" } },

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

  if(isAiNode(type))return data.connection_id ? "Advisory only · human review required" : "Choose an approved AI connection";

  const roles = (data.assignee_roles ?? []).map((role) => role === "admin" ? "Administrator" : role.charAt(0).toUpperCase() + role.slice(1)).join(", ");

  switch (type) {

    case "email_trigger": return data.connection_id ? "Approved mailbox · sender confirmation required" : "Choose an approved mailbox";

    case "email_filter": return `${data.rules?.length ?? 0} email rules · ${data.match ?? "all"} must match`;

    case "email_send": case "email_reply": return data.destination || "Choose an approved recipient";

    case "attachment_inspector": return "Private storage · fail-closed malware scan";

    case "document_validation": return data.template_id ? `${data.document_fields?.length ?? 0} fields · immutable template` : "Choose a document template";

    case "telegram_trigger": return data.connection_id ? "Linked members · approved source chat" : "Choose a Telegram bot and source chat";

    case "telegram_filter": return data.match_value ? `${data.filter_field} ${data.match_mode}: ${data.match_value}` : "Choose a match";

    case "telegram_send_message": case "telegram_notification": return data.destination ? "Approved chat · durable delivery queue" : "Choose an approved destination";

    case "sms_notification": case "sms_send_message": return data.recipient_id ? "Approved template and recipient" : "Choose approved SMS resources";

    case "slack_trigger": return data.connection_id ? "Linked members · approved source channel" : "Choose a Slack bot and source channel";

    case "slack_filter": return data.match_value ? `${data.filter_field} ${data.match_mode}: ${data.match_value}` : "Choose a match";

    case "slack_send_message": case "slack_notification": return data.destination ? "Approved channel · durable delivery queue" : "Choose an approved destination";

    case "integration_action": return data.connection_id ? "Simulated notification · approved connection" : "Choose an approved connection";

    case "start": return "Request submitted";

    case "end": return data.result === "rejected" ? "Request rejected" : data.result === "completed" ? "Request completed" : "Choose a result";

    case "approval": return roles ? `Decision by ${roles}` : "Choose who can approve";

    case "assignment": return data.assignee_mode === "member" ? (data.assignee_user_id ? "Assigned to a selected member" : "Choose a member") : roles ? `Task for ${roles}` : "Choose who completes this task";

    case "revision": return `Up to ${data.max_returns ?? 0} revisions${data.return_to_node_id ? ", then return to review" : " · Choose a review step to return to"}`;

    case "notification": return data.channels?.length ? data.channels.map((channel) => channel === "email" ? "Email" : "In-app notification").join(" + ") : "Choose a delivery channel";

    case "delay": {

      if (data.resume_mode === "until") return data.resume_at ? `Until ${new Date(data.resume_at).toLocaleString()}` : "Choose when to resume";

      const seconds = data.duration_seconds;

      if (!seconds) return "Choose a waiting period";

      const [amount, unit] = seconds % 86400 === 0 ? [seconds / 86400, "day"] : seconds % 3600 === 0 ? [seconds / 3600, "hour"] : seconds % 60 === 0 ? [seconds / 60, "minute"] : [seconds, "second"];

      return `Wait ${amount} ${unit}${amount === 1 ? "" : "s"}`;

    }

    case "condition": {

      const rules = (data.rules ?? []) as import("@/lib/workflows/types").ConditionRule[];

      if (!rules.length) return "Add a rule using a form answer";

      const rule = rules[0];

      const label = fields.find((field) => field.id === rule.field_id)?.label ?? "Answer";

      return `${label} ${rule.operator.replaceAll("_", " ")}${rule.value == null ? "" : ` ${rule.value}`}${rules.length > 1 ? ` + ${rules.length - 1} more (${data.match === "any" ? "any" : "all"})` : ""}`;

    }

  }

}



const INTRODUCED_IN: Partial<Record<NodeType, number>> = {

  email_trigger: 3, email_filter: 3, email_send: 3, email_reply: 3, attachment_inspector: 3, document_validation: 3,

  sms_notification: 3, sms_send_message: 3,

  slack_trigger: 3, slack_filter: 3, slack_send_message: 3, slack_notification: 3,

  telegram_trigger: 3, telegram_filter: 3, telegram_send_message: 3, telegram_notification: 3,

  integration_action: 3,

  notification: 2,

  assignment: 2,

  delay: 2,

};



export function nodeRegistryVersionFor(type: NodeType): number {

  if(isAiNode(type))return 4;

  return INTRODUCED_IN[type] ?? 1;

}



export function isNodeTypeAllowed(type: NodeType, registryVersion = NODE_REGISTRY_VERSION): boolean {

  return [1,2,3,4].includes(registryVersion) && nodeRegistryVersionFor(type) <= registryVersion;

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
