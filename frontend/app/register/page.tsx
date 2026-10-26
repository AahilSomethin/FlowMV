import { Suspense } from "react";
import { RegisterForm } from "@/components/auth/register-form";

export default function RegisterPage({
  searchParams,
}: {
  searchParams: Promise<{ next?: string | string[] }>;
}) {
  return (
    <Suspense fallback={<AuthRouteFallback />}>
      <RegisterRoute searchParams={searchParams} />
    </Suspense>
  );
}

async function RegisterRoute({
  searchParams,
}: {
  searchParams: Promise<{ next?: string | string[] }>;
}) {
  const params = await searchParams;
  const nextPath = Array.isArray(params.next) ? params.next[0] : params.next;

  return <RegisterForm nextPath={nextPath ?? null} />;
}

function AuthRouteFallback() {
  return (
    <main className="flex flex-1 items-center justify-center px-4 py-8">
      <p className="text-sm text-muted-foreground">Loading</p>
    </main>
  );
}
