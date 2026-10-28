"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  BarChart3,
  GitBranch,
  Inbox,
  LayoutDashboard,
  ListTodo,
  Settings,
  Users,
  Workflow,
  Plug,
} from "lucide-react";
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarRail,
  useSidebar,
} from "@/components/ui/sidebar";
import { WORKSPACE_NAV, type WorkspaceHref } from "@/lib/navigation";
import { useAuth } from "@/lib/auth";
import { roleLabel } from "@/lib/organizations";

const descriptions: Record<WorkspaceHref, string> = {
  "/connections": "Control connected services and workflow access",
  "/dashboard": "Your workspace at a glance", "/requests": "Start and track submissions", "/tasks": "Things requiring your action", "/workflows": "Design and manage processes", "/analytics": "Understand performance", "/team": "People and roles", "/settings": "Account and appearance",
};

const icons: Record<WorkspaceHref, typeof LayoutDashboard> = {
  "/connections": Plug,
  "/dashboard": LayoutDashboard,
  "/tasks": ListTodo,
  "/requests": Inbox,
  "/workflows": GitBranch,
  "/analytics": BarChart3,
  "/team": Users,
  "/settings": Settings,
};

export function AppSidebar() {
  const pathname = usePathname();
  const { setOpenMobile } = useSidebar();
  const { user } = useAuth();
  const current = user?.current_organization ?? null;

  return (
    <Sidebar collapsible="icon" id="app-sidebar">
      <SidebarHeader>
        <SidebarMenu>
          <SidebarMenuItem>
            <SidebarMenuButton size="lg" asChild tooltip="FlowMV">
              <Link href="/dashboard" aria-label="FlowMV overview" onClick={() => setOpenMobile(false)}>
                <span className="flex size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
                  <Workflow className="size-4" aria-hidden="true" />
                </span>
                <span className="group-data-[collapsible=icon]:hidden"><span className="block truncate text-base font-semibold tracking-tight">FlowMV</span><span className="block text-[10px] text-muted-foreground">Work, in flow.</span></span>
              </Link>
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarHeader>
      <SidebarContent>
        <SidebarGroup>
          <SidebarGroupLabel>Your workspace</SidebarGroupLabel>
          <SidebarGroupContent>
            <SidebarMenu>
              {WORKSPACE_NAV.map((item) => {
                const Icon = icons[item.href];
                const active = pathname === item.href || pathname.startsWith(`${item.href}/`);

                return (
                  <SidebarMenuItem key={item.href}>
                    <SidebarMenuButton asChild isActive={active} tooltip={descriptions[item.href]}>
                      <Link
                        href={item.href}
                        title={descriptions[item.href]}
                        aria-current={active ? "page" : undefined}
                        onClick={() => setOpenMobile(false)}
                      >
                        <Icon aria-hidden="true" />
                        <span>{item.label}</span>
                      </Link>
                    </SidebarMenuButton>
                  </SidebarMenuItem>
                );
              })}
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      </SidebarContent>
      <SidebarFooter>
        <div className="flex min-w-0 items-center gap-2 px-2 py-1 group-data-[collapsible=icon]:justify-center">
          <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-sidebar-accent text-sm font-medium text-sidebar-accent-foreground">
            {(current?.name ?? "F").slice(0, 1).toUpperCase()}
          </span>
          <div className="min-w-0 group-data-[collapsible=icon]:hidden">
            <p className="truncate text-sm font-medium">{current?.name ?? "No workspace"}</p>
            <p className="truncate text-xs text-muted-foreground">
              {current ? roleLabel(current.role) : "Choose a workspace"}
            </p>
          </div>
        </div>
      </SidebarFooter>
      <SidebarRail />
    </Sidebar>
  );
}
