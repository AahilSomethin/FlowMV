"use client";

import { useMemo, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { Controller, useForm } from "react-hook-form";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { AlertDialog, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from "@/components/ui/alert-dialog";
import { applyServerErrors } from "@/lib/form-errors";
import {
  buildSubmissionSchema,
  defaultSubmissionValues,
  hasBlockingFileField,
  type SubmissionValues,
} from "@/lib/execution";
import type { FormDefinition, FormField } from "@/lib/workflows/types";

const emptyOption = "__empty__";

export function DynamicForm({
  definition,
  initialValues,
  submitLabel,
  pending = false,
  disabled = false,
  banner,
  confirmTitle,
  confirmDescription,
  onSubmit,
}: {
  definition: FormDefinition;
  initialValues?: SubmissionValues;
  submitLabel: string;
  pending?: boolean;
  disabled?: boolean;
  banner?: string | null;
  confirmTitle?: string;
  confirmDescription?: string;
  onSubmit: (values: SubmissionValues) => Promise<void>;
}) {
  const schema = useMemo(() => buildSubmissionSchema(definition), [definition]);
  const blocked = hasBlockingFileField(definition) || disabled;
  const [review, setReview] = useState<SubmissionValues | null>(null);
  const form = useForm<SubmissionValues>({
    resolver: zodResolver(schema),
    defaultValues: initialValues ?? defaultSubmissionValues(definition),
  });

  async function send(values: SubmissionValues) {
    try {
      await onSubmit(values);
    } catch (error) {
      applyServerErrors(error, form.setError);
    } finally {
      setReview(null);
    }
  }

  return (
    <form
      className="flex flex-col gap-5"
      onSubmit={form.handleSubmit(async (values) => {
        if (confirmTitle) { setReview(values); return; }
        await send(values);
      })}
      noValidate
    >
      {definition.fields.length ? <p className="text-xs text-muted-foreground">Fields marked <span className="font-semibold text-foreground">*</span> are required.</p> : <p className="text-sm text-muted-foreground">No additional details are needed for this request.</p>}
      {Object.keys(form.formState.errors).length ? <Alert variant="destructive" role="alert"><AlertTitle>Check your answers</AlertTitle><AlertDescription><ul className="space-y-1">{Object.entries(form.formState.errors).map(([id, error]) => <li key={id}><button type="button" className="text-left underline underline-offset-4" onClick={() => { form.setFocus(id); document.getElementById(id)?.focus(); }}>{definition.fields.find((field) => field.id === id)?.label ?? "Form"}: {String(error?.message ?? "Please check this field.")}</button></li>)}</ul></AlertDescription></Alert> : null}
      {blocked && hasBlockingFileField(definition) ? (
        <Alert>
          <AlertTitle>Attachments are unavailable</AlertTitle>
          <AlertDescription>File attachments cannot be submitted yet.</AlertDescription>
        </Alert>
      ) : null}
      <FieldGroup>
        {definition.fields.map((field) =>
          field.type === "file" ? (
            <Field key={field.id}>
              <FieldLabel>{field.label}</FieldLabel>
              {field.description ? <FieldDescription>{field.description}</FieldDescription> : null}
              <p className="text-sm text-muted-foreground">File attachments cannot be submitted yet.</p>
            </Field>
          ) : (
            <Controller
              key={field.id}
              control={form.control}
              name={field.id}
              render={({ field: control, fieldState }) => (
                <Field data-invalid={fieldState.invalid || undefined}>
                  {field.type === "checkbox" ? (
                    <FieldLabel className="items-start gap-2">
                      <Checkbox
                        id={field.id}
                        ref={control.ref}
                        onBlur={control.onBlur}
                        aria-required={field.required}
                        aria-invalid={fieldState.invalid || undefined}
                        aria-describedby={`${field.id}-help ${field.id}-error`}
                        checked={control.value === true}
                        disabled={pending || blocked}
                        onCheckedChange={(checked) => control.onChange(checked === true)}
                      />
                      <span>{field.label}{field.required ? <span aria-hidden="true"> *</span> : null}</span>
                    </FieldLabel>
                  ) : (
                    <FieldLabel htmlFor={field.id}>{field.label}{field.required ? <span aria-hidden="true"> *</span> : null}</FieldLabel>
                  )}
                  <FieldDescription id={`${field.id}-help`}>{field.description || (field.required ? "Required" : "Optional")}</FieldDescription>
                  {field.type === "textarea" ? (
                    <Textarea
                      id={field.id}
                      ref={control.ref}
                      onBlur={control.onBlur}
                      aria-required={field.required}
                      aria-describedby={`${field.id}-help ${field.id}-error`}
                      value={textValue(control.value)}
                      disabled={pending || blocked}
                      aria-invalid={fieldState.invalid || undefined}
                      onChange={(event) => control.onChange(event.target.value)}
                    />
                  ) : null}
                  {field.type === "text" ? (
                    <Input
                      id={field.id}
                      ref={control.ref}
                      onBlur={control.onBlur}
                      aria-required={field.required}
                      aria-describedby={`${field.id}-help ${field.id}-error`}
                      value={textValue(control.value)}
                      disabled={pending || blocked}
                      aria-invalid={fieldState.invalid || undefined}
                      onChange={(event) => control.onChange(event.target.value)}
                    />
                  ) : null}
                  {field.type === "number" ? (
                    <Input
                      id={field.id}
                      type="number"
                      ref={control.ref}
                      onBlur={control.onBlur}
                      aria-required={field.required}
                      aria-describedby={`${field.id}-help ${field.id}-error`}
                      inputMode="decimal"
                      value={control.value === null || control.value === undefined ? "" : String(control.value)}
                      disabled={pending || blocked}
                      aria-invalid={fieldState.invalid || undefined}
                      onChange={(event) => control.onChange(numberValue(event.target.value))}
                    />
                  ) : null}
                  {field.type === "date" ? (
                    <Input
                      id={field.id}
                      type="date"
                      ref={control.ref}
                      onBlur={control.onBlur}
                      aria-required={field.required}
                      aria-describedby={`${field.id}-help ${field.id}-error`}
                      value={textValue(control.value)}
                      disabled={pending || blocked}
                      aria-invalid={fieldState.invalid || undefined}
                      onChange={(event) => control.onChange(event.target.value)}
                    />
                  ) : null}
                  {field.type === "select" ? (
                    <Select
                      value={control.value === null || control.value === "" ? emptyOption : String(control.value)}
                      disabled={pending || blocked}
                      onValueChange={(value) => control.onChange(value === emptyOption ? null : value)}
                    >
                      <SelectTrigger id={field.id} ref={control.ref} onBlur={control.onBlur} className="w-full" aria-required={field.required} aria-describedby={`${field.id}-help ${field.id}-error`} aria-invalid={fieldState.invalid || undefined}>
                        <SelectValue placeholder="Choose an option" />
                      </SelectTrigger>
                      <SelectContent>
                        {field.required ? null : <SelectItem value={emptyOption}>Not selected</SelectItem>}
                        {field.options.map((option) => (
                          <SelectItem key={option.value} value={option.value}>
                            {option.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  ) : null}
                  <div id={`${field.id}-error`}><FieldError errors={[{ message: fieldState.error?.message }]} /></div>
                </Field>
              )}
            />
          ),
        )}
      </FieldGroup>
      {banner ? (
        <p className="text-sm text-destructive" role="alert">
          {banner}
        </p>
      ) : null}
      <Button type="submit" className="h-10 w-fit" disabled={pending || blocked}>
        {pending ? "Submitting" : submitLabel}
      </Button>
      <AlertDialog open={review !== null} onOpenChange={(open) => { if (!open && !pending) setReview(null); }}>
        <AlertDialogContent className="max-h-[85dvh] overflow-y-auto">
          <AlertDialogHeader><AlertDialogTitle>{confirmTitle}</AlertDialogTitle><AlertDialogDescription>{confirmDescription ?? "Review your answers before continuing."}</AlertDialogDescription></AlertDialogHeader>
          <ReadOnlyFields definition={definition} data={review} />
          <AlertDialogFooter><AlertDialogCancel disabled={pending}>Keep editing</AlertDialogCancel><Button type="button" disabled={pending || blocked || !review} onClick={() => { if (review) void send(review); }}>{pending ? "Submitting…" : "Confirm submission"}</Button></AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </form>
  );
}

export function ReadOnlyFields({ definition, data }: { definition: FormDefinition | null | undefined; data: Record<string, unknown> | null | undefined }) {
  const fields = definition?.fields ?? [];

  if (fields.length === 0) {
    return <p className="text-sm text-muted-foreground">This request has no submitted fields.</p>;
  }

  return (
    <dl className="flex flex-col gap-4">
      {fields.map((field) => (
        <div key={field.id} className="flex flex-col gap-1">
          <dt className="text-sm text-muted-foreground">{field.label}</dt>
          <dd className="break-words whitespace-pre-wrap text-sm">
            {field.type === "file" ? "File attachments cannot be submitted yet." : displayField(field, data?.[field.id])}
          </dd>
        </div>
      ))}
    </dl>
  );
}

function displayField(field: FormField, value: unknown): string {
  if (field.type === "checkbox") {
    return value === true ? "Yes" : "No";
  }

  if (value === null || value === undefined || value === "") {
    return "Not provided";
  }

  if (field.type === "select") {
    return field.options.find((option) => option.value === value)?.label ?? String(value);
  }

  return String(value);
}

function textValue(value: unknown): string {
  return typeof value === "string" ? value : "";
}

function numberValue(value: string): number | null | string {
  if (value.trim() === "") {
    return null;
  }

  const parsed = Number(value);

  return Number.isFinite(parsed) ? parsed : value;
}
