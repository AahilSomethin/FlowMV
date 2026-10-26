"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { zodResolver } from "@hookform/resolvers/zod";
import { Loader2, LogOut, Workflow } from "lucide-react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { TextField } from "@/components/auth/text-field";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { FieldDescription, FieldGroup } from "@/components/ui/field";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { applyServerErrors } from "@/lib/form-errors";
import type { OrganizationSummary } from "@/lib/api";

const schema = z.object({
  name: z.string().trim().min(1, "Name is required.").max(255),
  slug: z
    .string()
    .trim()
    .max(255)
    .refine((value) => value === "" || /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(value), {
      message: "Use lowercase letters, numbers, and single hyphens.",
    }),
});

type Values = z.infer<typeof schema>;

export function CreateOrganizationForm() {
  const router = useRouter();
  const { user, status, logout, refresh } = useAuth();
  const [formError, setFormError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { name: "", slug: "" },
  });

  useEffect(() => {
    if (status === "guest") {
      router.replace("/login");
    }

    if (status === "authenticated" && user && user.organizations.length > 0) {
      router.replace("/dashboard");
    }
  }, [router, status, user]);

  async function onLogout() {
    setPending(true);

    try {
      await logout();
      router.replace("/login");
    } catch {
      setFormError("Could not sign out. Try again.");
      setPending(false);
    }
  }

  async function onSubmit(values: Values) {
    setFormError(null);

    try {
      await api<OrganizationSummary>("/api/v1/organizations", {
        method: "POST",
        body: JSON.stringify({
          name: values.name,
          ...(values.slug ? { slug: values.slug } : {}),
        }),
      });
      await refresh();
      router.replace("/dashboard");
    } catch (error) {
      setFormError(applyServerErrors(error, form.setError));
    }
  }

  if (status !== "authenticated" || !user || user.organizations.length > 0) {
    return (
      <main className="flex flex-1 items-center justify-center p-4">
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 className="size-4 animate-spin" aria-hidden="true" />
          Checking your session
        </p>
      </main>
    );
  }

  return (
    <div className="flex min-h-dvh flex-1 flex-col">
      <header className="flex shrink-0 items-center justify-between gap-3 border-b px-4 py-3 sm:px-6">
        <div className="flex min-w-0 items-center gap-2.5">
          <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground">
            <Workflow className="size-4" aria-hidden="true" />
          </span>
          <span className="truncate text-sm font-medium">FlowMV</span>
        </div>
        <Button type="button" variant="outline" className="h-10" onClick={onLogout} disabled={pending}>
          {pending ? <Loader2 className="animate-spin" aria-hidden="true" /> : <LogOut aria-hidden="true" />}
          Log out
        </Button>
      </header>
      <div className="flex flex-1 items-center justify-center p-4">
        <Card className="w-full max-w-md">
          <CardHeader>
            <CardTitle className="text-xl">Create your organization</CardTitle>
            <CardDescription>You will be its first admin. You can invite other people after this.</CardDescription>
          </CardHeader>
          <CardContent>
          <form onSubmit={form.handleSubmit(onSubmit)} noValidate className="flex flex-col gap-5">
            {formError ? (
              <Alert variant="destructive">
                <AlertDescription>{formError}</AlertDescription>
              </Alert>
            ) : null}
            <FieldGroup>
              <TextField control={form.control} name="name" label="Organization name" autoComplete="organization" />
              <div className="flex flex-col gap-2">
                <TextField
                  control={form.control}
                  name="slug"
                  label="Slug"
                  autoComplete="off"
                  spellCheck={false}
                  placeholder="optional"
                />
                <FieldDescription>Leave the slug blank and FlowMV will create one from the name.</FieldDescription>
              </div>
            </FieldGroup>
            <Button type="submit" size="lg" className="h-10 w-full" disabled={form.formState.isSubmitting}>
              {form.formState.isSubmitting ? <Loader2 className="animate-spin" aria-hidden="true" /> : null}
            Create organization
          </Button>
          </form>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
