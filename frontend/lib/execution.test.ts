import { describe, expect, it } from "vitest";
import { buildSubmissionSchema, hasBlockingFileField, IdempotencyKeys, toSubmissionData } from "@/lib/execution";
import { safeNextPath } from "@/lib/navigation";
import type { FormDefinition } from "@/lib/workflows/types";

const definition: FormDefinition = {
  fields: [
    {
      id: "amount",
      type: "number",
      label: "Amount",
      description: "",
      required: true,
      default_value: null,
      validation: { min: 1, max: 100 },
      options: [],
    },
    {
      id: "agree",
      type: "checkbox",
      label: "Agree",
      description: "",
      required: true,
      default_value: null,
      validation: {},
      options: [],
    },
    {
      id: "note",
      type: "text",
      label: "Note",
      description: "",
      required: false,
      default_value: null,
      validation: { max_length: 20 },
      options: [],
    },
  ],
};

describe("execution forms", () => {
  it("keeps numbers and booleans as JSON types", () => {
    const schema = buildSubmissionSchema(definition);
    const parsed = schema.parse({ amount: 20, agree: true, note: "" });

    expect(parsed.amount).toBe(20);
    expect(parsed.agree).toBe(true);
    expect(toSubmissionData(definition, parsed)).toEqual({ amount: 20, agree: true, note: null });
    expect(schema.safeParse({ amount: "20", agree: true, note: null }).success).toBe(false);
    expect(schema.safeParse({ amount: 20, agree: false, note: null }).success).toBe(false);
  });

  it("blocks file fields instead of dropping them", () => {
    expect(
      hasBlockingFileField({
        fields: [
          {
            id: "receipt",
            type: "file",
            label: "Receipt",
            description: "",
            required: false,
            default_value: null,
            validation: {},
            options: [],
          },
        ],
      }),
    ).toBe(true);
  });

  it("reuses an idempotency key until the payload changes", () => {
    const keys = new IdempotencyKeys();
    const first = keys.for("POST /tasks/1 {\"decision\":\"approved\"}");
    const retry = keys.for("POST /tasks/1 {\"decision\":\"approved\"}");
    const changed = keys.for("POST /tasks/1 {\"decision\":\"rejected\"}");

    expect(retry).toBe(first);
    expect(changed).not.toBe(first);
  });

  it("allows request and task links after sign-in", () => {
    expect(safeNextPath("/requests/new")).toBe("/requests/new");
    expect(safeNextPath("/requests/11111111-1111-1111-1111-111111111111")).toBe(
      "/requests/11111111-1111-1111-1111-111111111111",
    );
    expect(safeNextPath("/tasks/11111111-1111-1111-1111-111111111111")).toBe(
      "/tasks/11111111-1111-1111-1111-111111111111",
    );
  });
});
