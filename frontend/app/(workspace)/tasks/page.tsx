import type { Metadata } from "next";
import { ListTodo } from "lucide-react";
import { ModulePlaceholder } from "@/components/modules/module-placeholder";

export const metadata: Metadata = {
  title: "My Tasks",
};

export default function TasksPage() {
  return (
    <ModulePlaceholder
      title="My Tasks"
      icon={ListTodo}
      description="Tasks assigned to you will appear here when a task API exists."
    />
  );
}
