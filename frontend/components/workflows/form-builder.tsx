"use client";

import { useEffect, useMemo, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm, useWatch } from "react-hook-form";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { fieldIdFromLabel } from "@/lib/workflows/definition";
import type { FieldType, FormField } from "@/lib/workflows/types";

const FIELD_TYPES: FieldType[] = ["text", "textarea", "number", "date", "select", "checkbox", "file"];

const schema = z.object({
  id: z.string().regex(/^[a-z][a-z0-9_]{0,63}$/, "Use a lowercase identifier that starts with a letter."),
  type: z.enum(["text", "textarea", "number", "date", "select", "checkbox", "file"]),
  label: z.string().trim().min(1, "Label is required.").max(120).refine((value) => !/[<>]/.test(value), "Labels cannot contain HTML."),
  description: z.string().max(2000).refine((value) => !/[<>]/.test(value), "Descriptions cannot contain HTML."),
  required: z.boolean(),
  defaultValue: z.string(),
  min: z.string(),
  max: z.string(),
  extensions: z.string(),
  maxSize: z.string(),
  options: z.string(),
});

type FieldValues = z.infer<typeof schema>;

const emptyFieldValues: FieldValues = {
  id: "",
  type: "text",
  label: "",
  description: "",
  required: false,
  defaultValue: "",
  min: "",
  max: "",
  extensions: "",
  maxSize: "",
  options: "",
};

export function FormBuilder({ fields, onChange }: { fields: FormField[]; onChange: (fields: FormField[]) => void }) {
  const [editing, setEditing] = useState<FormField | "new" | null>(null);

  return (
    <div className="flex flex-col gap-3">
      <div>
        <h2 className="text-sm font-medium">Submission form</h2>
        <p className="text-xs leading-5 text-muted-foreground">These fields are stored with the workflow. Submissions are not collected yet.</p>
      </div>
      {fields.length === 0 ? <p className="text-sm text-muted-foreground">No fields yet.</p> : null}
      <ul className="flex flex-col gap-2">
        {fields.map((field) => (
          <li key={field.id} className="flex items-start justify-between gap-2 rounded-lg bg-muted/50 px-2 py-2">
            <div className="min-w-0">
              <p className="truncate text-sm font-medium">{field.label}</p>
              <p className="truncate text-xs text-muted-foreground">
                {field.id} · {field.type}
                {field.required ? " · required" : ""}
              </p>
            </div>
            <div className="flex shrink-0 gap-1">
              <Button type="button" variant="ghost" size="sm" onClick={() => setEditing(field)}>
                Edit
              </Button>
              <Button type="button" variant="ghost" size="sm" onClick={() => onChange(fields.filter((item) => item.id !== field.id))}>
                Remove
              </Button>
            </div>
          </li>
        ))}
      </ul>
      <Button type="button" variant="outline" onClick={() => setEditing("new")}>
        Add field
      </Button>
      <FieldDialog
        open={editing !== null}
        field={editing && editing !== "new" ? editing : null}
        taken={fields.map((field) => field.id).filter((id) => id !== (editing && editing !== "new" ? editing.id : ""))}
        onOpenChange={(open) => {
          if (!open) {
            setEditing(null);
          }
        }}
        onSave={(field) => {
          if (editing && editing !== "new") {
            onChange(fields.map((item) => (item.id === editing.id ? field : item)));
          } else {
            onChange([...fields, field]);
          }

          setEditing(null);
        }}
      />
    </div>
  );
}

function FieldDialog({
  open,
  field,
  taken,
  onOpenChange,
  onSave,
}: {
  open: boolean;
  field: FormField | null;
  taken: string[];
  onOpenChange: (open: boolean) => void;
  onSave: (field: FormField) => void;
}) {
  const form = useForm<FieldValues>({
    resolver: zodResolver(schema),
    defaultValues: emptyFieldValues,
  });

  const resetValues = useMemo<FieldValues>(
    () =>
      field
        ? {
            id: field.id,
            type: field.type,
            label: field.label,
            description: field.description,
            required: field.required,
            defaultValue: field.default_value == null ? "" : String(field.default_value),
            min: stringValue(field.validation.min ?? field.validation.min_length),
            max: stringValue(field.validation.max ?? field.validation.max_length),
            extensions: Array.isArray(field.validation.extensions) ? field.validation.extensions.join(", ") : "",
            maxSize: stringValue(field.validation.max_size_mb),
            options: field.options.map((option) => `${option.value}|${option.label}`).join("\n"),
          }
        : emptyFieldValues,
    [field],
  );

  useEffect(() => {
    if (!open) {
      return;
    }

    form.reset(resetValues);
  }, [form, open, resetValues]);
  const type = useWatch({ control: form.control, name: "type" });
  const required = useWatch({ control: form.control, name: "required" });

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{field ? "Edit field" : "Add field"}</DialogTitle>
          <DialogDescription>Field identifiers stay stable so conditions can refer to them.</DialogDescription>
        </DialogHeader>
        <form
          className="flex flex-col gap-3"
          onSubmit={form.handleSubmit((values) => {
            if (taken.includes(values.id)) {
              form.setError("id", { message: "That identifier is already used." });
              return;
            }

            const built = toField(values);

            if (typeof built === "string") {
              form.setError("options", { message: built });
              return;
            }

            onSave(built);
          })}
        >
          <Field data-invalid={Boolean(form.formState.errors.label) || undefined}>
            <FieldLabel htmlFor="field-label">Label</FieldLabel>
            <Input
              id="field-label"
              {...form.register("label", {
                onBlur: (event) => {
                  if (!form.getValues("id")) {
                    form.setValue("id", fieldIdFromLabel(event.target.value, taken));
                  }
                },
              })}
            />
            <FieldError errors={[form.formState.errors.label]} />
          </Field>
          <Field data-invalid={Boolean(form.formState.errors.id) || undefined}>
            <FieldLabel htmlFor="field-id">Identifier</FieldLabel>
            <Input id="field-id" {...form.register("id")} />
            <FieldError errors={[form.formState.errors.id]} />
          </Field>
          <Field>
            <FieldLabel htmlFor="field-type">Type</FieldLabel>
            <Select value={type} onValueChange={(value) => form.setValue("type", value as FieldType)}>
              <SelectTrigger id="field-type" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {FIELD_TYPES.map((item) => (
                  <SelectItem key={item} value={item}>
                    {item}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </Field>
          <Field>
            <FieldLabel htmlFor="field-description">Description</FieldLabel>
            <Textarea id="field-description" {...form.register("description")} />
            <FieldError errors={[form.formState.errors.description]} />
          </Field>
          <label className="flex items-center justify-between gap-3 text-sm">
            Required
            <Switch checked={required} onCheckedChange={(checked) => form.setValue("required", checked === true)} />
          </label>
          {type !== "file" ? (
            <Field>
              <FieldLabel htmlFor="field-default">Default value</FieldLabel>
              <Input id="field-default" type={type === "number" ? "number" : type === "date" ? "date" : "text"} {...form.register("defaultValue")} />
            </Field>
          ) : null}
          {type === "text" || type === "textarea" || type === "number" || type === "date" ? (
            <div className="grid grid-cols-2 gap-2">
              <Field>
                <FieldLabel htmlFor="field-min">{type === "date" ? "Earliest" : "Minimum"}</FieldLabel>
                <Input id="field-min" type={type === "date" ? "date" : "number"} {...form.register("min")} />
              </Field>
              <Field>
                <FieldLabel htmlFor="field-max">{type === "date" ? "Latest" : "Maximum"}</FieldLabel>
                <Input id="field-max" type={type === "date" ? "date" : "number"} {...form.register("max")} />
              </Field>
            </div>
          ) : null}
          {type === "select" ? (
            <Field data-invalid={Boolean(form.formState.errors.options) || undefined}>
              <FieldLabel htmlFor="field-options">Options</FieldLabel>
              <Textarea id="field-options" placeholder={"low|Low\nhigh|High"} {...form.register("options")} />
              <FieldError errors={[form.formState.errors.options]} />
            </Field>
          ) : null}
          {type === "file" ? (
            <div className="grid gap-2">
              <Field>
                <FieldLabel htmlFor="field-extensions">Extensions</FieldLabel>
                <Input id="field-extensions" placeholder="pdf, png" {...form.register("extensions")} />
              </Field>
              <Field>
                <FieldLabel htmlFor="field-size">Maximum size (MB)</FieldLabel>
                <Input id="field-size" type="number" min={1} max={25} {...form.register("maxSize")} />
              </Field>
            </div>
          ) : null}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit">Save field</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function toField(values: FieldValues): FormField | string {
  const validation: FormField["validation"] = {};
  let defaultValue: FormField["default_value"] = values.defaultValue === "" ? null : values.defaultValue;

  if (values.type === "number") {
    if (values.min !== "") validation.min = Number(values.min);
    if (values.max !== "") validation.max = Number(values.max);
    defaultValue = values.defaultValue === "" ? null : Number(values.defaultValue);
  }

  if (values.type === "text" || values.type === "textarea") {
    if (values.min !== "") validation.min_length = Number(values.min);
    if (values.max !== "") validation.max_length = Number(values.max);
  }

  if (values.type === "date") {
    if (values.min !== "") validation.min = values.min;
    if (values.max !== "") validation.max = values.max;
  }

  if (values.type === "checkbox") {
    defaultValue = values.defaultValue === "" ? null : values.defaultValue === "true";
  }

  if (values.type === "file") {
    defaultValue = null;
    const extensions = values.extensions
      .split(",")
      .map((item) => item.trim().toLowerCase())
      .filter(Boolean);

    if (extensions.some((item) => !/^[a-z0-9]{1,8}$/.test(item))) {
      return "Extensions must be short lowercase letters or numbers.";
    }

    if (extensions.length > 0) validation.extensions = extensions;
    if (values.maxSize !== "") validation.max_size_mb = Number(values.maxSize);
  }

  const options =
    values.type === "select"
      ? values.options
          .split("\n")
          .map((line) => line.trim())
          .filter(Boolean)
          .map((line) => {
            const [value, ...label] = line.split("|");
            return { value: (value ?? "").trim(), label: (label.join("|") || value || "").trim() };
          })
      : [];

  if (values.type === "select" && options.some((option) => !option.value || !option.label || /[<>]/.test(option.value + option.label))) {
    return "Each option needs a value and a label, written as value|label.";
  }

  return {
    id: values.id,
    type: values.type,
    label: values.label.trim(),
    description: values.description.trim(),
    required: values.required,
    default_value: defaultValue,
    validation,
    options,
  };
}

function stringValue(value: string | number | string[] | undefined): string {
  return value == null || Array.isArray(value) ? "" : String(value);
}
