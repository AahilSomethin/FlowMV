import type { Metadata } from "next";
import { RequestDetailScreen } from "@/components/execution/request-detail-screen";

export const metadata: Metadata = {
  title: "Request",
};

export default function RequestDetailPage() {
  return <RequestDetailScreen />;
}
