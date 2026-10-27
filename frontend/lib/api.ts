export type OrganizationRole = "admin" | "manager" | "employee";

export type OrganizationSummary = {
  uuid: string;
  name: string;
  slug: string;
  role: OrganizationRole;
  created_at: string | null;
};

export type User = {
  id: number;
  name: string;
  email: string;
  email_verified_at: string | null;
  created_at: string | null;
  current_organization: OrganizationSummary | null;
  organizations: OrganizationSummary[];
};

export type ApiViolation = {
  code: string;
  message: string;
  node_id: string | null;
  edge_id: string | null;
  field_id: string | null;
};

type ErrorBody = {
  message?: string;
  errors?: Record<string, string[]>;
  violations?: ApiViolation[];
};

export class ApiError extends Error {
  readonly status: number;
  readonly errors: Record<string, string[]>;
  readonly violations: ApiViolation[];

  constructor(
    message: string,
    status: number,
    errors: Record<string, string[]> = {},
    violations: ApiViolation[] = [],
  ) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.errors = errors;
    this.violations = violations;
  }
}

export type ApiInit = RequestInit & {
  idempotencyKey?: string;
};

const RETRYABLE_METHODS = new Set(["GET", "HEAD", "OPTIONS", "PUT", "DELETE"]);

let unauthorizedHandler: (() => void) | null = null;

export function setUnauthorizedHandler(handler: (() => void) | null): void {
  unauthorizedHandler = handler;
}

function notifyUnauthorized(): void {
  unauthorizedHandler?.();
}

function apiOrigin(): string {
  return (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000").replace(/\/$/, "");
}

function readXsrfToken(): string | null {
  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

  return match?.[1] ? decodeURIComponent(match[1]) : null;
}

async function ensureCsrfCookie(): Promise<void> {
  let response: Response;

  try {
    response = await fetch(`${apiOrigin()}/sanctum/csrf-cookie`, {
      credentials: "include",
      signal: AbortSignal.timeout(30_000),
      headers: { Accept: "application/json" },
    });
  } catch {
    throw new ApiError("Could not reach the FlowMV API.", 0);
  }

  if (!response.ok) {
    throw new ApiError("Could not start a secure session.", response.status);
  }
}

function canRetryAfter419(method: string, idempotencyKey: string | undefined): boolean {
  return RETRYABLE_METHODS.has(method) || Boolean(idempotencyKey);
}

async function send<T>(path: string, init: ApiInit): Promise<T> {
  const { idempotencyKey, ...requestInit } = init;
  const method = (requestInit.method ?? "GET").toUpperCase();
  const headers = new Headers(requestInit.headers);
  headers.set("Accept", "application/json");

  if (requestInit.body !== undefined && !headers.has("Content-Type")) {
    headers.set("Content-Type", "application/json");
  }

  if (idempotencyKey) {
    headers.set("Idempotency-Key", idempotencyKey);
  }

  if (!["GET", "HEAD", "OPTIONS"].includes(method)) {
    await ensureCsrfCookie();
    const token = readXsrfToken();

    if (token) {
      headers.set("X-XSRF-TOKEN", token);
    }
  }

  let response: Response;

  try {
    response = await fetch(`${apiOrigin()}${path}`, {
      ...requestInit,
      signal: requestInit.signal ? AbortSignal.any([requestInit.signal, AbortSignal.timeout(30_000)]) : AbortSignal.timeout(30_000),
      method,
      headers,
      credentials: "include",
    });
  } catch {
    throw new ApiError("Could not reach the FlowMV API.", 0);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  const body = (await response.json().catch(() => {
    if (response.ok) throw new ApiError("The API response could not be read. Refresh and try again.", 0);
    return {};
  })) as ErrorBody & { data?: T };

  if (response.status === 401) {
    notifyUnauthorized();
    throw new ApiError(body.message ?? "Unauthenticated.", 401, body.errors ?? {}, body.violations ?? []);
  }

  if (!response.ok) {
    throw new ApiError(body.message ?? "Request failed.", response.status, body.errors ?? {}, body.violations ?? []);
  }

  return (body.data ?? body) as T;
}

async function confirmSession(): Promise<void> {
  try {
    await send("/api/v1/me", { method: "GET" });
  } catch (error) {
    if (error instanceof ApiError && (error.status === 401 || error.status === 0)) {
      return;
    }
  }
}

export async function api<T>(path: string, init: ApiInit = {}): Promise<T> {
  const method = (init.method ?? "GET").toUpperCase();
  const eligible = canRetryAfter419(method, init.idempotencyKey);

  try {
    return await send<T>(path, init);
  } catch (error) {
    if (!(error instanceof ApiError) || error.status !== 419) {
      throw error;
    }

    if (eligible) {
      await ensureCsrfCookie();

      return send<T>(path, init);
    }

    if (path !== "/api/v1/me") {
      await confirmSession();
    }

    throw error;
  }
}
