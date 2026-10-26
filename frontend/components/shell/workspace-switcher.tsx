"use client";

import { useState } from "react";
import { Check, ChevronDown, Loader2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { useWorkspaceTransition } from "@/components/shell/workspace-transition";
import { api, ApiError, type User } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { roleLabel } from "@/lib/organizations";

export function WorkspaceSwitcher() {
  const { user, applySession } = useAuth();
  const { pending, runChange } = useWorkspaceTransition();
  const [error, setError] = useState<string | null>(null);
  const current = user?.current_organization ?? null;

  if (!user || user.organizations.length === 0) {
    return null;
  }

  async function choose(uuid: string) {
    if (pending || uuid === current?.uuid) {
      return;
    }

    setError(null);

    try {
      await runChange(async () => {
        const next = await api<User>(`/api/v1/organizations/${uuid}/switch`, { method: "POST" });
        applySession(next);
      });
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not switch workspace.");
    }
  }

  return (
    <div className="flex min-w-0 max-w-full flex-col gap-1">
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            type="button"
            variant="outline"
            className="h-10 max-w-36 sm:max-w-64"
            disabled={pending}
            aria-label="Switch workspace"
          >
            {pending ? <Loader2 className="animate-spin" aria-hidden="true" /> : null}
            <span className="truncate">{current?.name ?? "Choose workspace"}</span>
            <ChevronDown aria-hidden="true" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-72">
          {user.organizations.map((organization) => {
            const selected = organization.uuid === current?.uuid;

            return (
              <DropdownMenuItem
                key={organization.uuid}
                onSelect={() => {
                  void choose(organization.uuid);
                }}
              >
                <span className="min-w-0 flex-1 truncate">{organization.name}</span>
                <Badge variant="outline">{roleLabel(organization.role)}</Badge>
                {selected ? <Check aria-hidden="true" /> : null}
              </DropdownMenuItem>
            );
          })}
        </DropdownMenuContent>
      </DropdownMenu>
      {error ? (
        <p role="alert" className="max-w-64 text-sm text-destructive">
          {error}
        </p>
      ) : null}
    </div>
  );
}
