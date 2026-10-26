"use client";

import Link from "next/link";
import { BarChart3, GitBranch, Inbox, ListTodo } from "lucide-react";
import { EmptyState } from "@/components/patterns/empty-state";
import { DetailItem, DetailSection, PageHeader } from "@/components/patterns/page-header";
import { RoleBadge, StatusBadge } from "@/components/patterns/status-badge";
import { Button } from "@/components/ui/button";
import { useAuth } from "@/lib/auth";

const unavailable = [
  {
    href: "/tasks",
    label: "My Tasks",
    icon: ListTodo,
    detail: "Assigned work will show up here after the task API exists.",
  },
  {
    href: "/requests",
    label: "Requests",
    icon: Inbox,
    detail: "Incoming requests are not stored yet.",
  },
  {
    href: "/workflows",
    label: "Workflows",
    icon: GitBranch,
    detail: "Workflow definitions are not stored yet.",
  },
  {
    href: "/analytics",
    label: "Analytics",
    icon: BarChart3,
    detail: "There is no reporting data to chart.",
  },
] as const;

export function DashboardScreen() {
  const { user } = useAuth();
  const current = user?.current_organization ?? null;

  if (!user) {
    return null;
  }

  return (
    <>
      <PageHeader
        title={`Welcome, ${user.name}`}
        description="This overview uses your account and the workspace selected on the server."
      />
      {current ? (
        <div className="grid gap-4 lg:grid-cols-2">
          <DetailSection title="Workspace" description="Loaded from your current organization.">
            <DetailItem label="Name">{current.name}</DetailItem>
            <DetailItem label="Slug">{current.slug}</DetailItem>
            <DetailItem label="Your role">
              <RoleBadge role={current.role} />
            </DetailItem>
          </DetailSection>
          <DetailSection title="Account" description="This is the signed-in user for this browser session.">
            <DetailItem label="Name">{user.name}</DetailItem>
            <DetailItem label="Email">{user.email}</DetailItem>
            <DetailItem label="Email status">
              <StatusBadge>{user.email_verified_at ? "Verified" : "Not verified"}</StatusBadge>
            </DetailItem>
            <DetailItem label="Memberships">{user.organizations.length}</DetailItem>
          </DetailSection>
        </div>
      ) : (
        <EmptyState
          title="Choose a workspace"
          description="Select an organization from the workspace menu. FlowMV will replace this page with that organization's details."
        />
      )}
      <section className="flex flex-col gap-3">
        <div className="flex flex-col gap-1">
          <h2 className="text-base font-medium">Not connected yet</h2>
          <p className="text-sm leading-6 text-muted-foreground">
            These modules are part of the shell. They do not show counts, durations, or sample records.
          </p>
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          {unavailable.map((item) => {
            const Icon = item.icon;

            return (
              <div key={item.href} className="flex flex-col gap-3 rounded-xl bg-card px-4 py-4 ring-1 ring-foreground/10">
                <div className="flex items-center justify-between gap-3">
                  <div className="flex min-w-0 items-center gap-2">
                    <Icon className="size-4 text-muted-foreground" aria-hidden="true" />
                    <h3 className="truncate text-sm font-medium">{item.label}</h3>
                  </div>
                  <StatusBadge>Not available</StatusBadge>
                </div>
                <p className="text-sm leading-6 text-muted-foreground">{item.detail}</p>
                <Button variant="outline" className="h-10 w-fit" asChild>
                  <Link href={item.href}>Open {item.label}</Link>
                </Button>
              </div>
            );
          })}
        </div>
      </section>
    </>
  );
}
