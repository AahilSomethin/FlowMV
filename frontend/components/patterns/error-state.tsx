import type { ReactNode } from "react";
import { ApiError } from "@/lib/api";

export function describeApiError(error: unknown, fallback = "Could not complete that request."): {
  title: string;
  description: string;
} {
  if (!(error instanceof ApiError)) {
    return { title: "Something went wrong", description: fallback };
  }

  if (error.status === 0) {
    return {
      title: "Unable to connect",
      description: "Could not reach the FlowMV API. Your session was left unchanged.",
    };
  }

  if (error.status === 401) {
    return { title: "Sign in required", description: "Your session is no longer valid." };
  }

  if (error.status === 403) {
    return { title: "Permission denied", description: error.message || "You do not have access to this." };
  }

  if (error.status === 404) {
    return { title: "Not found", description: error.message || "That record is no longer available." };
  }

  if (error.status === 419) {
    return {
      title: "Security check failed",
      description: "The request was rejected before it ran. Try it again.",
    };
  }

  if (error.status >= 500) {
    return { title: "Something went wrong", description: "The server could not complete that request." };
  }

  return { title: "Something went wrong", description: error.message || fallback };
}

export function ErrorState({
  title,
  description,
  action,
}: {
  title: string;
  description: string;
  action?: ReactNode;
}) {
  return (
    <div className="flex flex-col items-start gap-3 rounded-xl bg-card px-4 py-6 ring-1 ring-destructive/30 sm:px-6" role="alert">
      <div className="flex max-w-xl flex-col gap-1">
        <h2 className="text-base font-medium text-destructive">{title}</h2>
        <p className="text-sm leading-6 text-muted-foreground text-pretty">{description}</p>
      </div>
      {action}
    </div>
  );
}
