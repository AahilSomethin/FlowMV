import { act, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { ReactNode } from "react";
import { IntentLink, IntentPrefetchProvider } from "./intent-link";
const prefetch = vi.hoisted(() => vi.fn());
vi.mock("next/navigation", () => ({ useRouter: () => ({ prefetch }) }));
vi.mock("next/link", () => ({
  default: ({
    children,
    prefetch: ignored,
    ...props
  }: {
    children: ReactNode;
    prefetch?: boolean;
  }) => (
    <a {...props} data-prefetch={String(ignored)}>
      {children}
    </a>
  ),
}));
afterEach(() => {
  vi.useRealTimers();
  prefetch.mockClear();
});
const view = (scope = "1:organization:manager") => (
  <IntentPrefetchProvider scope={scope} pathname="/dashboard" role="manager">
    <IntentLink href="/requests">Requests</IntentLink>
    <IntentLink href="/connections/ai">AI</IntentLink>
  </IntentPrefetchProvider>
);
describe("intent navigation", () => {
  it("prepares only one likely route and cancels a fleeting hover", () => {
    vi.useFakeTimers();
    render(view("priority:org:manager"));
    const link = screen.getByRole("link", { name: "Requests" });
    expect(link).toHaveAttribute("data-prefetch", "false");
    fireEvent.mouseEnter(link);
    fireEvent.mouseLeave(link);
    act(() => vi.advanceTimersByTime(700));
    expect(prefetch.mock.calls.map(([path]) => path)).toEqual(["/tasks"]);
  });
  it("supports keyboard intent but cancels pending work on workspace/role transition", () => {
    vi.useFakeTimers();
    const { rerender } = render(view("old:org:manager"));
    fireEvent.mouseEnter(screen.getByRole("link", { name: "Requests" }));
    rerender(view("new:other:employee"));
    act(() => vi.advanceTimersByTime(120));
    expect(prefetch).not.toHaveBeenCalled();
    fireEvent.focus(screen.getByRole("link", { name: "AI" }));
    expect(prefetch).toHaveBeenCalledWith("/connections/ai");
  });
  it("does not speculate outside an authenticated workspace provider", () => {
    vi.useFakeTimers();
    render(<IntentLink href="/requests">Requests</IntentLink>);
    fireEvent.focus(screen.getByRole("link"));
    act(() => vi.advanceTimersByTime(1000));
    expect(prefetch).not.toHaveBeenCalled();
  });
});
