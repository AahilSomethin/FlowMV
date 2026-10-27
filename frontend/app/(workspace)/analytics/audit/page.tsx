import type { Metadata } from "next";
import { AuditScreen } from "@/components/analytics/audit-screen";

export const metadata: Metadata = {
  title: "Audit",
};

export default function AuditPage() {
  return <AuditScreen />;
}
