import Link from "next/link";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import type { AnalyticsScope } from "@/lib/analytics";

export function AnalyticsNav({ current }: { current: "overview" | "audit" }) {
  return (
    <nav className="flex flex-wrap gap-2" aria-label="Analytics sections">
      <Button variant={current === "overview" ? "default" : "outline"} className="h-10" asChild>
        <Link href="/analytics">Overview</Link>
      </Button>
      <Button variant={current === "audit" ? "default" : "outline"} className="h-10" asChild>
        <Link href="/analytics/audit">Audit</Link>
      </Button>
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
