import type { Metadata } from "next";
import { AcceptInvitationScreen } from "@/components/invitations/accept-invitation-screen";

export const metadata: Metadata = {
  title: "Accept invitation",
  robots: { index: false, follow: false },
  referrer: "no-referrer",
};

export default function AcceptInvitationPage() {
  return <AcceptInvitationScreen />;
}
