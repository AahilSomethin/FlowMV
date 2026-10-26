"use client";

import { useEffect, useState, useSyncExternalStore } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Loader2 } from "lucide-react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { stashInviteToken } from "@/lib/invitation-token";

function subscribeInvite(listener: () => void) {
  window.addEventListener("flowmv-invite", listener);

  return () => window.removeEventListener("flowmv-invite", listener);
}

function readInviteTokenPresence(): boolean {
  return stashInviteToken();
}

export function VerifyEmailScreen({
  id,
  hash,
  expires,
  signature,
}: {
  id: string;
  hash: string;
  expires: string;
  signature: string;
}) {
  const router = useRouter();
  const { status, refresh } = useAuth();
  const [error, setError] = useState<string | null>(null);
  const [verified, setVerified] = useState(false);
  const hasInvite = useSyncExternalStore(subscribeInvite, readInviteTokenPresence, () => false);

  useEffect(() => {
    if (status !== "guest") {
      return;
    }

    const next = `/verify-email/${id}/${hash}?expires=${encodeURIComponent(expires)}&signature=${encodeURIComponent(signature)}`;
    router.replace(`/login?next=${encodeURIComponent(next)}`);
  }, [expires, hash, id, router, signature, status]);

  useEffect(() => {
    if (status !== "authenticated" || !expires || !signature) {
      return;
    }

    let active = true;

    const path = `/api/v1/email/verify/${id}/${hash}?expires=${encodeURIComponent(expires)}&signature=${encodeURIComponent(signature)}`;

    api<{ verified: boolean }>(path)
      .then(async () => {
        if (!active) {
          return;
        }

        await refresh();
        setVerified(true);
      })
      .catch((caught: unknown) => {
        if (!active) {
          return;
        }

        setError(caught instanceof ApiError ? caught.message : "This verification link is invalid.");
      });

    return () => {
      active = false;
    };
  }, [expires, hash, id, refresh, signature, status]);

  const pending = status === "authenticated" && !verified && !error && Boolean(expires) && Boolean(signature);

  return (
    <main className="flex flex-1 items-center justify-center p-4">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle className="text-xl">Verify email</CardTitle>
          <CardDescription>
            {verified ? "Your email address is verified." : "Confirm this address for your FlowMV account."}
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          {error ? (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          ) : null}
          {pending ? (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
              <Loader2 className="size-4 animate-spin" aria-hidden="true" />
              Verifying your email
            </p>
          ) : null}
          {verified ? (
            <Button className="h-10" asChild>
              <Link href={hasInvite ? "/invitations/accept" : "/dashboard"}>
                {hasInvite ? "Return to invitation" : "Go to dashboard"}
              </Link>
            </Button>
          ) : null}
        </CardContent>
      </Card>
    </main>
  );
}
