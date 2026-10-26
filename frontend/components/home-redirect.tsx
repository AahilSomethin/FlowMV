"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { Loader2 } from "lucide-react";
import { ErrorState } from "@/components/patterns/error-state";
import { Button } from "@/components/ui/button";
import { useAuth } from "@/lib/auth";

export function HomeRedirect() {
  const router = useRouter();
  const { status, refresh } = useAuth();

  useEffect(() => {
    if (status === "authenticated") {
      router.replace("/dashboard");
    }

    if (status === "guest") {
      router.replace("/login");
    }
  }, [router, status]);

  if (status === "unreachable") {
    return (
      <main className="flex flex-1 items-center justify-center p-4">
        <div className="w-full max-w-md">
          <ErrorState
            title="Unable to load your account"
            description="FlowMV could not reach your session. This does not sign you out."
            action={
              <Button type="button" className="h-10" onClick={() => void refresh().catch(() => undefined)}>
                Try again
              </Button>
            }
          />
        </div>
      </main>
    );
  }

  return (
    <main className="flex flex-1 items-center justify-center p-4">
      <p className="flex items-center gap-2 text-sm text-muted-foreground">
        <Loader2 className="size-4 animate-spin" aria-hidden="true" />
        Opening FlowMV
      </p>
    </main>
  );
}
