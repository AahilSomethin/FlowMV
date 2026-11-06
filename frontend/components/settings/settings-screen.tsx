"use client";

import { DetailItem, DetailSection, PageHeader } from "@/components/patterns/page-header";
import { RoleBadge, StatusBadge } from "@/components/patterns/status-badge";
import { Button } from "@/components/ui/button";
import { useTheme } from "@/components/theme/theme-provider";
import { useAuth } from "@/lib/auth";
import { themeOptions } from "@/lib/theme";

export function SettingsScreen() {
  const { user } = useAuth();
  const { theme, setTheme } = useTheme();
  const current = user?.current_organization ?? null;

  if (!user) {
    return null;
  }

  return (
    <>
      <PageHeader
        eyebrow="Account"
        title="Settings"
        description="Manage your account, workspace details, and appearance."
      />
      <div className="grid gap-4 lg:grid-cols-2">
        <DetailSection title="Account">
          <DetailItem label="Name">{user.name}</DetailItem>
          <DetailItem label="Email">{user.email}</DetailItem>
          <DetailItem label="Email status">
            <StatusBadge>{user.email_verified_at ? "Verified" : "Not verified"}</StatusBadge>
          </DetailItem>
        </DetailSection>
        <DetailSection title="Workspace" description="Your access in the current workspace.">
          {current ? (
            <>
              <DetailItem label="Name">{current.name}</DetailItem>
              <DetailItem label="Slug">{current.slug}</DetailItem>
              <DetailItem label="Role">
                <RoleBadge role={current.role} />
              </DetailItem>
            </>
          ) : (
            <DetailItem label="Workspace">No workspace selected.</DetailItem>
          )}
        </DetailSection>
      </div>
      <DetailSection title="Appearance" description="Saved in this browser and applied before the page is shown.">
        <DetailItem label="Theme">
          <div className="flex w-fit flex-wrap gap-1 rounded-lg bg-surface-subtle p-1 ring-1 ring-foreground/10" role="group" aria-label="Theme">
            {themeOptions.map((option) => (
              <Button
                key={option.value}
                type="button"
                variant={theme === option.value ? "secondary" : "outline"}
                className="h-10"
                aria-pressed={theme === option.value}
                onClick={() => setTheme(option.value)}
              >
                {option.label}
              </Button>
            ))}
          </div>
        </DetailItem>
        <DetailItem label="Not available">Password changes and notification preferences are not available.</DetailItem>
      </DetailSection>
    </>
  );
}
