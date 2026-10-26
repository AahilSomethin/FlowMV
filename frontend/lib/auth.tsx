"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from "react";
import { api, ApiError, type User } from "@/lib/api";

type AuthStatus = "loading" | "authenticated" | "guest";

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
      .catch(() => {
        if (!active) {
          return;
        }

        setUser(null);
        setStatus("guest");
      });

    return () => {
      active = false;
    };
  }, []);

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

  const refresh = useCallback(async () => {
    const current = normalizeUser(await api<User>("/api/v1/me"));
    setUser(current);
    setStatus("authenticated");

    return current;
  }, []);

  const logout = useCallback(async () => {
    try {
      await api<void>("/api/v1/auth/logout", { method: "POST" });
    } catch (error) {
      if (!(error instanceof ApiError) || error.status !== 401) {
        throw error;
      }
    } finally {
      setUser(null);
      setStatus("guest");
    }
  }, []);

  useEffect(() => {
    if (status !== "authenticated") {
      return;
    }

    function onFocus() {
      api<User>("/api/v1/me")
        .then((current) => setUser(normalizeUser(current)))
        .catch(() => undefined);
    }

    window.addEventListener("focus", onFocus);

    return () => window.removeEventListener("focus", onFocus);
  }, [status]);

  const value = useMemo(
    () => ({ user, status, login, register, logout, refresh }),
    [user, status, login, register, logout, refresh],
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
