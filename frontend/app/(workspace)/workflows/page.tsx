import type { Metadata } from "next";
import { GitBranch } from "lucide-react";
import { ModulePlaceholder } from "@/components/modules/module-placeholder";

export const metadata: Metadata = {
  title: "Workflows",
};

export default function WorkflowsPage() {
  return (
    <ModulePlaceholder
      title="Workflows"
      icon={GitBranch}
      description="Workflow definitions will appear here when a workflow API exists."
    />
  );
}
