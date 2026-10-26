"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { TextField } from "@/components/auth/text-field";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Textarea } from "@/components/ui/textarea";
import { api } from "@/lib/api";
import { applyServerErrors } from "@/lib/form-errors";
import type { WorkflowDetail } from "@/lib/workflows/types";

const schema = z.object({
  name: z.string().trim().min(1, "Name is required.").max(120),
  description: z.string().max(2000),
});

type Values = z.infer<typeof schema>;

export function CreateWorkflowDialog({ organizationId }: { organizationId: string }) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { name: "", description: "" },
  });

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button type="button">Create workflow</Button>
      </DialogTrigger>
      <DialogContent className="max-h-[90dvh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Create workflow</DialogTitle>
          <DialogDescription>Start with a draft. Nothing is published until you explicitly publish a version.</DialogDescription>
        </DialogHeader>
        <form
          className="flex flex-col gap-3"
          onSubmit={form.handleSubmit(async (values) => {
            setFormError(null);

            try {
              const created = await api<WorkflowDetail>(`/api/v1/organizations/${organizationId}/workflows`, {
                method: "POST",
                body: JSON.stringify({
                  name: values.name,
                  description: values.description.trim() === "" ? null : values.description.trim(),
                }),
              });
              setOpen(false);
              form.reset();
              router.push(`/workflows/${created.uuid}/edit`);
            } catch (error) {
              setFormError(applyServerErrors(error, form.setError));
            }
          })}
        >
          <TextField control={form.control} name="name" label="Name" />
          <Field>
            <FieldLabel htmlFor="workflow-description">Description</FieldLabel>
            <Textarea id="workflow-description" {...form.register("description")} />
            <FieldError errors={[form.formState.errors.description]} />
          </Field>
          {formError ? <p className="text-sm text-destructive">{formError}</p> : null}
          <DialogFooter>
            <Button type="submit" disabled={form.formState.isSubmitting}>
              Create
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
