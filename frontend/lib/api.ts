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

type ErrorBody = {
  message?: string;
  errors?: Record<string, string[]>;
};

export class ApiError extends Error {
  readonly status: number;
  readonly errors: Record<string, string[]>;

  constructor(message: string, status: number, errors: Record<string, string[]> = {}) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.errors = errors;
  }
}

function apiOrigin(): string {
  return (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000").replace(/\/$/, "");
}

function readXsrfToken(): string | null {
  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

  return match?.[1] ? decodeURIComponent(match[1]) : null;
}

async function ensureCsrfCookie(): Promise<void> {
  const response = await fetch(`${apiOrigin()}/sanctum/csrf-cookie`, {
    credentials: "include",
    headers: { Accept: "application/json" },
  });

  if (!response.ok) {
    throw new ApiError("Could not start a secure session.", response.status);
  }
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const method = (init.method ?? "GET").toUpperCase();
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");

  if (init.body !== undefined && !headers.has("Content-Type")) {
    headers.set("Content-Type", "application/json");
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
      ...init,
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

  const body = (await response.json().catch(() => ({}))) as ErrorBody & { data?: T };

  if (!response.ok) {
    throw new ApiError(body.message ?? "Request failed.", response.status, body.errors ?? {});
  }

  return (body.data ?? body) as T;
}
