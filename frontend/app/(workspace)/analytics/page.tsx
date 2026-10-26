import type { Metadata } from "next";
import { BarChart3 } from "lucide-react";
import { ModulePlaceholder } from "@/components/modules/module-placeholder";

export const metadata: Metadata = {
  title: "Analytics",
};

export default function AnalyticsPage() {
  return (
    <ModulePlaceholder
      title="Analytics"
      icon={BarChart3}
      description="Reports will appear here when an analytics API exists."
    />
  );
}
