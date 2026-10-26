import type { Metadata } from "next";
import { TeamScreen } from "@/components/team/team-screen";

export const metadata: Metadata = {
  title: "Team",
};

export default function TeamPage() {
  return <TeamScreen />;
}
