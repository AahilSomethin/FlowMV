import type { ReactNode } from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { RequestListScreen } from "@/components/execution/request-list-screen";
import { RequestCreateScreen } from "@/components/execution/request-create-screen";
import { TaskInboxScreen } from "@/components/execution/task-inbox-screen";
import { DynamicForm } from "@/components/execution/dynamic-form";
import { TaskDetail } from "@/components/execution/task-detail";
import { ApiError } from "@/lib/api";
import type { WorkflowTask } from "@/lib/execution";

const auth = vi.hoisted(() => ({ org: "org-a" }));
const api = vi.hoisted(() => vi.fn());

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  usePathname: () => "/requests",
  useParams: () => ({ instanceId: "instance-1", taskId: "task-1" }),
  useSearchParams: () => new URLSearchParams(),
}));

vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

vi.mock("@/lib/auth", () => ({
  useAuth: () => ({
    user: {
      id: 1,
      name: "Ada",
      email: "ada@example.com",
      email_verified_at: null,
      created_at: null,
      current_organization: { uuid: auth.org, role: "manager", name: "Acme", slug: "acme", created_at: null },
      organizations: [],
    },
    refresh: vi.fn(),
  }),
}));

vi.mock("@/lib/api", async () => {
  const actual = await vi.importActual<typeof import("@/lib/api")>("@/lib/api");

  return { ...actual, api };
});

const task: WorkflowTask = {
  uuid: "task-1",
  task_type: "approval",
  status: "open",
  decision: null,
  node_id: "approval",
  node_label: "Manager approval",
  assigned_role: "manager",
  assigned_at: "2026-10-26T12:00:00Z",
  completed_at: null,
  instance: {
    uuid: "instance-1",
    status: "awaiting_approval",
    current_node_id: "approval",
    current_node_label: "Manager approval",
    submitted_data: { note: "Need laptops" },
    workflow: { uuid: "workflow-1", name: "Alpha purchase" },
    workflow_version: { uuid: "version-1", version_number: 3 },
    submitted_by: { name: "Grace" },
    form_definition: {
      fields: [
        {
          id: "note",
          type: "text",
          label: "Note",
          description: "",
          required: true,
          default_value: null,
          validation: {},
          options: [],
        },
      ],
    },
  },
};

describe("execution screens", () => {
  it("hides decisions when the backend denies action on a readable task", () => {
    render(<TaskDetail organizationId="org-a" task={{ ...task, can_decide: false }} onUpdated={vi.fn()} />);
    expect(screen.getByText("Need laptops")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Approve" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Reject" })).not.toBeInTheDocument();
  });

  it("lets an authorized administrator choose a member and submit keyed reassignment", async () => {
    api.mockImplementation((path: string) => Promise.resolve(path.endsWith('/members') ? [{ user_id: 2, name: 'QA Manager', role: 'manager' }] : {}));
    const updated = vi.fn();
    render(<TaskDetail organizationId="org-a" task={{ ...task, task_type: "assignment", can_complete: false, can_reassign: true }} onUpdated={updated} />);
    expect(screen.queryByRole("button", { name: "Mark complete" })).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Reassign task" }));
    await userEvent.click(await screen.findByRole("combobox", { name: "New assignee" }));
    await userEvent.click(screen.getByRole("option", { name: "QA Manager · manager" }));
    await userEvent.click(screen.getByRole("button", { name: "Confirm reassignment" }));
    expect(updated).toHaveBeenCalledOnce();
    expect(api).toHaveBeenLastCalledWith('/api/v1/organizations/org-a/tasks/task-1/reassign', expect.objectContaining({ method: 'POST', body: '{"assignee_user_id":2}', idempotencyKey: expect.any(String) }));
  });
  beforeEach(() => {
    auth.org = "org-a";
    api.mockReset();
  });

  it("keeps task search focused while fetching each query", async () => {
    api.mockResolvedValue({ items: [], total: 0, last_page: 1, current_page: 1 });
    render(<TaskInboxScreen />);
    const search = await screen.findByRole("textbox", { name: "Search tasks" });
    await userEvent.type(search, "purchase");
    expect(search).toHaveFocus();
    expect(search).toHaveValue("purchase");
    expect(await screen.findByText("No open tasks")).toBeInTheDocument();
    expect(api).toHaveBeenLastCalledWith(expect.stringContaining("search=purchase"));
  });

  it("discovers services beyond the first page and recovers from a form load error", async () => {
    const service = { uuid: "service", name: "Purchase equipment", description: "Request new equipment", status: "published" };
    api.mockImplementation((url: string) => {
      if (url.endsWith("/workflows/service")) return Promise.reject(new ApiError("Service is temporarily unavailable", 503));
      return Promise.resolve({ items: [service], total: 21, last_page: 2, current_page: url.includes("page=2") ? 2 : 1 });
    });
    render(<RequestCreateScreen />);
    await screen.findByRole("heading", { name: "Purchase equipment" });
    await userEvent.click(screen.getByRole("button", { name: "Next" }));
    await screen.findByRole("heading", { name: "Purchase equipment" });
    expect(api).toHaveBeenCalledWith(expect.stringContaining("page=2"));
    await userEvent.click(screen.getByRole("button", { name: "Start request" }));
    expect(await screen.findByRole("heading", { name: "Form unavailable" })).toBeInTheDocument();
    expect(screen.queryByText("Loading form")).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Choose another service" }));
    expect(await screen.findByRole("textbox", { name: "Search services" })).toBeInTheDocument();
  });

  it("validates answers before review and submits only after confirmation", async () => {
    const submit = vi.fn().mockResolvedValue(undefined);
    render(<DynamicForm definition={task.instance!.form_definition!} submitLabel="Submit request" confirmTitle="Submit this request?" onSubmit={submit} />);
    await userEvent.click(screen.getByRole("button", { name: "Submit request" }));
    expect(await screen.findByText("Check your answers")).toBeInTheDocument();
    expect(submit).not.toHaveBeenCalled();
    await userEvent.type(screen.getByRole("textbox", { name: /Note/ }), "Need laptops");
    await userEvent.click(screen.getByRole("button", { name: "Submit request" }));
    expect(await screen.findByRole("alertdialog")).toHaveTextContent("Need laptops");
    expect(submit).not.toHaveBeenCalled();
    await userEvent.click(screen.getByRole("button", { name: "Keep editing" }));
    expect(screen.getByRole("textbox", { name: /Note/ })).toHaveValue("Need laptops");
    await userEvent.click(screen.getByRole("button", { name: "Submit request" }));
    await userEvent.click(screen.getByRole("button", { name: "Confirm submission" }));
    expect(submit).toHaveBeenCalledWith({ note: "Need laptops" });
  });

  it("drops the previous workspace when the organization changes", async () => {
    api.mockImplementation((url: string) =>
      Promise.resolve({
        items: [
          {
            uuid: url.includes("org-b") ? "request-b" : "request-a",
            status: "awaiting_approval",
            failure_code: null,
            retryable: false,
            current_node_id: "step",
            current_node_label: url.includes("org-b") ? "Second step" : "First step",
            started_at: "2026-10-26T12:00:00Z",
            completed_at: null,
            workflow: { uuid: "workflow", name: url.includes("org-b") ? "Second organization" : "First organization" },
            workflow_version: { uuid: "version", version_number: 1 },
            submitted_by: { name: "Ada" },
          },
        ],
        current_page: 1,
        last_page: 1,
        total: 1,
      }),
    );

    const view = render(<RequestListScreen />);

    expect((await screen.findAllByText("First organization")).length).toBeGreaterThan(0);
    auth.org = "org-b";
    view.rerender(<RequestListScreen />);

    expect((await screen.findAllByText("Second organization")).length).toBeGreaterThan(0);
    expect(screen.queryByText("First organization")).not.toBeInTheDocument();
    expect(api).toHaveBeenCalledWith(expect.stringContaining("/organizations/org-b/instances"));
  });

  it("confirms the request and action, then reuses the idempotency key after a timeout", async () => {
    const user = userEvent.setup();
    api
      .mockRejectedValueOnce(new ApiError("Could not reach the FlowMV API.", 0))
      .mockResolvedValueOnce({ uuid: "instance-1", status: "completed" });

    render(<TaskDetail organizationId="org-a" task={task} onUpdated={vi.fn()} />);

    await user.click(screen.getByRole("button", { name: "Approve" }));
    expect(screen.getByRole("heading", { name: "Approve “Alpha purchase”?" })).toBeInTheDocument();
    expect(screen.getByText(/approve the request “Alpha purchase” at the Manager approval step/i)).toBeInTheDocument();
    await user.click(screen.getAllByRole("button", { name: "Approve" }).at(-1)!);
    expect(await screen.findByText(/reuse the same idempotency key/i)).toBeInTheDocument();

    await user.click(screen.getAllByRole("button", { name: "Approve" })[0]);
    await user.click(screen.getAllByRole("button", { name: "Approve" }).at(-1)!);

    expect(api).toHaveBeenCalledTimes(2);
    const first = api.mock.calls[0][1].idempotencyKey;
    const second = api.mock.calls[1][1].idempotencyKey;
    expect(first).toBe(second);
    expect(api.mock.calls[0][0]).toContain("/tasks/task-1/decisions");
  });
});
