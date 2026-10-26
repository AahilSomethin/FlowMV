import type { LucideIcon } from "lucide-react";
import { EmptyState } from "@/components/patterns/empty-state";
import { PageHeader } from "@/components/patterns/page-header";
import { StatusBadge } from "@/components/patterns/status-badge";

export function ModulePlaceholder({
  title,
  description,
  icon: Icon,
}: {
  title: string;
  description: string;
  icon: LucideIcon;
}) {
  return (
    <>
      <PageHeader
        title={title}
        description={description}
        actions={<StatusBadge>Not available</StatusBadge>}
      />
      <EmptyState
        icon={<Icon aria-hidden="true" />}
        title="No records"
        description="Nothing is listed here because this module has no API yet. Counts, timings, and sample rows are intentionally omitted."
      />
    </>
  );
}
