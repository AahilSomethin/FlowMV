import type { ReactNode } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

export function StatCard({
  label,
  value,
  detail,
  icon,
}: {
  label: string;
  value: string;
  detail?: string;
  icon?: ReactNode;
}) {
  return (
    <Card className="shadow-(--shadow-card)">
      <CardHeader>
        <div className="flex items-center justify-between gap-3">
          <CardDescription className="text-xs font-medium tracking-wide uppercase">{label}</CardDescription>
          {icon ? <span className="text-muted-foreground [&_svg]:size-4">{icon}</span> : null}
        </div>
        <CardTitle className="text-2xl font-semibold tracking-tight tabular-nums text-balance">{value}</CardTitle>
      </CardHeader>
      {detail ? <CardContent className="text-sm leading-5 text-muted-foreground text-pretty">{detail}</CardContent> : null}
    </Card>
  );
}
