import type { Metadata } from "next";
import { RequestListScreen } from "@/components/execution/request-list-screen";

export const metadata: Metadata = {
  title: "Requests",
};

export default function RequestsPage() {
  return <RequestListScreen />;
}
