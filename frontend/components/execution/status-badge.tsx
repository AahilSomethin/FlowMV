import { StatusBadge } from "@/components/patterns/status-badge";
import { instanceStatusLabel, instanceStatusTone, type InstanceStatus } from "@/lib/execution";

export function InstanceStatusBadge({ status }: { status: InstanceStatus }) {
  return <StatusBadge tone={instanceStatusTone(status)}>{instanceStatusLabel(status)}</StatusBadge>;
}
