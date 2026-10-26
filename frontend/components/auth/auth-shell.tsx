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
    <main className="flex flex-1 items-center justify-center px-4 py-8">
      <div className="w-full max-w-md">
        <div className="mb-6 flex items-center gap-2.5">
          <span className="flex size-9 items-center justify-center rounded-lg bg-primary text-primary-foreground">
            <Workflow className="size-4" aria-hidden="true" />
          </span>
          <span className="text-sm font-medium tracking-tight">FlowMV</span>
        </div>
        <Card>
          <CardHeader>
            <CardTitle className="text-xl">{title}</CardTitle>
            <CardDescription>{description}</CardDescription>
          </CardHeader>
          <CardContent>{children}</CardContent>
        </Card>
        <div className="mt-4 text-center text-sm text-muted-foreground">{footer}</div>
      </div>
    </main>
  );
}
