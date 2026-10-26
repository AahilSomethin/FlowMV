"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Loader2, LogOut, Workflow } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { useAuth } from "@/lib/auth";

export function DashboardScreen() {
  const router = useRouter();
  const { user, status, logout } = useAuth();
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  useEffect(() => {
    if (status === "guest") {
      router.replace("/login");
    }
  }, [router, status]);

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

  if (status !== "authenticated" || !user) {
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
      <header className="flex items-center justify-between gap-3 border-b px-4 py-3 sm:px-6">
        <div className="flex min-w-0 items-center gap-2.5">
          <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground">
            <Workflow className="size-4" aria-hidden="true" />
          </span>
          <span className="truncate text-sm font-medium">FlowMV</span>
        </div>
        <Button type="button" variant="outline" className="h-10" onClick={onLogout} disabled={pending}>
          {pending ? <Loader2 className="animate-spin" aria-hidden="true" /> : <LogOut aria-hidden="true" />}
          Log out
        </Button>
      </header>
      <main className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4 sm:p-6">
        {error ? (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        ) : null}
        <Card>
          <CardHeader>
            <CardTitle className="text-xl">Welcome, {user.name}</CardTitle>
            <CardDescription>{user.email}</CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-sm leading-6 text-muted-foreground">
              You are signed in. This session stays with this browser until you log out.
            </p>
          </CardContent>
        </Card>
      </main>
    </div>
  );
}
