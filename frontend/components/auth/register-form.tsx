"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { zodResolver } from "@hookform/resolvers/zod";
import { Loader2, UserPlus } from "lucide-react";
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

const registerSchema = z
  .object({
    name: z.string().trim().min(1, "Name is required.").max(255),
    email: z.string().email("Enter a valid email address."),
    password: z.string().min(8, "Use at least 8 characters."),
    password_confirmation: z.string().min(1, "Confirm your password."),
  })
  .refine((values) => values.password === values.password_confirmation, {
    path: ["password_confirmation"],
    message: "Passwords do not match.",
  });

type RegisterValues = z.infer<typeof registerSchema>;

export function RegisterForm({ nextPath = null }: { nextPath?: string | null }) {
  const router = useRouter();
  const { register: registerUser, status } = useAuth();
  const destination = safeNextPath(nextPath);

  useEffect(() => {
    if (status === "authenticated") {
      router.replace(destination);
    }
  }, [destination, router, status]);
  const [formError, setFormError] = useState<string | null>(null);
  const form = useForm<RegisterValues>({
    resolver: zodResolver(registerSchema),
    defaultValues: {
      name: "",
      email: "",
      password: "",
      password_confirmation: "",
    },
  });

  async function onSubmit(values: RegisterValues) {
    setFormError(null);

    try {
      await registerUser(values);
      router.replace(destination);
    } catch (error) {
      setFormError(applyServerErrors(error, form.setError));
    }
  }

  return (
    <AuthShell
      title="Create your account"
      description="Registration signs you in immediately."
      footer={
        <>
          Already have an account?{" "}
          <Link
            href={destination === "/dashboard" ? "/login" : `/login?next=${encodeURIComponent(destination)}`}
            className="font-medium text-primary underline-offset-4 hover:underline"
          >
            Sign in
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
          <TextField control={form.control} name="name" label="Name" autoComplete="name" />
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
            autoComplete="new-password"
          />
          <TextField
            control={form.control}
            name="password_confirmation"
            label="Confirm password"
            type="password"
            autoComplete="new-password"
          />
        </FieldGroup>
        <Button type="submit" size="lg" className="h-10 w-full" disabled={form.formState.isSubmitting}>
          {form.formState.isSubmitting ? (
            <Loader2 className="animate-spin" aria-hidden="true" />
          ) : (
            <UserPlus aria-hidden="true" />
          )}
          Create account
        </Button>
      </form>
    </AuthShell>
  );
}
