"use client";

import { useEffect } from "react";
import { usePathname, useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { SidebarInset, SidebarProvider, SidebarTrigger, useSidebar } from "@/components/ui/sidebar";
import { TooltipProvider } from "@/components/ui/tooltip";
import { AccountMenu } from "@/components/shell/account-menu";
import { AppBreadcrumbs } from "@/components/shell/app-breadcrumbs";
import { AppSidebar } from "@/components/shell/app-sidebar";
import { WorkspaceSwitcher } from "@/components/shell/workspace-switcher";
import { WorkspaceTransition, useWorkspaceTransition } from "@/components/shell/workspace-transition";
import { ErrorState } from "@/components/patterns/error-state";
import { LoadingState } from "@/components/patterns/loading-state";
import { useAuth } from "@/lib/auth";

function ShellSidebarTrigger() {
  const { open, openMobile, isMobile } = useSidebar();

  return <SidebarTrigger className="size-10" aria-expanded={isMobile ? openMobile : open} />;
}

function ShellFrame({ children }: { children: React.ReactNode }) {
  const { user } = useAuth();
  const { pending } = useWorkspaceTransition();
  const organizationKey = user?.current_organization?.uuid ?? "none";

  return (
    <TooltipProvider>
      <SidebarProvider>
        <AppSidebar />
        <SidebarInset id="main" className="min-h-svh min-w-0">
        <a
          href="#content"
          className="sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-50 focus:rounded-lg focus:bg-background focus:px-3 focus:py-2 focus:text-sm focus:ring-3 focus:ring-ring"
        >
          Skip to content
        </a>
        <header className="sticky top-0 z-20 flex min-h-14 shrink-0 flex-wrap items-center gap-2 border-b bg-background px-3 py-2 md:px-4">
          <ShellSidebarTrigger />
          <Separator orientation="vertical" className="hidden h-5 sm:block" />
          <AppBreadcrumbs />
          <div className="ml-auto flex min-w-0 items-center gap-2">
            <WorkspaceSwitcher />
            <AccountMenu />
          </div>
        </header>
        <div id="content" className="flex min-h-0 flex-1 flex-col">
          <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 md:p-6">
            {pending ? (
              <LoadingState label="Switching workspace" />
            ) : (
              <div key={organizationKey} className="flex flex-col gap-6">
                {children}
              </div>
            )}
          </div>
        </div>
      </SidebarInset>
      </SidebarProvider>
    </TooltipProvider>
  );
}

export function AppShell({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const { user, status, refresh } = useAuth();

  useEffect(() => {
    if (status === "guest") {
      router.replace(`/login?next=${encodeURIComponent(pathname)}`);
    }

    if (status === "authenticated" && user && user.organizations.length === 0) {
      router.replace("/onboarding");
    }
  }, [pathname, router, status, user]);

  if (status === "unreachable") {
    return (
      <>
        <main className="flex flex-1 items-center justify-center p-4">
          <div className="w-full max-w-md">
            <ErrorState
              title="Unable to load your account"
              description="FlowMV could not reach your session. This does not sign you out."
              action={
                <Button type="button" className="h-10" onClick={() => void refresh().catch(() => undefined)}>
                  Try again
                </Button>
              }
            />
          </div>
        </main>
        <div hidden>{children}</div>
      </>
    );
  }

  if (status !== "authenticated" || !user || user.organizations.length === 0) {
    return (
      <>
        <main className="flex flex-1 items-center justify-center p-4">
          <p className="text-sm text-muted-foreground">Checking your session</p>
        </main>
        <div hidden>{children}</div>
      </>
    );
  }

  return (
    <WorkspaceTransition>
      <ShellFrame>{children}</ShellFrame>
    </WorkspaceTransition>
  );
}
