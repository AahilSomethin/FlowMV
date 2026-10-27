import { Suspense } from "react";
import type { Metadata } from "next";
import { RequestListScreen } from "@/components/execution/request-list-screen";
import { LoadingState } from "@/components/patterns/loading-state";

export const metadata: Metadata = {
  title: "Requests",
};

export default function RequestsPage() {
  return (
    <Suspense fallback={<LoadingState label="Loading requests" />}>
      <RequestListScreen />
    </Suspense>
  );
}
