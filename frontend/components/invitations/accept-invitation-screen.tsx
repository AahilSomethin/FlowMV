"use client";

import { useEffect, useState, useSyncExternalStore } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Loader2 } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api, ApiError, type OrganizationSummary } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { clearInviteToken, readInviteToken, scrubSecret, stashInviteToken } from "@/lib/invitation-token";

function subscribeInvite(listener: () => void) {
  window.addEventListener("flowmv-invite", listener);

  return () => window.removeEventListener("flowmv-invite", listener);
}

export function AcceptInvitationScreen() {
  const router = useRouter();
  const { user, status, refresh } = useAuth();
  const storedInvite = useSyncExternalStore(subscribeInvite, stashInviteToken, () => false);
  const [dismissedInvite, setDismissedInvite] = useState(false);
  const hasToken = storedInvite && !dismissedInvite;
  const ready = useSyncExternalStore(subscribeInvite, () => true, () => false);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [joined, setJoined] = useState<OrganizationSummary | null>(null);
  const [keptWorkspace, setKeptWorkspace] = useState(false);

  useEffect(() => {
    if (!ready || status !== "guest") {
      return;
    }

    router.replace("/login?next=%2Finvitations%2Faccept");
  }, [ready, router, status]);

  async function accept() {
    const token = readInviteToken();
    const previous = user?.current_organization?.uuid ?? null;

    if (!token) {
      setDismissedInvite(true);
      return;
    }

    setError(null);
    setPending(true);

    try {
      const organization = await api<OrganizationSummary>("/api/v1/invitations/accept", {
        method: "POST",
        body: JSON.stringify({ token }),
      });
      clearInviteToken();
      const refreshed = await refresh();
      setJoined(organization);
      setKeptWorkspace(previous !== null && refreshed.current_organization?.uuid === previous && previous !== organization.uuid);
      setDismissedInvite(true);
    } catch (caught) {
      const message = scrubSecret(
        caught instanceof ApiError ? caught.message : "Could not accept this invitation.",
        token,
      );
      setError(message);

      const keep = message.includes("Verify your email") || message.includes("different email");

      if (!keep) {
        clearInviteToken();
        setDismissedInvite(true);
      }
    } finally {
      setPending(false);
    }
  }

  async function resend() {
    setError(null);
    setNotice(null);
    setPending(true);

    try {
      await api<void>("/api/v1/email/verification-notification", { method: "POST" });
      setNotice("Verification email sent. Open it, then return here.");
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not send the verification email.");
    } finally {
      setPending(false);
    }
  }

  if (!ready || status === "loading" || status === "guest") {
    return (
      <main className="flex flex-1 items-center justify-center p-4">
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 className="size-4 animate-spin" aria-hidden="true" />
          Checking your session
        </p>
      </main>
    );
  }

  const unverified = user?.email_verified_at === null;

  return (
    <main className="flex flex-1 items-center justify-center p-4">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle className="text-xl">Accept invitation</CardTitle>
          <CardDescription>
            {joined
              ? keptWorkspace
                ? `You joined ${joined.name}. Your current workspace stays the same.`
                : `You joined ${joined.name}.`
              : "Accept with the account that is signed in now."}
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          {error ? (
            <Alert variant="destructive">
              <AlertTitle>Invitation</AlertTitle>
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          ) : null}
          {notice ? (
            <Alert>
              <AlertDescription>{notice}</AlertDescription>
            </Alert>
          ) : null}
          {joined ? (
            <Button className="h-10" asChild>
              <Link href="/dashboard">Go to dashboard</Link>
            </Button>
          ) : !hasToken ? (
            <p className="text-sm text-muted-foreground">This invitation link is incomplete or has already been used in this tab.</p>
          ) : unverified ? (
            <>
              <p className="text-sm leading-6 text-muted-foreground">
                Verify {user?.email} before accepting. The invitation stays pending until you do.
              </p>
              <Button type="button" className="h-10" onClick={() => void resend()} disabled={pending}>
                {pending ? <Loader2 className="animate-spin" aria-hidden="true" /> : null}
                Resend verification email
              </Button>
            </>
          ) : (
            <Button type="button" className="h-10" onClick={() => void accept()} disabled={pending}>
              {pending ? <Loader2 className="animate-spin" aria-hidden="true" /> : null}
              Accept invitation
            </Button>
          )}
        </CardContent>
      </Card>
    </main>
  );
}
