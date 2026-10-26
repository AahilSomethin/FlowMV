"use client";

import { useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { Loader2 } from "lucide-react";
import { Controller, useForm } from "react-hook-form";
import { z } from "zod";
import { TextField } from "@/components/auth/text-field";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Field, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { api, type OrganizationSummary } from "@/lib/api";
import { applyServerErrors } from "@/lib/form-errors";
import { roleLabel, type Invitation } from "@/lib/organizations";

const schema = z.object({
  email: z.string().trim().email("Enter a valid email address."),
  role: z.enum(["employee", "manager", "admin"]),
});

type Values = z.infer<typeof schema>;

export function InviteMemberDialog({
  organization,
  onInvited,
}: {
  organization: OrganizationSummary;
  onInvited: (invitation: Invitation) => void;
}) {
  const [open, setOpen] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { email: "", role: "employee" },
  });

  async function onSubmit(values: Values) {
    setFormError(null);

    try {
      const invitation = await api<Invitation>(`/api/v1/organizations/${organization.uuid}/invitations`, {
        method: "POST",
        body: JSON.stringify(values),
      });
      onInvited(invitation);
      form.reset({ email: "", role: "employee" });
      setOpen(false);
    } catch (error) {
      setFormError(applyServerErrors(error, form.setError));
    }
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button type="button" className="h-10">
          Invite member
        </Button>
      </DialogTrigger>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Invite a member</DialogTitle>
          <DialogDescription>
            Send an invitation to join {organization.name}. The default role is Employee.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={form.handleSubmit(onSubmit)} noValidate className="flex flex-col gap-4">
          {formError ? (
            <Alert variant="destructive">
              <AlertDescription>{formError}</AlertDescription>
            </Alert>
          ) : null}
          <FieldGroup>
            <TextField control={form.control} name="email" label="Email" type="email" autoComplete="email" spellCheck={false} />
            <Controller
              control={form.control}
              name="role"
              render={({ field, fieldState }) => (
                <Field data-invalid={fieldState.invalid || undefined}>
                  <FieldLabel htmlFor="invite-role">Role</FieldLabel>
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger id="invite-role" className="h-10 w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="employee">{roleLabel("employee")}</SelectItem>
                      <SelectItem value="manager">{roleLabel("manager")}</SelectItem>
                      <SelectItem value="admin">{roleLabel("admin")}</SelectItem>
                    </SelectContent>
                  </Select>
                  <FieldError errors={[fieldState.error]} />
                </Field>
              )}
            />
          </FieldGroup>
          <DialogFooter>
            <Button type="submit" className="h-10" disabled={form.formState.isSubmitting}>
              {form.formState.isSubmitting ? <Loader2 className="animate-spin" aria-hidden="true" /> : null}
              Send invitation
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
