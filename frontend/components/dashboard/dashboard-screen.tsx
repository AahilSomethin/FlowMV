"use client";

import { AppShell } from "@/components/shell/app-shell";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { useAuth } from "@/lib/auth";
import { roleLabel } from "@/lib/organizations";

export function DashboardScreen() {
  const { user } = useAuth();
  const current = user?.current_organization ?? null;

  return (
    <AppShell>
      <Card>
        <CardHeader>
          <CardTitle className="text-xl">Welcome, {user?.name}</CardTitle>
          <CardDescription>{user?.email}</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-2">
          {current ? (
            <p className="text-sm leading-6 text-muted-foreground">
              You are in {current.name} as {roleLabel(current.role)}. This session stays with this browser until you log out.
            </p>
          ) : (
            <p className="text-sm leading-6 text-muted-foreground">
              Choose a workspace from the header to continue.
            </p>
          )}
        </CardContent>
      </Card>
    </AppShell>
  );
}
