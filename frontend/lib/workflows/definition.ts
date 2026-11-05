import {AI_NODES,isAiNode} from "@/lib/ai";
import type {
  ConditionOperator,
  FieldType,
  FormDefinition,
  GraphDefinition,
  NodeType,
  WorkflowEdge,
  WorkflowNode,
  WorkflowNodeData,
  WorkflowViolation,
} from "@/lib/workflows/types";
import { OPERATORS, SOURCE_HANDLES, VALUELESS_OPERATORS } from "@/lib/workflows/types";

const MAX_DELAY_SECONDS = 30 * 24 * 60 * 60;

export function violation(
  code: string,
  message: string,
  nodeId: string | null = null,
  edgeId: string | null = null,
  fieldId: string | null = null,
): WorkflowViolation {
  return { code, message, node_id: nodeId, edge_id: edgeId, field_id: fieldId };
}

export function emptyForm(): FormDefinition {
  return { fields: [] };
}

export function defaultNodeData(type: NodeType): WorkflowNodeData {
  const labels: Record<NodeType, string> = {
    ...Object.fromEntries(Object.entries(AI_NODES).map(([key,value])=>[key,value.title])) as Record<import("@/lib/ai").AiNodeType,string>,
    email_trigger: "Email Trigger", email_filter: "Email Filter", email_send: "Email Send", email_reply: "Email Reply", attachment_inspector: "Attachment Inspector", document_validation: "Document Validation",
    sms_notification: "SMS Notification", sms_send_message: "SMS Send Message",
    slack_trigger: "Slack Trigger", slack_filter: "Slack Message Filter", slack_send_message: "Slack Send Message", slack_notification: "Slack Notification",
    telegram_trigger: "Telegram Trigger", telegram_filter: "Telegram Filter", telegram_send_message: "Telegram Send Message", telegram_notification: "Telegram Notification",
    start: "Start",
    approval: "Approval",
    condition: "Condition",
    revision: "Revision",
    end: "End",
    notification: "Notification",
    assignment: "Assignment",
    delay: "Delay",
    integration_action: "Connected action",
  };
  const base: WorkflowNodeData = { label: labels[type], description: "" };
  if (isAiNode(type)) return {...base,connection_id:"",source_kind: type === "ai_email_analysis" ? "email" : type === "ai_document_analysis" ? "document" : type === "ai_slack_analysis" ? "slack" : "request",document_field:null,field_map:{},configuration:{input_fields:[],fields:[],categories:[],routes:[],data_categories:[]}};
  if (type === "email_trigger") return {...base, connection_id:"", input_mapping:{}, rules:[], match:"all"};
  if (type === "email_filter") return {...base, rules:[{field:"subject",operator:"contains",value:"application"}], match:"all"};
  if (type === "attachment_inspector") return {...base, require_attachments:true};
  if (type === "document_validation") return {...base, template_id:"", document_fields:[], field_map:{}, require_document:true};
  if (type === "email_send" || type === "email_reply") return {...base, connection_id:"", destination:"", ...(type === "email_send" ? {subject:"Request update"}:{}), text:"Request {{request.uuid}}: {{request.status}}\n{{request.link}}", allowed_field_ids:[]};
  if (type.startsWith("sms_")) return {...base,connection_id:"",sender_id:"",recipient_id:"",template_id:""};
  if (type === "telegram_trigger" || type === "slack_trigger") return {...base,connection_id:"",destination:"",input_mapping:{}};
  if (type === "telegram_filter" || type === "slack_filter") return {...base,filter_field:"command",match_mode:"equals",match_value:"/request"};
  if (type === "telegram_send_message" || type === "telegram_notification" || type === "slack_send_message" || type === "slack_notification") return {...base,connection_id:"",destination:"",text:"Request {{request.uuid}}: {{approval.decision}}",allowed_field_ids:[]};

  if (type === "integration_action") {
    return { ...base, connection_id: "", operation: "mock.notify", destination: "mock:inbox" };
  }

  if (type === "approval") {
    return { ...base, assignee_roles: ["manager"] };
  }

  if (type === "condition") {
    return { ...base, match: "all", rules: [] };
  }

  if (type === "revision") {
    return { ...base, max_returns: 3, return_to_node_id: null };
  }

  if (type === "end") {
    return { ...base, result: "completed" };
  }

  if (type === "notification") {
    return {
      ...base,
      channels: ["in_app"],
      recipient_roles: ["manager"],
      include_submitter: true,
      include_task_participants: false,
      allowed_field_ids: [],
      subject: "Workflow update",
      body: "A workflow step needs your attention.",
    };
  }

  if (type === "assignment") {
    return {
      ...base,
      assignee_mode: "role",
      assignee_roles: ["employee"],
      assignee_user_id: null,
      exclude_submitter: true,
      instructions: "",
    };
  }

  if (type === "delay") {
    return {
      ...base,
      resume_mode: "duration",
      duration_seconds: 3600,
      resume_at: null,
    };
  }

  return base;
}

export function createNode(type: NodeType, position: { x: number; y: number }, id = crypto.randomUUID()): WorkflowNode {
  return {
    id,
    type,
    position: { x: Math.round(position.x), y: Math.round(position.y) },
    data: defaultNodeData(type),
  };
}

/**
 * Publication checks mirrored for the editor. Laravel remains authoritative.
 * Revision nodes model a future submitter-correction loop and are not executed here.
 */
export function validateDefinition(graph: GraphDefinition, form: FormDefinition): WorkflowViolation[] {
  const violations: WorkflowViolation[] = [];
  const nodes = graph.nodes;
  const edges = graph.edges;
  const byId = new Map(nodes.map((node) => [node.id, node]));
  const fields = new Map(form.fields.map((field) => [field.id, field]));

  for (const node of nodes) {
    if (isAiNode(node.type) && (!node.data.connection_id || !node.data.configuration?.data_categories.length || (node.data.source_kind === "request" && !node.data.configuration?.input_fields.length))) violations.push(violation("ai_config", "Choose an approved AI connection, permitted data categories and source fields.", node.id));
    if (["email_trigger", "email_filter", "email_send", "email_reply", "attachment_inspector", "document_validation"].includes(node.type)) {
      if (["email_filter", "email_reply", "attachment_inspector", "document_validation"].includes(node.type) && !nodes.some(n=>n.type === "email_trigger")) violations.push(violation("email_trigger", "This step needs an Email Trigger.", node.id));
      if (["email_trigger", "email_send", "email_reply"].includes(node.type) && !node.data.connection_id) violations.push(violation("email_connection", "Choose an approved mailbox with a workflow permission.", node.id));
      if (node.type === "email_trigger" && form.fields.some(f=>f.required)) violations.push(violation("email_fields", "Keep request form fields optional; Document Validation checks required information so corrections can be requested.", node.id));
      if (node.type === "email_filter" && !node.data.rules?.length) violations.push(violation("email_rules", "Add a message rule.", node.id));
      if (["email_send", "email_reply"].includes(node.type) && (!node.data.destination || !node.data.text?.trim() || (node.type === "email_send" && !node.data.subject?.trim()))) violations.push(violation("email_action", "Choose an approved recipient and write the message.", node.id));
      if (node.type === "document_validation" && (!node.data.template_id || !node.data.document_fields?.length || node.data.document_fields.some(f=>f.type !== "supporting_document" && !fields.has(node.data.field_map?.[f.id] ?? "")))) violations.push(violation("document_mapping", "Choose a template and map every document field to a request answer.", node.id));
    }
    if (node.type === "approval" && node.data.telegram_approval && (!node.data.telegram_approval.connection_id || !/^[0-9]{1,20}$/.test(node.data.telegram_approval.destination))) violations.push(violation("telegram_approval", "Choose an approved bot and a confirmed reviewer private chat.", node.id));
    if (node.type.startsWith("telegram_")) {
      if (node.type === "telegram_filter") {
        if (!node.data.match_value || !nodes.some(n=>n.type === "telegram_trigger")) violations.push(violation("telegram_filter", "Choose a filter value and use a Telegram Trigger.", node.id));
      } else {
        if (!node.data.connection_id || !/^-?[0-9]{1,20}$/.test(node.data.destination ?? "")) violations.push(violation("telegram_connection", "Choose an approved Telegram bot and chat.", node.id));
        if (node.type === "telegram_trigger") {
          for (const field of form.fields) if (field.required && !node.data.input_mapping?.[field.id]) violations.push(violation("telegram_mapping", "Map every required request answer.", node.id, null, field.id));
        } else {
          if (!node.data.text?.trim()) violations.push(violation("telegram_message", "Write the Telegram message.", node.id));
          for (const match of (node.data.text ?? "").matchAll(/\{\{([^}]+)\}\}/g)) {
            const key=match[1];
            if (!["request.uuid","request.status","approval.decision"].includes(key) && !(key.startsWith("input.") && node.data.allowed_field_ids?.includes(key.slice(6)))) violations.push(violation("telegram_variable", "Use only the listed safe variables and permitted request answers.", node.id));
          }
        }
      }
    }
    if (node.type.startsWith("sms_") && (!node.data.connection_id || !node.data.sender_id || !node.data.recipient_id || !node.data.template_id)) violations.push(violation("sms_config", "Select the approved SMS connection, sender, recipient and template.", node.id));
    if (node.type.startsWith("slack_")) {
      if (node.type === "slack_filter") {
        if (!node.data.match_value || !nodes.some(n=>n.type === "slack_trigger")) violations.push(violation("slack_filter", "Choose a filter value and use a Slack Trigger.", node.id));
      } else {
        if (!node.data.connection_id || !/^[CG][A-Z0-9]{8,30}$/.test(node.data.destination ?? "")) violations.push(violation("slack_connection", "Choose an approved Slack bot and chat.", node.id));
        if (node.type === "slack_trigger") {
          for (const field of form.fields) if (field.required && !node.data.input_mapping?.[field.id]) violations.push(violation("slack_mapping", "Map every required request answer.", node.id, null, field.id));
        } else {
          if (!node.data.text?.trim()) violations.push(violation("slack_message", "Write the Slack message.", node.id));
          for (const match of (node.data.text ?? "").matchAll(/\{\{([^}]+)\}\}/g)) {
            const key=match[1];
            if (!["request.uuid","request.status","approval.decision"].includes(key) && !(key.startsWith("input.") && node.data.allowed_field_ids?.includes(key.slice(6)))) violations.push(violation("slack_variable", "Use only the listed safe variables and permitted request answers.", node.id));
          }
        }
      }
    }
    if (!node.data.label.trim()) {
      violations.push(violation("incomplete_config", "Give this node a label.", node.id));
    }

    if (node.type === "integration_action" && (!node.data.connection_id || node.data.operation !== "mock.notify" || !["mock:inbox", "mock:review"].includes(node.data.destination ?? ""))) {
      violations.push(violation("integration_config", "Choose an approved connection and destination.", node.id));
    }

    if (node.type === "approval" && (node.data.assignee_roles?.length ?? 0) === 0) {
      violations.push(violation("approval_assignee", "Choose at least one role that can approve.", node.id));
    }

    if (node.type === "end" && node.data.result !== "completed" && node.data.result !== "rejected") {
      violations.push(violation("end_result", "Choose whether this End node completes or rejects the workflow.", node.id));
    }

    if (node.type === "condition") {
      const rules = (node.data.rules ?? []) as import("@/lib/workflows/types").ConditionRule[];

      if (rules.length === 0) {
        violations.push(violation("incomplete_config", "Add at least one condition rule.", node.id));
      }

      for (const rule of rules) {
        const field = fields.get(rule.field_id);

        if (!field) {
          violations.push(violation("unknown_field", "This rule references a form field that does not exist.", node.id, null, rule.field_id));
          continue;
        }

        if (!OPERATORS[field.type].includes(rule.operator)) {
          violations.push(violation("operator_mismatch", "This operator does not apply to the selected field.", node.id, null, field.id));
          continue;
        }

        if (VALUELESS_OPERATORS.has(rule.operator)) {
          if (rule.value !== null && rule.value !== "") {
            violations.push(violation("operator_mismatch", "This operator does not take a comparison value.", node.id, null, field.id));
          }
        } else if (rule.value === null || rule.value === "") {
          violations.push(violation("incomplete_config", "Enter a comparison value for this rule.", node.id, null, field.id));
        } else if (field.type === "number" && typeof rule.value !== "number") {
          violations.push(violation("operator_mismatch", "Compare this field with a number.", node.id, null, field.id));
        } else if (field.type === "select" && !field.options.some((option) => option.value === rule.value)) {
          violations.push(violation("operator_mismatch", "Compare this field with one of its options.", node.id, null, field.id));
        }
      }
    }

    if (node.type === "revision" && (node.data.max_returns == null || node.data.max_returns < 1 || node.data.max_returns > 10)) {
      violations.push(violation("revision_bound", "Set the maximum number of revision attempts, from 1 to 10.", node.id));
    }

    if (node.type === "notification") {
      if ((node.data.channels?.length ?? 0) === 0) {
        violations.push(violation("notification_channels", "Choose at least one notification channel.", node.id));
      }

      const hasRecipients =
        (node.data.recipient_roles?.length ?? 0) > 0 ||
        node.data.include_submitter === true ||
        node.data.include_task_participants === true;

      if (!hasRecipients) {
        violations.push(violation("notification_recipients", "Choose at least one recipient role or participant.", node.id));
      }

      for (const fieldId of node.data.allowed_field_ids ?? []) {
        const field = fields.get(fieldId);

        if (!field) {
          violations.push(violation("unknown_field", "This notification references a form field that does not exist.", node.id, null, fieldId));
          continue;
        }

        if (field.type === "file") {
          violations.push(violation("notification_template", "File fields cannot be included in notification templates.", node.id, null, fieldId));
        }
      }

      if (!(node.data.subject ?? "").trim()) {
        violations.push(violation("incomplete_config", "Give this notification a subject.", node.id));
      }
    }

    if (node.type === "assignment") {
      const mode = node.data.assignee_mode ?? "role";

      if (mode === "role" && (node.data.assignee_roles?.length ?? 0) === 0) {
        violations.push(violation("assignment_assignee", "Choose at least one role that can complete this task.", node.id));
      }

      if (mode === "member" && (node.data.assignee_user_id ?? null) === null) {
        violations.push(violation("assignment_assignee", "Choose an organization member for this assignment.", node.id));
      }
    }

    if (node.type === "delay") {
      const mode = node.data.resume_mode ?? "duration";

      if (mode === "duration") {
        const seconds = node.data.duration_seconds;

        if (seconds == null || seconds < 1 || seconds > MAX_DELAY_SECONDS) {
          violations.push(violation("delay_config", "Set a delay duration between 1 second and 30 days.", node.id));
        }
      }

      if (mode === "until" && (node.data.resume_at ?? null) === null) {
        violations.push(violation("delay_config", "Set a resume time for this delay.", node.id));
      }
    }
  }

  for (const field of form.fields) {
    if (!field.label.trim()) {
      violations.push(violation("incomplete_config", "Give this field a label.", null, null, field.id));
    }

    if (field.type === "select" && field.options.length === 0) {
      violations.push(violation("incomplete_config", "Add at least one option to this select field.", null, null, field.id));
    }
  }

  const starts = nodes.filter((node) => node.type === "start" || node.type === "telegram_trigger" || node.type === "email_trigger" || node.type === "slack_trigger");
  const ends = nodes.filter((node) => node.type === "end");

  if (starts.length !== 1) {
    violations.push(violation("start_count", "A workflow needs exactly one Start node."));
  }

  if (ends.length < 1) {
    violations.push(violation("end_count", "A workflow needs at least one End node."));
  }

  const forward = new Map<string, string[]>();
  const handles = new Set<string>();

  for (const edge of edges) {
    const source = byId.get(edge.source);
    const target = byId.get(edge.target);

    if (!source || !target) {
      violations.push(violation("invalid_edge_endpoint", "A connection points at a missing node.", null, edge.id));
      continue;
    }

    if (!SOURCE_HANDLES[source.type].includes(edge.source_handle) || target.type === "start" || target.type === "telegram_trigger" || target.type === "email_trigger" || target.type === "slack_trigger") {
      violations.push(violation("invalid_handle", "This connection is not a supported outcome.", source.id, edge.id));
      continue;
    }

    const key = `${source.id}:${edge.source_handle}`;

    if (handles.has(key)) {
      violations.push(violation("invalid_handle", "Each outcome can have one connection.", source.id, edge.id));
      continue;
    }

    handles.add(key);

    if (edge.source_handle !== "return") {
      forward.set(source.id, [...(forward.get(source.id) ?? []), target.id]);
    }
  }

  for (const node of nodes) {
    if (node.type === "end") {
      continue;
    }

    for (const handle of SOURCE_HANDLES[node.type]) {
      if (!handles.has(`${node.id}:${handle}`)) {
        const code = node.type === "approval" ? "approval_branch" : node.type === "condition" ? "condition_branch" : "dangling_path";
        violations.push(violation(code, missingHandle(node.type, handle), node.id));
      }
    }
  }

  const color = new Map<string, number>();

  const visit = (id: string) => {
    const state = color.get(id) ?? 0;

    if (state === 1) {
      violations.push(violation("cycle", "Only a revision return may loop, and it must point back at an earlier Approval.", id));
      return;
    }

    if (state === 2) {
      return;
    }

    color.set(id, 1);

    for (const next of forward.get(id) ?? []) {
      visit(next);
    }

    color.set(id, 2);
  };

  for (const node of nodes) {
    visit(node.id);
  }

  const startId = starts[0]?.id;

  if (startId) {
    const seen = new Set<string>();
    const stack = [startId];

    while (stack.length > 0) {
      const id = stack.pop();

      if (!id || seen.has(id)) {
        continue;
      }

      seen.add(id);
      stack.push(...(forward.get(id) ?? []));
    }

    for (const node of nodes) {
      if (!seen.has(node.id)) {
        violations.push(violation("unreachable_node", "This node cannot be reached from Start.", node.id));
      }
    }
  }

  for (const node of nodes.filter((item) => item.type === "revision")) {
    validateRevision(node, edges, byId, forward, violations);
  }

  return dedupe(violations);
}

function validateRevision(
  node: WorkflowNode,
  edges: WorkflowEdge[],
  byId: Map<string, WorkflowNode>,
  forward: Map<string, string[]>,
  violations: WorkflowViolation[],
) {
  const incoming = edges.filter((edge) => edge.target === node.id && edge.source_handle !== "return");

  if (incoming.length === 0) {
    violations.push(violation("revision_structure", "A rejection from an Approval node must enter this Revision.", node.id));
  }

  for (const edge of incoming) {
    const source = byId.get(edge.source);

    const correction = (entry: WorkflowEdge): boolean => {
      const from = byId.get(entry.source);
      return (from?.type === "approval" && entry.source_handle === "rejected") || (from?.type === "document_validation" && ["missing", "invalid", "unknown"].includes(entry.source_handle)) || (!!from && isAiNode(from.type) && ["missing","uncertain"].includes(entry.source_handle));
    };
    if (!source || !(correction(edge) || (source.type === "email_reply" && edges.filter(e=>e.target === source.id).length > 0 && edges.filter(e=>e.target === source.id).every(correction)))) {
      violations.push(violation("revision_structure", "Enter Revision from an approval rejection or document correction path, optionally through one Email Reply.", node.id, edge.id));
    }
  }

  const returns = edges.filter((edge) => edge.source === node.id && edge.source_handle === "return");

  if (returns.length !== 1) {
    violations.push(violation("revision_structure", "A Revision node needs one return connection to an earlier Approval.", node.id));
    return;
  }

  const returnEdge = returns[0];
  const target = byId.get(returnEdge.target);

  if (!target || target.type === "start") {
    violations.push(violation("revision_target", "A revision cannot return to Start. Point it at an earlier Approval node.", node.id, returnEdge.id));
    return;
  }

  if ((target.type !== "approval" && target.type !== "document_validation" && !isAiNode(target.type))) {
    violations.push(violation("revision_target", "A revision returns to an Approval node that comes before it.", node.id, returnEdge.id));
    return;
  }

  if (node.data.return_to_node_id !== target.id) {
    violations.push(violation("revision_target", "The revision settings must name the same Approval node as the return connection.", node.id, returnEdge.id));
  }

  if (!reaches(target.id, node.id, forward)) {
    violations.push(violation("revision_target", "The return target must be an Approval node that can reach this Revision without using the return connection.", node.id, returnEdge.id));
  }

  const exhausted = edges.filter((edge) => edge.source === node.id && edge.source_handle === "exhausted");

  if (exhausted.length !== 1) {
    violations.push(violation("revision_structure", "A Revision node needs an exhausted connection for when the attempt limit is passed.", node.id));
    return;
  }

  for (const terminalId of terminals(exhausted[0].target, forward)) {
    const terminal = byId.get(terminalId);

    if (!terminal || terminal.type !== "end" || terminal.data.result !== "rejected") {
      violations.push(violation("exhausted_result", "The exhausted revision path must end at a rejected End node.", terminal?.id ?? node.id, exhausted[0].id));
    }
  }
}

function reaches(from: string, to: string, forward: Map<string, string[]>): boolean {
  const seen = new Set<string>();
  const stack = [from];

  while (stack.length > 0) {
    const id = stack.pop();

    if (!id || seen.has(id)) {
      continue;
    }

    if (id === to) {
      return true;
    }

    seen.add(id);
    stack.push(...(forward.get(id) ?? []));
  }

  return false;
}

function terminals(startId: string, forward: Map<string, string[]>): string[] {
  const result: string[] = [];
  const seen = new Set<string>();
  const stack = [startId];

  while (stack.length > 0) {
    const id = stack.pop();

    if (!id || seen.has(id)) {
      continue;
    }

    seen.add(id);
    const next = forward.get(id) ?? [];

    if (next.length === 0) {
      result.push(id);
      continue;
    }

    stack.push(...next);
  }

  return result;
}

function missingHandle(type: NodeType, handle: string): string {
  if (type === "approval") {
    return "Connect both the approved and rejected outcomes.";
  }

  if (type === "condition") {
    return "Connect both the true and false outcomes.";
  }

  if (type === "revision") {
    return handle === "return"
      ? "Connect the return outcome to an earlier Approval node."
      : "Connect the exhausted outcome to a rejected End node.";
  }

  if (type === "start") {
    return "Connect Start to the next step.";
  }

  if (type === "notification" || type === "assignment" || type === "delay") {
    return "Connect this step to what happens next.";
  }

  return "Connect every outcome before publishing.";
}

function dedupe(violations: WorkflowViolation[]): WorkflowViolation[] {
  const seen = new Set<string>();

  return violations.filter((item) => {
    const key = JSON.stringify(item);

    if (seen.has(key)) {
      return false;
    }

    seen.add(key);

    return true;
  }).slice(0, 50);
}

export function operatorsFor(type: FieldType): ConditionOperator[] {
  return OPERATORS[type];
}

export function fieldIdFromLabel(label: string, taken: string[]): string {
  const base = label
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "")
    .replace(/^[0-9]+/, "")
    .slice(0, 48);
  const seed = base || "field";
  let candidate = seed;
  let suffix = 2;

  while (taken.includes(candidate)) {
    candidate = `${seed}_${suffix}`;
    suffix += 1;
  }

  return candidate;
}

export function snapshotKey(graph: GraphDefinition, form: FormDefinition): string {
  return JSON.stringify({ graph, form });
}
