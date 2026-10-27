import type { ReactNode } from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { UnsavedChangesGuard } from "@/components/workflows/unsaved-changes-guard";
import { ValidationList } from "@/components/workflows/validation-list";
import { WorkflowListScreen } from "@/components/workflows/workflow-list-screen";
import { canLeaveCurrentPage } from "@/lib/navigation";

const auth = vi.hoisted(() => ({ org: "org-a" }));
const api = vi.hoisted(() => vi.fn());

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  usePathname: () => "/workflows",
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
      current_organization: { uuid: auth.org, role: "admin", name: "Acme", slug: "acme", created_at: null },
      organizations: [],
    },
    refresh: vi.fn(),
  }),
}));

vi.mock("@/lib/api", async () => {
  const actual = await vi.importActual<typeof import("@/lib/api")>("@/lib/api");

  return { ...actual, api };
});

describe("workflow screens", () => {
  beforeEach(() => {
    auth.org = "org-a";
    api.mockReset();
    api.mockImplementation((url: string) => {
      const name = url.includes("org-b") ? "Second organization" : "First organization";

      return Promise.resolve({
        items: [
          {
            uuid: name,
            name,
            description: null,
            status: "draft",
            has_unpublished_changes: true,
            current_version_number: null,
            draft_revision: 1,
            created_at: null,
            updated_at: null,
            can_update: true,
            can_publish: true,
            can_archive: true,
          },
        ],
        current_page: 1,
        last_page: 1,
        total: 1,
      });
    });
  });

  it("shows validation feedback", () => {
    render(
      <ValidationList
        violations={[
          {
            code: "end_count",
            message: "A workflow needs at least one End node.",
            node_id: null,
            edge_id: null,
            field_id: null,
          },
        ]}
      />,
    );

    expect(screen.getByRole("alert")).toHaveTextContent("at least one End node");
  });

  it("asks before leaving with unsaved changes", async () => {
    const confirm = vi.spyOn(window, "confirm").mockReturnValue(false);

    render(
      <>
        <UnsavedChangesGuard dirty />
        <a href="https://example.com/leave">Leave</a>
      </>,
    );

    await userEvent.click(screen.getByRole("link", { name: "Leave" }));

    expect(confirm).toHaveBeenCalled();
    confirm.mockRestore();
  });

  it("protects workspace switches and sign-out from losing a draft", () => {
    const confirm = vi.spyOn(window, "confirm").mockReturnValue(false);
    const view = render(<UnsavedChangesGuard dirty />);
    expect(canLeaveCurrentPage()).toBe(false);
    confirm.mockReturnValue(true);
    expect(canLeaveCurrentPage()).toBe(true);
    view.unmount();
    expect(canLeaveCurrentPage()).toBe(true);
    expect(confirm).toHaveBeenCalledTimes(2);
    confirm.mockRestore();
  });

  it("cancels supported browser history traversal when an unsaved draft is kept", () => {
    const navigation = new EventTarget();
    const previous = Object.getOwnPropertyDescriptor(window, "navigation");
    Object.defineProperty(window, "navigation", { configurable: true, value: navigation });
    const confirm = vi.spyOn(window, "confirm").mockReturnValue(false);
    const view = render(<UnsavedChangesGuard dirty />);
    const event = new Event("navigate", { cancelable: true });
    Object.defineProperty(event, "navigationType", { value: "traverse" });
    expect(navigation.dispatchEvent(event)).toBe(false);
    expect(confirm).toHaveBeenCalledOnce();
    view.unmount();
    confirm.mockRestore();
    if (previous) Object.defineProperty(window, "navigation", previous);
    else Reflect.deleteProperty(window, "navigation");
  });

  it("reloads workflows when the organization changes", async () => {
    const view = render(<WorkflowListScreen />);

    expect((await screen.findAllByText("First organization")).length).toBeGreaterThan(0);

    auth.org = "org-b";
    view.rerender(<WorkflowListScreen />);

    expect((await screen.findAllByText("Second organization")).length).toBeGreaterThan(0);
    expect(screen.queryByText("First organization")).not.toBeInTheDocument();
    expect(api).toHaveBeenCalledWith(expect.stringContaining("/organizations/org-b/workflows"));
  });
});
