"use client";

import type { ComponentProps } from "react";
import { Controller, type Control, type FieldPath, type FieldValues } from "react-hook-form";
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";

type TextFieldProps<T extends FieldValues> = {
  control: Control<T>;
  name: FieldPath<T>;
  label: string;
} & Omit<ComponentProps<typeof Input>, "name" | "id">;

export function TextField<T extends FieldValues>({
  control,
  name,
  label,
  ...inputProps
}: TextFieldProps<T>) {
  const fieldId = String(name);

  return (
    <Controller
      name={name}
      control={control}
      render={({ field, fieldState }) => (
        <Field data-invalid={fieldState.invalid || undefined}>
          <FieldLabel htmlFor={fieldId}>{label}</FieldLabel>
          <Input
            {...field}
            {...inputProps}
            id={fieldId}
            className="h-10"
            aria-invalid={fieldState.invalid || undefined}
            aria-describedby={fieldState.invalid ? `${fieldId}-error` : undefined}
          />
          <FieldError id={`${fieldId}-error`} errors={[fieldState.error]} />
        </Field>
      )}
    />
  );
}
