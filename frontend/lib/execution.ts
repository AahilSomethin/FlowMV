import { z } from "zod";
import { api } from "@/lib/api";
import type { FormDefinition, FormField, WorkflowList } from "@/lib/workflows/types";

export type InstanceStatus =
  | "running"
  | "awaiting_approval"
  | "awaiting_revision"
  | "completed"
  | "rejected"
  | "failed";

export type FailureCode =
  | "no_eligible_approver"
  | "invalid_graph"
  | "unknown_operator"
  | "corrupt_state";

export type TaskType = "approval" | "revision";
export type TaskStatus = "open" | "completed";
export type TaskDecision = "approved" | "rejected";

export type WorkflowRef = {
  uuid: string;
  name: string;
  status?: string;
};

export type VersionRef = {
  uuid: string;
  version_number: number;
};

export type PersonRef = {
  name: string;
};

export type InstanceTask = {
  uuid: string;
  task_type: TaskType;
  status: TaskStatus;
  decision: TaskDecision | null;
  node_id: string;
  node_label: string | null;
  assigned_role: string | null;
  assigned_at: string;
  completed_at: string | null;
};

export type InstanceEvent = {
  uuid: string;
  event_type: string;
  node_id: string | null;
  node_label: string | null;
  payload: Record<string, unknown>;
  created_at: string;
  actor: PersonRef | null;
};

export type WorkflowInstance = {
  uuid: string;
  status: InstanceStatus;
  failure_code: FailureCode | null;
  retryable: boolean;
  current_node_id: string;
  current_node_label: string | null;
  started_at: string;
  completed_at: string | null;
  workflow: WorkflowRef | null;
  workflow_version: VersionRef | null;
  submitted_by: PersonRef | null;
  submitted_data?: Record<string, unknown>;
  form_definition?: FormDefinition;
  tasks?: InstanceTask[];
  events?: InstanceEvent[];
};

export type WorkflowTask = {
  uuid: string;
  task_type: TaskType;
  status: TaskStatus;
  decision: TaskDecision | null;
  node_id: string;
  node_label: string | null;
  assigned_role: string | null;
  assigned_at: string;
  completed_at: string | null;
  instance: {
    uuid: string;
    status: InstanceStatus;
    current_node_id: string;
    current_node_label: string | null;
    submitted_data: Record<string, unknown>;
    workflow: WorkflowRef | null;
    workflow_version: VersionRef | null;
    submitted_by: PersonRef | null;
    form_definition: FormDefinition | null;
  } | null;
};

export type Page<T> = {
  items: T[];
  current_page: number;
  last_page: number;
  total: number;
};

export type SubmissionValues = Record<string, string | number | boolean | null>;

const NUMBER_LIMIT = 1_000_000_000;

export function hasBlockingFileField(definition: FormDefinition | null | undefined): boolean {
  return (definition?.fields ?? []).some((field) => field.type === "file");
}

export function buildSubmissionSchema(definition: FormDefinition) {
  const shape: Record<string, z.ZodType<string | number | boolean | null>> = {};

  for (const field of definition.fields) {
    if (field.type === "file") {
      continue;
    }

    shape[field.id] = fieldSchema(field);
  }

  return z.object(shape);
}

export function defaultSubmissionValues(definition: FormDefinition): SubmissionValues {
  const values: SubmissionValues = {};

  for (const field of definition.fields) {
    if (field.type === "file") {
      continue;
    }

    if (field.type === "checkbox") {
      values[field.id] = field.default_value === true;
      continue;
    }

    values[field.id] = field.default_value ?? null;
  }

  return values;
}

export function toSubmissionData(definition: FormDefinition, values: SubmissionValues): SubmissionValues {
  const data: SubmissionValues = {};

  for (const field of definition.fields) {
    if (field.type === "file") {
      continue;
    }

    const value = values[field.id];

    if (field.type === "checkbox") {
      data[field.id] = value === true;
      continue;
    }

    data[field.id] = value === "" ? null : (value ?? null);
  }

  return data;
}

export function stableJson(value: unknown): string {
  if (Array.isArray(value)) {
    return `[${value.map((item) => stableJson(item)).join(",")}]`;
  }

  if (value && typeof value === "object") {
    return `{${Object.keys(value)
      .sort()
      .map((key) => `${JSON.stringify(key)}:${stableJson((value as Record<string, unknown>)[key])}`)
      .join(",")}}`;
  }

  return JSON.stringify(value ?? null);
}

export class IdempotencyKeys {
  private readonly keys = new Map<string, string>();

  for(fingerprint: string): string {
    const existing = this.keys.get(fingerprint);

    if (existing) {
      return existing;
    }

    const key = crypto.randomUUID();
    this.keys.set(fingerprint, key);

    return key;
  }
}

export function instanceStatusLabel(status: InstanceStatus): string {
  switch (status) {
    case "running":
      return "In progress";
    case "awaiting_approval":
      return "Awaiting approval";
    case "awaiting_revision":
      return "Needs revision";
    case "completed":
      return "Completed";
    case "rejected":
      return "Rejected";
    case "failed":
      return "Failed";
  }
}

export function instanceStatusTone(status: InstanceStatus): "neutral" | "attention" | "danger" {
  if (status === "rejected" || status === "failed") {
    return "danger";
  }

  if (status === "awaiting_approval" || status === "awaiting_revision" || status === "running") {
    return "attention";
  }

  return "neutral";
}

export function failureLabel(code: FailureCode | null): string {
  switch (code) {
    case "no_eligible_approver":
      return "Nobody is eligible to approve this request.";
    case "invalid_graph":
      return "The published workflow could not continue.";
    case "unknown_operator":
      return "A condition could not be evaluated.";
    case "corrupt_state":
      return "The request is no longer consistent.";
    default:
      return "This request failed.";
  }
}

export function eventLabel(eventType: string): string {
  switch (eventType) {
    case "instance_started":
      return "Request submitted";
    case "condition_evaluated":
      return "Condition checked";
    case "task_created":
      return "Task opened";
    case "task_approved":
      return "Approved";
    case "task_rejected":
      return "Rejected";
    case "revision_requested":
      return "Revision requested";
    case "revision_submitted":
      return "Revision submitted";
    case "revision_exhausted":
      return "No revisions remaining";
    case "instance_completed":
      return "Request completed";
    case "instance_rejected":
      return "Request rejected";
    case "instance_failed":
      return "Request failed";
    default:
      return "Update recorded";
  }
}

export function formatWhen(value: string | null | undefined): string {
  if (!value) {
    return "Not recorded";
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(date);
}

export function displayValue(value: unknown): string {
  if (value === null || value === undefined || value === "") {
    return "Not provided";
  }

  if (typeof value === "boolean") {
    return value ? "Yes" : "No";
  }

  if (typeof value === "number") {
    return String(value);
  }

  return String(value);
}

export function listInstances(
  organizationId: string,
  params: { page: number; status?: string; search?: string },
): Promise<Page<WorkflowInstance>> {
  return api<Page<WorkflowInstance>>(`/api/v1/organizations/${organizationId}/instances?${query(params)}`);
}

export function getInstance(organizationId: string, instanceId: string): Promise<WorkflowInstance> {
  return api<WorkflowInstance>(`/api/v1/organizations/${organizationId}/instances/${instanceId}`);
}

export function startInstance(
  organizationId: string,
  workflowId: string,
  data: SubmissionValues,
  idempotencyKey: string,
): Promise<WorkflowInstance> {
  const body = { data };

  return api<WorkflowInstance>(`/api/v1/organizations/${organizationId}/workflows/${workflowId}/instances`, {
    method: "POST",
    body: stableJson(body),
    idempotencyKey,
  });
}

export function retryInstance(
  organizationId: string,
  instanceId: string,
  idempotencyKey: string,
): Promise<WorkflowInstance> {
  return api<WorkflowInstance>(`/api/v1/organizations/${organizationId}/instances/${instanceId}/retry`, {
    method: "POST",
    body: stableJson({}),
    idempotencyKey,
  });
}

export function listTasks(
  organizationId: string,
  params: { page: number; status: TaskStatus; search?: string },
): Promise<Page<WorkflowTask>> {
  return api<Page<WorkflowTask>>(`/api/v1/organizations/${organizationId}/tasks?${query(params)}`);
}

export function getTask(organizationId: string, taskId: string): Promise<WorkflowTask> {
  return api<WorkflowTask>(`/api/v1/organizations/${organizationId}/tasks/${taskId}`);
}

export function decideTask(
  organizationId: string,
  taskId: string,
  decision: TaskDecision,
  idempotencyKey: string,
): Promise<WorkflowInstance> {
  const body = { decision };

  return api<WorkflowInstance>(`/api/v1/organizations/${organizationId}/tasks/${taskId}/decisions`, {
    method: "POST",
    body: stableJson(body),
    idempotencyKey,
  });
}

export function reviseTask(
  organizationId: string,
  taskId: string,
  data: SubmissionValues,
  idempotencyKey: string,
): Promise<WorkflowInstance> {
  return api<WorkflowInstance>(`/api/v1/organizations/${organizationId}/tasks/${taskId}/revisions`, {
    method: "POST",
    body: stableJson({ data }),
    idempotencyKey,
  });
}

export function listPublishedWorkflows(organizationId: string, search: string, page: number): Promise<WorkflowList> {
  return api<WorkflowList>(
    `/api/v1/organizations/${organizationId}/workflows?${query({ page, status: "published", search: search || undefined })}`,
  );
}

function query(params: Record<string, string | number | undefined>): string {
  const search = new URLSearchParams();

  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== "") {
      search.set(key, String(value));
    }
  }

  return search.toString();
}

function fieldSchema(field: FormField): z.ZodType<string | number | boolean | null> {
  if (field.type === "checkbox") {
    return z.boolean().superRefine((value, context) => {
      if (field.required && value !== true) {
        context.addIssue({ code: z.ZodIssueCode.custom, message: "This field must be checked." });
      }
    });
  }

  if (field.type === "number") {
    return z.custom<number | null>().superRefine((value, context) => {
      if (value === null || value === undefined) {
        if (field.required) {
          context.addIssue({ code: z.ZodIssueCode.custom, message: "This field is required." });
        }

        return;
      }

      if (typeof value !== "number" || !Number.isFinite(value) || Math.abs(value) > NUMBER_LIMIT) {
        context.addIssue({ code: z.ZodIssueCode.custom, message: "Enter a number." });

        return;
      }

      const min = numberConstraint(field.validation.min);
      const max = numberConstraint(field.validation.max);

      if (min !== null && value < min) {
        context.addIssue({ code: z.ZodIssueCode.custom, message: "Enter a number that is at least the minimum." });
      }

      if (max !== null && value > max) {
        context.addIssue({ code: z.ZodIssueCode.custom, message: "Enter a number that is at most the maximum." });
      }
    });
  }

  if (field.type === "date") {
    return z.union([z.string(), z.null()]).superRefine((value, context) => {
      const normalized = value === "" ? null : value;

      if (normalized === null) {
        if (field.required) {
          context.addIssue({ code: z.ZodIssueCode.custom, message: "This field is required." });
        }

        return;
      }

      if (!isCalendarDate(normalized)) {
        context.addIssue({ code: z.ZodIssueCode.custom, message: "Enter a date as YYYY-MM-DD." });

        return;
      }

      const min = typeof field.validation.min === "string" && isCalendarDate(field.validation.min) ? field.validation.min : null;
      const max = typeof field.validation.max === "string" && isCalendarDate(field.validation.max) ? field.validation.max : null;

      if (min && normalized < min) {
        context.addIssue({ code: z.ZodIssueCode.custom, message: "Enter a date on or after the minimum." });
      }

      if (max && normalized > max) {
        context.addIssue({ code: z.ZodIssueCode.custom, message: "Enter a date on or before the maximum." });
      }
    });
  }

  if (field.type === "select") {
    const options = field.options.map((option) => option.value);

    return z.union([z.string(), z.null()]).superRefine((value, context) => {
      const normalized = value === "" ? null : value;

      if (normalized === null) {
        if (field.required) {
          context.addIssue({ code: z.ZodIssueCode.custom, message: "This field is required." });
        }

        return;
      }

      if (!options.includes(normalized)) {
        context.addIssue({ code: z.ZodIssueCode.custom, message: "Choose one of the available options." });
      }
    });
  }

  const max = integerConstraint(field.validation.max_length) ?? (field.type === "textarea" ? 5000 : 500);
  const min = integerConstraint(field.validation.min_length);

  return z.union([z.string(), z.null()]).superRefine((value, context) => {
    const normalized = value === "" ? null : value;

    if (normalized === null) {
      if (field.required) {
        context.addIssue({ code: z.ZodIssueCode.custom, message: "This field is required." });
      }

      return;
    }

    const length = Array.from(normalized).length;

    if (min !== null && length < min) {
      context.addIssue({ code: z.ZodIssueCode.custom, message: `Enter at least ${min} characters.` });
    }

    if (length > max) {
      context.addIssue({ code: z.ZodIssueCode.custom, message: `Enter at most ${max} characters.` });
    }
  });
}

function numberConstraint(value: unknown): number | null {
  return typeof value === "number" && Number.isFinite(value) ? value : null;
}

function integerConstraint(value: unknown): number | null {
  return typeof value === "number" && Number.isInteger(value) ? value : null;
}

function isCalendarDate(value: string): boolean {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

  if (!match) {
    return false;
  }

  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const date = new Date(Date.UTC(year, month - 1, day));

  return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;
}
