import type { ReactNode } from "react";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { AnalyticsScreen } from "@/components/analytics/analytics-screen";
import { AuditScreen } from "@/components/analytics/audit-screen";
import { DecisionRecord } from "@/components/analytics/decision-record";
import type { AnalyticsReport } from "@/lib/analytics";

const auth = vi.hoisted(() => ({ org: "org-a" }));
const api = vi.hoisted(() => vi.fn());

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
  }),
}));

vi.mock("@/lib/api", async () => {
  const actual = await vi.importActual<typeof import("@/lib/api")>("@/lib/api");

  return { ...actual, api };
});

vi.mock("recharts", () => ({
  ResponsiveContainer: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
  LineChart: () => <div data-testid="line-chart" />,
  PieChart: () => <div data-testid="pie-chart" />,
  CartesianGrid: () => null,
  XAxis: () => null,
  YAxis: () => null,
  Tooltip: () => null,
  Line: () => null,
  Pie: () => null,
  Cell: () => null,
}));

function report(name: string, total = 6): AnalyticsReport {
  return {
    timezone: "UTC",
    scope: "visible_requests",
    scope_label: "Limited to requests you can view",
    from: "2026-10-01",
    to: "2026-10-31",
    minimum_sample: 5,
    filters: { workflow: null, status: null },
    cohort: { total },
    status_distribution: [
      { status: "completed", count: total, share: total ? 1 : null },
      { status: "rejected", count: 0, share: total ? 0 : null },
      { status: "failed", count: 0, share: total ? 0 : null },
      { status: "running", count: 0, share: total ? 0 : null },
      { status: "awaiting_approval", count: 0, share: total ? 0 : null },
      { status: "awaiting_revision", count: 0, share: total ? 0 : null },
    ],
    volume: [{ date: "2026-10-01", count: total }],
    completion: { completed: 4, rejected: 0, denominator: 4, rate: null, sufficient: false },
    rejection: { rejected: 0, denominator: 4, rate: null, sufficient: false },
    completion_duration: { sample_size: 4, average_seconds: null, median_seconds: null, sufficient: false },
    approval_wait: { sample_size: 4, average_seconds: null, median_seconds: null, sufficient: false, open_count: 1, oldest_open_assigned_at: null },
    workflows: total
      ? [
          {
            uuid: "workflow-1",
            name,
            started: total,
            completed: 4,
            rejected: 0,
            in_progress: 1,
            failed: 0,
            terminal_completed: 4,
            terminal_denominator: 4,
            completion_rate: null,
            completion_sufficient: false,
            median_completion_seconds: null,
          },
        ]
      : [],
    revisions: { cohort_size: total, instances_revised: 0, submissions: 0, rate: null, sufficient: false, mean_among_revised: null },
    task_decisions: { approved: 4, rejected: 0 },
    bottlenecks: [],
    truncated: { workflows: false, bottlenecks: false },
  };
}

describe("analytics screens", () => {
  beforeEach(() => {
    auth.org = "org-a";
    api.mockReset();
    api.mockImplementation((url: string) => {
      if (String(url).includes("/workflows")) {
        return Promise.resolve({ items: [], current_page: 1, last_page: 1, total: 0 });
      }

      if (String(url).includes("/members")) {
        return Promise.resolve([]);
      }

      if (String(url).includes("/audit")) {
        return Promise.resolve({
          timezone: "UTC",
          scope: "visible_requests",
          scope_label: "Limited to requests you can view",
          items: String(url).includes("org-b")
            ? []
            : [
                {
                  uuid: "event-1",
                  event_type: "task_approved",
                  created_at: "2026-10-05T00:00:00Z",
                  node_id: "approval",
                  node_label: "Manager approval",
                  instance_uuid: "instance-1",
                  workflow_uuid: "workflow-1",
                  workflow_name: "Alpha",
                  actor_name: "Mina",
                  payload: { decision: "approved" },
                },
              ],
          current_page: 1,
          last_page: 1,
          total: String(url).includes("org-b") ? 0 : 1,
        });
      }

      return Promise.resolve(report(String(url).includes("org-b") ? "Beta" : "Alpha", String(url).includes("org-b") ? 0 : 6));
    });
  });

  it("shows the limited scope, an insufficient sample, and a chart", async () => {
    render(<AnalyticsScreen />);

    expect(screen.getByText("Loading analytics")).toBeInTheDocument();
    expect((await screen.findAllByText("Limited to requests you can view")).length).toBeGreaterThan(0);
    expect(screen.getAllByText("Not enough completed requests").length).toBeGreaterThan(0);
    expect(screen.getByTestId("volume-chart")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Alpha" })).toHaveAttribute("href", "/requests?workflow=workflow-1");
  });

  it("shows an empty state without a chart when nothing started", async () => {
    api.mockImplementation((url: string) => {
      if (String(url).includes("/workflows")) {
        return Promise.resolve({ items: [], current_page: 1, last_page: 1, total: 0 });
      }

      return Promise.resolve(report("Alpha", 0));
    });

    render(<AnalyticsScreen />);

    expect(await screen.findByText("No requests in this range")).toBeInTheDocument();
    expect(screen.queryByTestId("volume-chart")).not.toBeInTheDocument();
  });

  it("debounces a date preset into the analytics request", async () => {
    const user = userEvent.setup();
    render(<AnalyticsScreen />);
    await screen.findAllByText("Limited to requests you can view");
    api.mockClear();

    await user.click(screen.getByRole("button", { name: "7 days" }));

    await waitFor(() => {
      expect(api).toHaveBeenCalledWith(expect.stringContaining("from="));
    });
  });

  it("drops the previous workspace when the organization changes", async () => {
    const view = render(<AnalyticsScreen />);
    expect(await screen.findByText("Alpha")).toBeInTheDocument();

    auth.org = "org-b";
    view.rerender(<AnalyticsScreen />);

    expect(await screen.findByText("No requests in this range")).toBeInTheDocument();
    expect(screen.queryByText("Alpha")).not.toBeInTheDocument();
    expect(api).toHaveBeenCalledWith(expect.stringContaining("/organizations/org-b/analytics"));
  });

  it("lists audit events and clears them when the organization changes", async () => {
    const view = render(<AuditScreen />);

    expect(screen.getByText("Loading audit")).toBeInTheDocument();
    expect(await screen.findByRole("link", { name: "Alpha" })).toHaveAttribute("href", "/requests/instance-1");
    expect(screen.getByText("approved")).toBeInTheDocument();

    auth.org = "org-b";
    view.rerender(<AuditScreen />);

    expect(await screen.findByText("No audit events")).toBeInTheDocument();
    expect(screen.queryByText("approved")).not.toBeInTheDocument();
  });

  it("distinguishes a missing decision snapshot from the current submission", () => {
    render(
      <DecisionRecord
        task={{ task_type: "approval", status: "completed", decision: "approved", node_label: "Manager approval", decision_snapshot: null }}
        definition={{ fields: [] }}
        current={{ note: "later" }}
      />,
    );

    expect(screen.getByText(/No decision-time snapshot was recorded/)).toBeInTheDocument();
  });
});
