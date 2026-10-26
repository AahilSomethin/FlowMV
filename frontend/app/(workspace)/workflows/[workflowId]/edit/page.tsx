import { Suspense } from "react";
import type { Metadata } from "next";
import { LoadingState } from "@/components/patterns/loading-state";
import { WorkflowBuilder } from "@/components/workflows/workflow-builder";

export const metadata: Metadata = {
  title: "Edit workflow",
};

export default function WorkflowEditPage({ params }: { params: Promise<{ workflowId: string }> }) {
  return (
    <Suspense fallback={<LoadingState label="Loading workflow" />}>
      <WorkflowEditRoute params={params} />
    </Suspense>
  );
}

async function WorkflowEditRoute({ params }: { params: Promise<{ workflowId: string }> }) {
  const { workflowId } = await params;

  return <WorkflowBuilder workflowId={workflowId} />;
}
