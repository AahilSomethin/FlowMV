import type { FieldPath, FieldValues, UseFormSetError } from "react-hook-form";
import { ApiError } from "@/lib/api";

export function applyServerErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
): string | null {
  if (!(error instanceof ApiError)) {
    return "Something went wrong. Try again.";
  }

  let mapped = false;

  for (const [key, messages] of Object.entries(error.errors)) {
    const message = messages[0];

    if (!message) {
      continue;
    }

    setError(key as FieldPath<T>, { message });
    mapped = true;
  }

  if (error.status === 429) {
    return "Too many attempts. Wait a minute and try again.";
  }

  if (error.status === 419) {
    return "Your session expired. Refresh the page and try again.";
  }

  if (!mapped) {
    return error.message;
  }

  return null;
}
