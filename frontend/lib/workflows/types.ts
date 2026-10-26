export type WorkflowStatus = "draft" | "published" | "archived";

export type NodeType = "start" | "approval" | "condition" | "revision" | "end";

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
  label: string;
  description: string;
  assignee_roles?: AssigneeRole[];
  match?: "all" | "any";
  rules?: ConditionRule[];
  max_returns?: number | null;
  return_to_node_id?: string | null;
  result?: EndResult | null;
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
  start: ["out"],
  approval: ["approved", "rejected"],
  condition: ["true", "false"],
  revision: ["return", "exhausted"],
  end: [],
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

export const NODE_COPY: Record<NodeType, { title: string; description: string }> = {
  start: {
    title: "Start",
    description: "Where a submission enters the workflow.",
  },
  approval: {
    title: "Approval",
    description: "A role approves or rejects the request. Both outcomes must be connected.",
  },
  condition: {
    title: "Condition",
    description: "Branches on a form field. True and false each need a connection.",
  },
  revision: {
    title: "Revision",
    description: "Rejection returns the request to the submitter, then back to an earlier approval.",
  },
  end: {
    title: "End",
    description: "Ends the workflow as completed or rejected.",
  },
};
