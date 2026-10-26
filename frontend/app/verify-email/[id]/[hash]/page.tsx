import { Suspense } from "react";
import type { Metadata } from "next";
import { VerifyEmailScreen } from "@/components/auth/verify-email-screen";

export const metadata: Metadata = {
  title: "Verify email",
  robots: { index: false, follow: false },
  referrer: "no-referrer",
};

export default function VerifyEmailPage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string; hash: string }>;
  searchParams: Promise<{ expires?: string; signature?: string }>;
}) {
  return (
    <Suspense
      fallback={
        <main className="flex flex-1 items-center justify-center p-4">
          <p className="text-sm text-muted-foreground">Checking your verification link</p>
        </main>
      }
    >
      <VerifyEmailRoute params={params} searchParams={searchParams} />
    </Suspense>
  );
}

async function VerifyEmailRoute({
  params,
  searchParams,
}: {
  params: Promise<{ id: string; hash: string }>;
  searchParams: Promise<{ expires?: string; signature?: string }>;
}) {
  const [{ id, hash }, query] = await Promise.all([params, searchParams]);

  return (
    <VerifyEmailScreen
      id={id}
      hash={hash}
      expires={query.expires ?? ""}
      signature={query.signature ?? ""}
    />
  );
}
