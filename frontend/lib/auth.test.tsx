import { act, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { AuthProvider, useAuth } from "./auth";
import { ApiError, type OrganizationRole, type User } from "./api";
const api = vi.hoisted(() => vi.fn());
vi.mock("@/lib/api", async () => ({ ...await vi.importActual<typeof import("./api")>("./api"), api }));
function user(role: OrganizationRole): User {
 const organization = { uuid: "organization", name: "Synthetic", slug: "synthetic", role, created_at: null };
 return { id: 1, name: "Synthetic", email: "synthetic@example.com", email_verified_at: null, created_at: null, current_organization: organization, organizations: [organization] };
}
function Context() { const { status, user, logout } = useAuth(); return <><output>{status}:{user?.current_organization?.role ?? "none"}</output><button onClick={() => void logout()}>Sign out</button></>; }
afterEach(() => { vi.useRealTimers(); api.mockReset(); });
describe("visible-session revalidation", () => {
 it("cannot resurrect account state when a refresh finishes after logout", async () => {
  vi.useFakeTimers(); Object.defineProperty(document, "visibilityState", { configurable: true, value: "visible" });
  let finish: (value: User) => void = () => { throw new Error("Refresh not started"); };
  api.mockResolvedValueOnce(user("admin")).mockImplementationOnce(() => new Promise<User>(resolve => { finish = resolve; })).mockResolvedValueOnce(undefined);
  render(<AuthProvider><Context/></AuthProvider>);
  await act(async () => {});
  await act(async () => { vi.advanceTimersByTime(60_000); });
  await act(async () => { fireEvent.click(screen.getByRole("button", { name: "Sign out" })); });
  expect(screen.getByText("guest:none")).toBeInTheDocument();
  await act(async () => { finish(user("admin")); });
  expect(screen.getByText("guest:none")).toBeInTheDocument();
 });
 it("refreshes changed roles and clears a revoked session without caching permission", async () => {
  vi.useFakeTimers(); Object.defineProperty(document, "visibilityState", { configurable: true, value: "visible" });
  api.mockResolvedValueOnce(user("admin")).mockResolvedValueOnce(user("employee")).mockRejectedValueOnce(new ApiError("Expired", 401));
  render(<AuthProvider><Context/></AuthProvider>);
  await act(async () => {}); expect(screen.getByText("authenticated:admin")).toBeInTheDocument();
  await act(async () => { vi.advanceTimersByTime(60_000); }); expect(screen.getByText("authenticated:employee")).toBeInTheDocument();
  await act(async () => { vi.advanceTimersByTime(60_000); }); expect(screen.getByText("guest:none")).toBeInTheDocument();
  expect(api).toHaveBeenCalledTimes(3);
 });
});
