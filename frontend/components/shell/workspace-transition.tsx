"use client";

import { createContext, useCallback, useContext, useMemo, useState } from "react";

type WorkspaceTransitionValue = {
  pending: boolean;
  runChange: (task: () => Promise<void>) => Promise<void>;
};

const WorkspaceTransitionContext = createContext<WorkspaceTransitionValue | null>(null);

export function WorkspaceTransition({ children }: { children: React.ReactNode }) {
  const [pending, setPending] = useState(false);

  const runChange = useCallback(async (task: () => Promise<void>) => {
    setPending(true);

    try {
      await task();
    } finally {
      setPending(false);
    }
  }, []);

  const value = useMemo(() => ({ pending, runChange }), [pending, runChange]);

  return <WorkspaceTransitionContext.Provider value={value}>{children}</WorkspaceTransitionContext.Provider>;
}

export function useWorkspaceTransition(): WorkspaceTransitionValue {
  const value = useContext(WorkspaceTransitionContext);

  if (!value) {
    throw new Error("useWorkspaceTransition must be used within WorkspaceTransition.");
  }

  return value;
}
