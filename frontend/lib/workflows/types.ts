export type WorkflowStatus = "draft" | "published" | "archived";

export type EmailRule = { field: "sender" | "recipient" | "subject" | "text" | "has_attachments"; operator: "equals" | "contains" | "starts_with"; value: string | boolean };
export type DocumentField = { id: string; label: string; type: "text" | "identifier" | "email" | "date" | "number" | "select" | "supporting_document"; required: boolean; options?: string[]; after_or_equal_to?: string; required_when?: {field_id: string; equals: string | boolean} };

export type NodeType =
  | import("@/lib/ai").AiNodeType
  | "email_trigger" | "email_filter" | "email_send" | "email_reply" | "attachment_inspector" | "document_validation"
  | "start"
  | "approval"
  | "condition"
  | "revision"
  | "end"
  | "notification"
  | "assignment"
  | "delay"
  | "integration_action"
  | "telegram_trigger"
  | "telegram_filter"
  | "telegram_send_message"
  | "telegram_notification"
  | "sms_notification" | "sms_send_message"
  | "slack_trigger" | "slack_filter" | "slack_send_message" | "slack_notification";

export type NotificationChannel = "in_app" | "email";

export type EndResult = "completed" | "rejected";

export type FieldType = "text" | "textarea" | "number" | "date" | "select" | "checkbox" | "file";

export type ConditionOperator =
  | "equals"
  | "not_equals"
  | "greater_than"
  | "greater_than_or_equal"
  | "less_than"
  | "less_than_or_equal"
  | "is_blank"
  | "is_not_blank"
  | "is_true"
  | "is_false";

export type AssigneeRole = "admin" | "manager" | "employee";

export type ConditionRule = {
  id: string;
  field_id: string;
  operator: ConditionOperator;
  value: string | number | null;
};

export type WorkflowNodeData = {
  source_kind?: "request"|"email"|"document"|"slack";
  document_field?: string|null;
  configuration?: import("@/lib/ai").AiConfiguration;
  require_attachments?: boolean;
  require_document?: boolean;
  sender_id?: string;
  recipient_id?: string;
  template_id?: string;
  document_fields?: DocumentField[];
  field_map?: Record<string, string>;
  connection_id?: string;
  input_mapping?: Record<string, string>;
  filter_field?: "command" | "text" | "chat_type" | "event_type";
  telegram_approval?: {connection_id:string;destination:string};
  match_mode?: "equals" | "contains" | "starts_with";
  match_value?: string;
  text?: string;
  operation?: string;
  destination?: string;
  label: string;
  description: string;
  assignee_roles?: AssigneeRole[];
  match?: "all" | "any";
  rules?: ConditionRule[] | EmailRule[];
  max_returns?: number | null;
  return_to_node_id?: string | null;
  result?: EndResult | null;
  channels?: NotificationChannel[];
  recipient_roles?: AssigneeRole[];
  include_submitter?: boolean;
  include_task_participants?: boolean;
  allowed_field_ids?: string[];
  subject?: string;
  body?: string;
  assignee_mode?: "role" | "member";
  assignee_user_id?: number | null;
  exclude_submitter?: boolean;
  instructions?: string;
  resume_mode?: "duration" | "until";
  duration_seconds?: number | null;
  resume_at?: string | null;
};

export type WorkflowNode = {
  id: string;
  type: NodeType;
  position: { x: number; y: number };
  data: WorkflowNodeData;
};

export type WorkflowEdge = {
  id: string;
  source: string;
  target: string;
  source_handle: string;
  target_handle: "in";
};

export type GraphDefinition = {
  nodes: WorkflowNode[];
  edges: WorkflowEdge[];
};

export type SelectOption = {
  value: string;
  label: string;
};

export type FormField = {
  id: string;
  type: FieldType;
  label: string;
  description: string;
  required: boolean;
  default_value: string | number | boolean | null;
  validation: Record<string, string | number | string[] | undefined>;
  options: SelectOption[];
};

export type FormDefinition = {
  fields: FormField[];
};

export type WorkflowViolation = {
  code: string;
  message: string;
  node_id: string | null;
  edge_id: string | null;
  field_id: string | null;
};

export type WorkflowSummary = {
  uuid: string;
  name: string;
  description: string | null;
  status: WorkflowStatus;
  has_unpublished_changes: boolean;
  current_version_number: number | null;
  draft_revision: number | null;
  created_at: string | null;
  updated_at: string | null;
  can_update: boolean;
  can_publish: boolean;
  can_archive: boolean;
};

export type WorkflowDetail = WorkflowSummary & {
  draft?: {
    revision: number;
    graph_definition: GraphDefinition;
    form_definition: FormDefinition;
  };
  published?: {
    uuid: string;
    version_number: number;
    graph_definition: GraphDefinition;
    form_definition: FormDefinition;
    published_at: string | null;
    published_by: { name: string } | null;
  };
};

export type WorkflowVersionSummary = {
  uuid: string;
  version_number: number;
  published_at: string | null;
  published_by: { name: string } | null;
  graph_definition?: GraphDefinition;
  form_definition?: FormDefinition;
};

export type WorkflowList = {
  items: WorkflowSummary[];
  current_page: number;
  last_page: number;
  total: number;
};

export const SOURCE_HANDLES: Record<NodeType, string[]> = {
  ai_email_analysis: ["complete","missing","uncertain","unavailable"],
  ai_document_analysis: ["complete","missing","uncertain","unavailable"],
  ai_slack_analysis: ["complete","missing","uncertain","unavailable"],
  ai_summarization: ["complete","missing","uncertain","unavailable"],
  ai_classification: ["complete","missing","uncertain","unavailable"],
  ai_field_extraction: ["complete","missing","uncertain","unavailable"],
  ai_reply_draft: ["complete","missing","uncertain","unavailable"],

  email_trigger: ["out"], email_filter: ["matched", "unmatched"], email_send: ["out"], email_reply: ["out"],
  attachment_inspector: ["inspected", "missing", "blocked"], document_validation: ["complete", "missing", "invalid", "unknown"],
  start: ["out"],
  approval: ["approved", "rejected"],
  condition: ["true", "false"],
  revision: ["return", "exhausted"],
  end: [],
  notification: ["out"],
  assignment: ["out"],
  delay: ["out"],
  integration_action: ["out"],
  sms_notification: ["out"], sms_send_message: ["out"],
  slack_trigger: ["out"], slack_filter: ["matched", "unmatched"], slack_send_message: ["out"], slack_notification: ["out"],
  telegram_trigger: ["out"],
  telegram_filter: ["matched", "unmatched"],
  telegram_send_message: ["out"],
  telegram_notification: ["out"],
};

export const OPERATORS: Record<FieldType, ConditionOperator[]> = {
  text: ["equals", "not_equals", "is_blank", "is_not_blank"],
  textarea: ["equals", "not_equals", "is_blank", "is_not_blank"],
  number: ["equals", "not_equals", "greater_than", "greater_than_or_equal", "less_than", "less_than_or_equal"],
  date: ["equals", "not_equals", "greater_than", "greater_than_or_equal", "less_than", "less_than_or_equal"],
  select: ["equals", "not_equals"],
  checkbox: ["is_true", "is_false"],
  file: ["is_blank", "is_not_blank"],
};

export const VALUELESS_OPERATORS = new Set<ConditionOperator>(["is_blank", "is_not_blank", "is_true", "is_false"]);
