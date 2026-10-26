import type { Metadata } from "next";
import { RequestCreateScreen } from "@/components/execution/request-create-screen";

export const metadata: Metadata = {
  title: "New request",
};

export default function NewRequestPage() {
  return <RequestCreateScreen />;
}
