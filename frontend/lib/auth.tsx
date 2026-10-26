"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from "react";
import { api, ApiError, setUnauthorizedHandler, type User } from "@/lib/api";

type AuthStatus = "loading" | "authenticated" | "guest" | "unreachable";

type RegisterInput = {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
};

type AuthContextValue = {
  user: User | null;
  status: AuthStatus;
  login: (input: { email: string; password: string }) => Promise<void>;
  register: (input: RegisterInput) => Promise<void>;
  logout: () => Promise<void>;
  refresh: () => Promise<User>;
  applySession: (current: User) => void;
};

function normalizeUser(current: User): User {
  return {
    ...current,
    current_organization: current.current_organization ?? null,
    organizations: current.organizations ?? [],
  };
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [status, setStatus] = useState<AuthStatus>("loading");

  const markGuest = useCallback(() => {
    setUser(null);
    setStatus("guest");
  }, []);

  useEffect(() => {
    setUnauthorizedHandler(markGuest);

    return () => setUnauthorizedHandler(null);
  }, [markGuest]);

  useEffect(() => {
    let active = true;

    api<User>("/api/v1/me")
      .then((current) => {
        if (!active) {
          return;
        }

        setUser(normalizeUser(current));
        setStatus("authenticated");
      })
      .catch((error: unknown) => {
        if (!active) {
          return;
        }

        if (error instanceof ApiError && error.status === 401) {
          markGuest();
          return;
        }

        setStatus("unreachable");
      });

    return () => {
      active = false;
    };
  }, [markGuest]);

  const login = useCallback(async (input: { email: string; password: string }) => {
    const current = await api<User>("/api/v1/auth/login", {
      method: "POST",
      body: JSON.stringify(input),
    });

    setUser(normalizeUser(current));
    setStatus("authenticated");
  }, []);

  const register = useCallback(async (input: RegisterInput) => {
    const current = await api<User>("/api/v1/auth/register", {
      method: "POST",
      body: JSON.stringify(input),
    });

    setUser(normalizeUser(current));
    setStatus("authenticated");
  }, []);

  const applySession = useCallback((current: User) => {
    setUser(normalizeUser(current));
    setStatus("authenticated");
  }, []);

  const refresh = useCallback(async () => {
    try {
      const current = normalizeUser(await api<User>("/api/v1/me"));
      setUser(current);
      setStatus("authenticated");

      return current;
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        markGuest();
      } else {
        setStatus((current) => (current === "authenticated" ? current : "unreachable"));
      }

      throw error;
    }
  }, [markGuest]);

  const logout = useCallback(async () => {
    try {
      await api<void>("/api/v1/auth/logout", { method: "POST" });
      markGuest();
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        markGuest();
        return;
      }

      throw error;
    }
  }, [markGuest]);

  useEffect(() => {
    if (status !== "authenticated") {
      return;
    }

    let lastRefresh = Date.now();

    function onVisibility() {
      if (document.visibilityState !== "visible" || Date.now() - lastRefresh < 60_000) {
        return;
      }

      lastRefresh = Date.now();
      void refresh().catch(() => undefined);
    }

    document.addEventListener("visibilitychange", onVisibility);

    return () => document.removeEventListener("visibilitychange", onVisibility);
  }, [refresh, status]);

  const value = useMemo(
    () => ({ user, status, login, register, logout, refresh, applySession }),
    [user, status, login, register, logout, refresh, applySession],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const value = useContext(AuthContext);

  if (!value) {
    throw new Error("useAuth must be used within AuthProvider.");
  }

  return value;
}
