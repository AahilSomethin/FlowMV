import { Suspense } from "react";
import { LoginForm } from "@/components/auth/login-form";

export default function LoginPage({
  searchParams,
}: {
  searchParams: Promise<{ next?: string | string[] }>;
}) {
  return (
    <Suspense fallback={<AuthRouteFallback />}>
      <LoginRoute searchParams={searchParams} />
    </Suspense>
  );
}

async function LoginRoute({
  searchParams,
}: {
  searchParams: Promise<{ next?: string | string[] }>;
}) {
  const params = await searchParams;
  const nextPath = Array.isArray(params.next) ? params.next[0] : params.next;

  return <LoginForm nextPath={nextPath ?? null} />;
}

function AuthRouteFallback() {
  return (
    <main className="flex flex-1 items-center justify-center px-4 py-8">
      <p className="text-sm text-muted-foreground">Loading</p>
    </main>
  );
}
