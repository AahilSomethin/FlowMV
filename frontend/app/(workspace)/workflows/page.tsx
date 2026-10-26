import type { Metadata } from "next";
import { WorkflowListScreen } from "@/components/workflows/workflow-list-screen";

export const metadata: Metadata = {
  title: "Workflows",
};

export default function WorkflowsPage() {
  return <WorkflowListScreen />;
}
