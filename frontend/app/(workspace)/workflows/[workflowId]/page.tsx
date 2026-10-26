import { Suspense } from "react";
import type { Metadata } from "next";
import { LoadingState } from "@/components/patterns/loading-state";
import { WorkflowDetailScreen } from "@/components/workflows/workflow-detail-screen";

export const metadata: Metadata = {
  title: "Workflow",
};

export default function WorkflowDetailPage({ params }: { params: Promise<{ workflowId: string }> }) {
  return (
    <Suspense fallback={<LoadingState label="Loading workflow" />}>
      <WorkflowDetailRoute params={params} />
    </Suspense>
  );
}

async function WorkflowDetailRoute({ params }: { params: Promise<{ workflowId: string }> }) {
  const { workflowId } = await params;

  return <WorkflowDetailScreen workflowId={workflowId} />;
}
