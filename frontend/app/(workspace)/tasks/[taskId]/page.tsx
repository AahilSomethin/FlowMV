import type { Metadata } from "next";
import { TaskPageScreen } from "@/components/execution/task-page-screen";

export const metadata: Metadata = {
  title: "Task",
};

export default function TaskDetailPage() {
  return <TaskPageScreen />;
}
