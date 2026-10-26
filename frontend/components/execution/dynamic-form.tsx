"use client";

import { useMemo } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { Controller, useForm } from "react-hook-form";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
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
  onSubmit,
}: {
  definition: FormDefinition;
  initialValues?: SubmissionValues;
  submitLabel: string;
  pending?: boolean;
  disabled?: boolean;
  banner?: string | null;
  onSubmit: (values: SubmissionValues) => Promise<void>;
}) {
  const schema = useMemo(() => buildSubmissionSchema(definition), [definition]);
  const blocked = hasBlockingFileField(definition) || disabled;
  const form = useForm<SubmissionValues>({
    resolver: zodResolver(schema),
    defaultValues: initialValues ?? defaultSubmissionValues(definition),
  });

  return (
    <form
      className="flex flex-col gap-5"
      onSubmit={form.handleSubmit(async (values) => {
        try {
          await onSubmit(values);
        } catch (error) {
          applyServerErrors(error, form.setError);
        }
      })}
      noValidate
    >
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
                        checked={control.value === true}
                        disabled={pending || blocked}
                        onCheckedChange={(checked) => control.onChange(checked === true)}
                      />
                      <span>{field.label}</span>
                    </FieldLabel>
                  ) : (
                    <FieldLabel htmlFor={field.id}>{field.label}</FieldLabel>
                  )}
                  {field.description ? <FieldDescription>{field.description}</FieldDescription> : null}
                  {field.type === "textarea" ? (
                    <Textarea
                      id={field.id}
                      value={textValue(control.value)}
                      disabled={pending || blocked}
                      aria-invalid={fieldState.invalid || undefined}
                      onChange={(event) => control.onChange(event.target.value)}
                    />
                  ) : null}
                  {field.type === "text" ? (
                    <Input
                      id={field.id}
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
                      <SelectTrigger id={field.id} className="w-full" aria-invalid={fieldState.invalid || undefined}>
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
                  <FieldError errors={[{ message: fieldState.error?.message }]} />
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
          <dd className="text-sm">
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
