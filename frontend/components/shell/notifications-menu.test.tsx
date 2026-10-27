import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { NotificationsMenu } from "@/components/shell/notifications-menu";

const api = vi.hoisted(() => vi.fn());
vi.mock("@/lib/api", () => ({ api }));

describe("workspace notifications", () => {
  beforeEach(() => { api.mockReset(); });
  it("loads only on open, marks a notice read in its workspace, and drops old notices on workspace change", async () => {
    api.mockImplementation((path: string, init?: { method?: string }) => Promise.resolve(init?.method ? {} : {
      items: path.includes("org-a") ? [{ id: "notice-a", read_at: null, data: { subject: "Private A", body: "Workspace A update" } }] : [],
      current_page: 1, last_page: 1, total: path.includes("org-a") ? 1 : 0,
    }));
    const view = render(<NotificationsMenu key="org-a" organizationId="org-a" />);
    expect(api).not.toHaveBeenCalled();
    await userEvent.click(screen.getByRole("button", { name: "Workspace notifications" }));
    await screen.findByText("Private A");
    await userEvent.click(screen.getByRole("button", { name: "Mark as read" }));
    await screen.findByText("Read", { selector: "p" });
    expect(api).toHaveBeenLastCalledWith("/api/v1/organizations/org-a/notifications/notice-a/read", { method: "POST" });
    view.rerender(<NotificationsMenu key="org-b" organizationId="org-b" />);
    expect(screen.queryByText("Private A")).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Workspace notifications" }));
    await screen.findByText("No notifications yet.");
    expect(api).toHaveBeenLastCalledWith("/api/v1/organizations/org-b/notifications?status=all&page=1");
  });

  it("paginates and resets to page one when filtering read or unread notifications", async () => {
    api.mockImplementation((path: string) => Promise.resolve({
      items: [{ id: 'notice', read_at: path.includes('status=read') ? '2026-10-28T00:00:00Z' : null, data: { subject: path.includes('page=2') ? 'Older notice' : 'Latest notice' } }],
      current_page: path.includes('page=2') ? 2 : 1, last_page: 2, total: 51,
    }));
    render(<NotificationsMenu organizationId="org-a" />);
    await userEvent.click(screen.getByRole('button', { name: 'Workspace notifications' }));
    await screen.findByText('Latest notice');
    await userEvent.click(screen.getByRole('button', { name: 'Next' }));
    await screen.findByText('Older notice');
    await userEvent.click(screen.getByRole('button', { name: 'Unread' }));
    await screen.findByText('Latest notice');
    expect(api).toHaveBeenLastCalledWith('/api/v1/organizations/org-a/notifications?status=unread&page=1');
    await userEvent.click(screen.getByRole('button', { name: /^Read$/ }));
    await screen.findByText('Read', { selector: 'p' });
    expect(api).toHaveBeenLastCalledWith('/api/v1/organizations/org-a/notifications?status=read&page=1');
  });
});
