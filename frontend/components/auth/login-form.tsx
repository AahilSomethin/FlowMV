"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { zodResolver } from "@hookform/resolvers/zod";
import { Loader2, LogIn } from "lucide-react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { AuthShell } from "@/components/auth/auth-shell";
import { TextField } from "@/components/auth/text-field";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FieldGroup } from "@/components/ui/field";
import { applyServerErrors } from "@/lib/form-errors";
import { useAuth } from "@/lib/auth";
import { safeNextPath } from "@/lib/navigation";

const loginSchema = z.object({
  email: z.string().email("Enter a valid email address."),
  password: z.string().min(1, "Password is required."),
});

type LoginValues = z.infer<typeof loginSchema>;

export function LoginForm({ nextPath = null }: { nextPath?: string | null }) {
  const router = useRouter();
  const { login, status } = useAuth();
  const destination = safeNextPath(nextPath);

  useEffect(() => {
    if (status === "authenticated") {
      router.replace(destination);
    }
  }, [destination, router, status]);
  const [formError, setFormError] = useState<string | null>(null);
  const form = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: "", password: "" },
  });

  async function onSubmit(values: LoginValues) {
    setFormError(null);

    try {
      await login(values);
      router.replace(destination);
    } catch (error) {
      setFormError(applyServerErrors(error, form.setError));
    }
  }

  return (
    <AuthShell
      title="Sign in"
      description="Use your FlowMV account to continue."
      footer={
        <>
          New to FlowMV?{" "}
          <Link
            href={destination === "/dashboard" ? "/register" : `/register?next=${encodeURIComponent(destination)}`}
            className="font-medium text-primary underline-offset-4 hover:underline"
          >
            Create an account
          </Link>
        </>
      }
    >
      <form onSubmit={form.handleSubmit(onSubmit)} noValidate className="flex flex-col gap-5">
        {formError ? (
          <Alert variant="destructive">
            <AlertDescription>{formError}</AlertDescription>
          </Alert>
        ) : null}
        <FieldGroup>
          <TextField
            control={form.control}
            name="email"
            label="Email"
            type="email"
            autoComplete="email"
            spellCheck={false}
          />
          <TextField
            control={form.control}
            name="password"
            label="Password"
            type="password"
            autoComplete="current-password"
          />
        </FieldGroup>
        <Button type="submit" size="lg" className="h-10 w-full" disabled={form.formState.isSubmitting}>
          {form.formState.isSubmitting ? (
            <Loader2 className="animate-spin" aria-hidden="true" />
          ) : (
            <LogIn aria-hidden="true" />
          )}
          Sign in
        </Button>
      </form>
    </AuthShell>
  );
}
