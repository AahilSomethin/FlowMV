import type { ReactNode } from "react";
import { Workflow } from "lucide-react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

export function AuthShell({
  title,
  description,
  children,
  footer,
}: {
  title: string;
  description: string;
  children: ReactNode;
  footer: ReactNode;
}) {
  return (
    <main className="grid min-h-dvh flex-1 lg:grid-cols-2">
      <section className="hidden flex-col justify-center bg-brand px-12 text-brand-foreground lg:flex">
        <div className="flex max-w-md flex-col gap-8">
          <div className="flex items-center gap-2.5">
            <span className="flex size-9 items-center justify-center rounded-lg bg-brand-foreground/15">
              <Workflow className="size-4" aria-hidden="true" />
            </span>
            <span className="font-medium tracking-tight">FlowMV</span>
          </div>
          <div>
            <p className="text-3xl font-semibold tracking-tight text-balance">Every request has a next step.</p>
            <p className="mt-3 text-sm leading-6 text-brand-foreground/80">Approvals, revisions, and assignments stay attached to the workflow that created them.</p>
          </div>
          <p className="text-xs text-brand-foreground/70">Workspace access follows your role. Switching workspaces never changes who you are.</p>
        </div>
      </section>
      <section className="flex items-center justify-center px-4 py-8">
        <div className="w-full max-w-md">
          <div className="mb-6 flex items-center gap-2.5 lg:hidden">
            <span className="flex size-9 items-center justify-center rounded-lg bg-primary text-primary-foreground">
              <Workflow className="size-4" aria-hidden="true" />
            </span>
            <span className="text-sm font-medium tracking-tight">FlowMV</span>
          </div>
          <Card>
            <CardHeader>
              <CardTitle><h1 className="text-xl">{title}</h1></CardTitle>
              <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent>{children}</CardContent>
          </Card>
          <div className="mt-4 text-center text-sm text-muted-foreground">{footer}</div>
        </div>
      </section>
    </main>
  );
}
