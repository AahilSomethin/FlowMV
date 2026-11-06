import { IntentLink as Link } from "@/components/shell/intent-link";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { cn } from "@/lib/utils";
import type { AnalyticsScope } from "@/lib/analytics";

export function AnalyticsNav({ current }: { current: "overview" | "audit" }) {
  const item = "inline-flex h-8 items-center rounded-md px-3 text-sm font-medium";

  return (
    <nav className="flex w-fit flex-wrap gap-1 rounded-lg bg-surface-subtle p-1 ring-1 ring-foreground/10" aria-label="Analytics sections">
      <Link href="/analytics" aria-current={current === "overview" ? "page" : undefined} className={cn(item, current === "overview" ? "bg-card text-foreground shadow-sm" : "text-muted-foreground hover:text-foreground")}>
        Performance
      </Link>
      <Link href="/analytics/audit" aria-current={current === "audit" ? "page" : undefined} className={cn(item, current === "audit" ? "bg-card text-foreground shadow-sm" : "text-muted-foreground hover:text-foreground")}>
        Audit
      </Link>
    </nav>
  );
}

export function ScopeBanner({ scope, label }: { scope: AnalyticsScope; label: string }) {
  return (
    <Alert data-testid="analytics-scope">
      <AlertTitle>{label}</AlertTitle>
      <AlertDescription>
        {scope === "organization"
          ? "These figures include every request in this workspace. Dates are UTC."
          : "These figures include only requests you can open. They are not organization-wide. Dates are UTC."}
      </AlertDescription>
    </Alert>
  );
}
