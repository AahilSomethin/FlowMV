"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { Loader2 } from "lucide-react";
import { useAuth } from "@/lib/auth";

export function HomeRedirect() {
  const router = useRouter();
  const { status } = useAuth();

  useEffect(() => {
    if (status === "authenticated") {
      router.replace("/dashboard");
    }

    if (status === "guest") {
      router.replace("/login");
    }
  }, [router, status]);

  return (
    <main className="flex flex-1 items-center justify-center p-4">
      <p className="flex items-center gap-2 text-sm text-muted-foreground">
        <Loader2 className="size-4 animate-spin" aria-hidden="true" />
        Opening FlowMV
      </p>
    </main>
  );
}
