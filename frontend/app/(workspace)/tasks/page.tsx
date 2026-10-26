import type { Metadata } from "next";
import { TaskInboxScreen } from "@/components/execution/task-inbox-screen";

export const metadata: Metadata = {
  title: "My Tasks",
};

export default function TasksPage() {
  return <TaskInboxScreen />;
}
