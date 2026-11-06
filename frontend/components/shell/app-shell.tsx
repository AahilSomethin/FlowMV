"use client";

import { useEffect } from "react";
import { usePathname, useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { SidebarInset, SidebarProvider, SidebarTrigger, useSidebar } from "@/components/ui/sidebar";
import { AccountMenu } from "@/components/shell/account-menu";
import { NotificationsMenu } from "@/components/shell/notifications-menu";
import { AppBreadcrumbs } from "@/components/shell/app-breadcrumbs";
import { AppSidebar } from "@/components/shell/app-sidebar";
import { DemoNotice } from "@/components/shell/demo-notice";
import { WorkspaceSwitcher } from "@/components/shell/workspace-switcher";
import { WorkspaceTransition, useWorkspaceTransition } from "@/components/shell/workspace-transition";
import { ErrorState } from "@/components/patterns/error-state";
import { LoadingState } from "@/components/patterns/loading-state";
import { useAuth } from "@/lib/auth";
import { IntentPrefetchProvider } from "@/components/shell/intent-link";

function ShellSidebarTrigger() {
  const { open, openMobile, isMobile } = useSidebar();

  return <SidebarTrigger className="size-10" aria-expanded={isMobile ? openMobile : open} />;
}

function ShellRouteSync() {
  const pathname = usePathname();
  const { setOpenMobile } = useSidebar();

  useEffect(() => {
    setOpenMobile(false);
    const content = document.getElementById("content");
    if (content instanceof HTMLElement && document.activeElement && !content.contains(document.activeElement)) {
      content.focus({ preventScroll: true });
    }
  }, [pathname, setOpenMobile]);

  return null;
}

function ShellFrame({ children }: { children: React.ReactNode }) {
  const { user } = useAuth();
  const { pending } = useWorkspaceTransition();
  const pathname = usePathname();
  const canvas = /^\/workflows\/[^/]+\/edit$/.test(pathname);
  const wide = /^\/(analytics|requests|team|connections|tasks|workflows|dashboard|settings)(\/|$)/.test(pathname) && pathname !== "/requests/new";
  const organizationKey = user?.current_organization?.uuid ?? "none";
  const accessKey = `${user?.id}:${organizationKey}:${user?.current_organization?.role}`;

  return (
    <IntentPrefetchProvider scope={accessKey} pathname={pathname} role={user?.current_organization?.role}>
      <SidebarProvider key={canvas ? "studio" : "workspace"} defaultOpen={!canvas}>
        <ShellRouteSync />
        <AppSidebar />
        <SidebarInset
          id="main"
          className={canvas ? "flex h-dvh max-h-dvh min-h-0 min-w-0 flex-col overflow-hidden" : "min-h-svh min-w-0"}
        >
        <a
          href="#content"
          className="sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-50 focus:rounded-lg focus:bg-background focus:px-3 focus:py-2 focus:text-sm focus:ring-3 focus:ring-ring"
        >
          Skip to content
        </a>
        <header className="sticky top-0 z-20 flex min-h-14 shrink-0 flex-wrap items-center gap-2 border-b bg-card/90 px-3 py-2 backdrop-blur-md md:px-4">
          <ShellSidebarTrigger />
          <Separator orientation="vertical" className="hidden h-5 sm:block" />
          <AppBreadcrumbs />
          <div className="ml-auto flex min-w-0 items-center gap-2">
            {organizationKey !== "none" ? <DemoNotice key={organizationKey} organizationId={organizationKey} /> : null}
            <WorkspaceSwitcher />
            {organizationKey !== "none" ? <NotificationsMenu key={organizationKey} organizationId={organizationKey} /> : null}
            <AccountMenu />
          </div>
        </header>
        <div id="content" tabIndex={-1} className={canvas ? "flex min-h-0 flex-1 flex-col overflow-hidden outline-none" : "flex min-h-0 flex-1 flex-col outline-none"}>
          {canvas ? (
            pending ? (
              <div className="p-4">
                <LoadingState label="Switching workspace" />
              </div>
            ) : (
              <div key={accessKey} className="flex min-h-0 flex-1 flex-col">
                {children}
              </div>
            )
          ) : (
            <div className={`mx-auto flex w-full flex-1 flex-col gap-6 p-4 md:p-6 lg:px-8 lg:py-6 ${wide ? "max-w-7xl" : "max-w-3xl"}`}>
              {pending ? (
                <LoadingState label="Switching workspace" />
              ) : (
                <div key={accessKey} className="flex flex-col gap-6">
                  {children}
                </div>
              )}
            </div>
          )}
        </div>
      </SidebarInset>
      </SidebarProvider>
    </IntentPrefetchProvider>
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
              description="Orqestra could not reach your session. This does not sign you out."
              action={
                <Button type="button" className="h-10" onClick={() => void refresh().catch(() => undefined)}>
                  Try again
                </Button>
              }
            />
          </div>
        </main>
      </>
    );
  }

  if (status !== "authenticated" || !user || user.organizations.length === 0) {
    return (
      <>
        <main className="flex flex-1 items-center justify-center p-4">
          <p role="status" className="text-sm text-muted-foreground">Checking your session</p>
        </main>
      </>
    );
  }

  return (
    <WorkspaceTransition>
      <ShellFrame>{children}</ShellFrame>
    </WorkspaceTransition>
  );
}
