import { Suspense } from "react";
import { AppShell } from "@/components/shell/app-shell";

export default function WorkspaceLayout({ children }: { children: React.ReactNode }) {
  return (
    <Suspense fallback={<WorkspaceShellFallback />}>
      <AppShell>{children}</AppShell>
    </Suspense>
  );
}

function WorkspaceShellFallback() {
  return (
    <main className="flex flex-1 items-center justify-center p-4">
      <p className="text-sm text-muted-foreground">Checking your session</p>
    </main>
  );
}
