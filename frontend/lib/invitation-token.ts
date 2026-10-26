const INVITE_KEY = "flowmv.invite";

export function stashInviteToken(): boolean {
  if (typeof window === "undefined") {
    return false;
  }

  const raw = window.location.hash.startsWith("#") ? window.location.hash.slice(1) : "";
  const fromHash = new URLSearchParams(raw).get("token");

  if (fromHash) {
    sessionStorage.setItem(INVITE_KEY, fromHash);
    window.history.replaceState(null, "", window.location.pathname);
  }

  const stored = sessionStorage.getItem(INVITE_KEY);

  return typeof stored === "string" && stored.length > 0;
}

export function readInviteToken(): string | null {
  if (typeof window === "undefined") {
    return null;
  }

  const token = sessionStorage.getItem(INVITE_KEY);

  return token && token.length > 0 ? token : null;
}

export function clearInviteToken(): void {
  if (typeof window === "undefined") {
    return;
  }

  sessionStorage.removeItem(INVITE_KEY);
}

export function scrubSecret(message: string, secret: string | null): string {
  if (!secret) {
    return message;
  }

  return message.split(secret).join("[redacted]");
}
