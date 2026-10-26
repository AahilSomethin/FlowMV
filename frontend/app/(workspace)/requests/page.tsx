import type { Metadata } from "next";
import { Inbox } from "lucide-react";
import { ModulePlaceholder } from "@/components/modules/module-placeholder";

export const metadata: Metadata = {
  title: "Requests",
};

export default function RequestsPage() {
  return (
    <ModulePlaceholder
      title="Requests"
      icon={Inbox}
      description="Requests will appear here when a request API exists."
    />
  );
}
