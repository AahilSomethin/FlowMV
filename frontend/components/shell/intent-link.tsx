"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  type ComponentProps,
  type ReactNode,
} from "react";
import type { OrganizationRole } from "@/lib/api";
import {
  canSpeculate,
  likelyDestination,
  PrefetchBudget,
} from "@/lib/prefetch";

const budget = new PrefetchBudget();
const IntentContext = createContext<((href: string) => void) | null>(null);
type Props = Omit<ComponentProps<typeof Link>, "href" | "prefetch"> & {
  href: string;
};

export function IntentPrefetchProvider({
  scope,
  pathname,
  role,
  children,
}: {
  scope: string;
  pathname: string;
  role?: OrganizationRole;
  children: ReactNode;
}) {
  const router = useRouter();
  useEffect(() => () => budget.clear(), [scope]);
  const prepare = useCallback(
    (href: string) => {
      const connection = (
        navigator as Navigator & {
          connection?: { saveData?: boolean; effectiveType?: string };
        }
      ).connection;
      if (
        canSpeculate(connection, navigator.onLine) &&
        budget.claim(scope, href)
      )
        router.prefetch(href);
    },
    [scope, router],
  );
  useEffect(() => {
    const likely = likelyDestination(pathname, role);
    if (!likely) return;
    const timer = setTimeout(() => prepare(likely), 700);
    return () => clearTimeout(timer);
  }, [pathname, role, prepare]);
  return (
    <IntentContext.Provider value={prepare}>{children}</IntentContext.Provider>
  );
}

export function IntentLink({
  href,
  onMouseEnter,
  onMouseLeave,
  onFocus,
  onBlur,
  ...props
}: Props) {
  const prepare = useContext(IntentContext);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const cancel = useCallback(() => {
    if (timer.current) clearTimeout(timer.current);
    timer.current = null;
  }, []);
  useEffect(() => cancel, [href, prepare, cancel]);
  return (
    <Link
      {...props}
      href={href}
      prefetch={false}
      onMouseEnter={(event) => {
        onMouseEnter?.(event);
        cancel();
        timer.current = setTimeout(() => prepare?.(href), 120);
      }}
      onMouseLeave={(event) => {
        onMouseLeave?.(event);
        cancel();
      }}
      onFocus={(event) => {
        onFocus?.(event);
        prepare?.(href);
      }}
      onBlur={(event) => {
        onBlur?.(event);
        cancel();
      }}
    />
  );
}
