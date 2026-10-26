"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { Loader2, LogOut, Workflow } from "lucide-react";
import { WorkspaceSwitcher } from "@/components/shell/workspace-switcher";
import { Button } from "@/components/ui/button";
import { useAuth } from "@/lib/auth";

export function AppShell({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const { user, status, logout } = useAuth();
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  useEffect(() => {
    if (status === "guest") {
      router.replace("/login");
    }

    if (status === "authenticated" && user && user.organizations.length === 0) {
      router.replace("/onboarding");
    }
  }, [router, status, user]);

  async function onLogout() {
    setError(null);
    setPending(true);

    try {
      await logout();
      router.replace("/login");
    } catch {
      setError("Could not sign out. Try again.");
      setPending(false);
    }
  }

  if (status !== "authenticated" || !user || user.organizations.length === 0) {
    return (
      <main className="flex flex-1 items-center justify-center p-4">
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 className="size-4 animate-spin" aria-hidden="true" />
          Checking your session
        </p>
      </main>
    );
  }

  return (
    <div className="flex min-h-dvh flex-1 flex-col">
      <header className="flex shrink-0 flex-wrap items-center gap-3 border-b px-4 py-3 sm:px-6">
        <Link href="/dashboard" className="flex min-w-0 items-center gap-2.5">
          <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground">
            <Workflow className="size-4" aria-hidden="true" />
          </span>
          <span className="truncate text-sm font-medium">FlowMV</span>
        </Link>
        <nav className="flex items-center gap-1" aria-label="Workspace">
          <Button variant={pathname === "/dashboard" ? "secondary" : "ghost"} className="h-10" asChild>
            <Link href="/dashboard" aria-current={pathname === "/dashboard" ? "page" : undefined}>
              Home
            </Link>
          </Button>
          <Button variant={pathname === "/team" ? "secondary" : "ghost"} className="h-10" asChild>
            <Link href="/team" aria-current={pathname === "/team" ? "page" : undefined}>
              Team
            </Link>
          </Button>
        </nav>
        <div className="ml-auto flex min-w-0 flex-wrap items-center justify-end gap-2">
          <WorkspaceSwitcher />
          <Button type="button" variant="outline" className="h-10" onClick={onLogout} disabled={pending}>
            {pending ? <Loader2 className="animate-spin" aria-hidden="true" /> : <LogOut aria-hidden="true" />}
            Log out
          </Button>
        </div>
      </header>
      <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4 sm:p-6">
        {error ? (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        ) : null}
        {children}
      </main>
    </div>
  );
}
