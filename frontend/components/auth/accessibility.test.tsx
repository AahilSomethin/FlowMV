import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import axe from "axe-core";
import { LoginForm } from "./login-form";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace: vi.fn() }) }));
vi.mock("@/lib/auth", () => ({
  useAuth: () => ({ status: "guest", login: vi.fn() }),
}));
async function violations() {
  return (
    await axe.run(document.body, {
      rules: { "color-contrast": { enabled: false } },
    })
  ).violations.map((v) => ({ id: v.id, impact: v.impact }));
}
describe("automated semantics and keyboard accessibility", () => {
  it("exposes labelled login errors and keyboard field order", async () => {
    const user = userEvent.setup();
    render(<LoginForm />);
    await user.tab();
    expect(screen.getByLabelText("Email")).toHaveFocus();
    await user.tab();
    expect(screen.getByLabelText("Password", { exact: true })).toHaveFocus();
    await user.click(
      screen.getByRole("button", { name: /^Sign in$/ }),
    );
    await screen.findByText("Enter a valid email address.");
    expect(await violations()).toEqual([]);
  });
  it("labels dialogs and restores focus after keyboard dismissal", async () => {
    const user = userEvent.setup();
    render(
      <main>
        <Dialog>
          <DialogTrigger asChild>
            <Button>Review operation</Button>
          </DialogTrigger>
          <DialogContent>
            <DialogTitle>Review operation</DialogTitle>
            <DialogDescription>Review the requested action.</DialogDescription>
            <Button>Continue</Button>
          </DialogContent>
        </Dialog>
      </main>,
    );
    const trigger = screen.getByRole("button", { name: "Review operation" });
    await user.click(trigger);
    await screen.findByRole("dialog");
    expect(await violations()).toEqual([]);
    await user.keyboard("{Escape}");
    expect(trigger).toHaveFocus();
  });
});
