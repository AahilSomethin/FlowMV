"use client";

import { useEffect, useId, useState } from "react";
import { FlaskConical } from "lucide-react";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { api } from "@/lib/api";

const simulated = ["Email", "Slack", "Telegram", "SMS", "Document scanning", "AI provider transport"];

export function DemoNotice({ organizationId }: { organizationId: string }) {
  const descriptionId = useId();
  const [enabled, setEnabled] = useState(false);

  useEffect(() => {
    let active = true;
    void api<{ enabled: boolean; notice: string | null }>(`/api/v1/organizations/${organizationId}/demo-status`)
      .then((data) => {
        if (active) {
          setEnabled(data.enabled);
        }
      })
      .catch(() => undefined);

    return () => {
      active = false;
    };
  }, [organizationId]);

  if (!enabled) {
    return null;
  }

  return (
    <Popover>
      <PopoverTrigger
        className="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full bg-status-warning-soft px-2.5 text-xs font-medium text-status-warning outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
        aria-describedby={descriptionId}
      >
        <FlaskConical className="size-3.5" aria-hidden="true" />
        Demo
      </PopoverTrigger>
      <span id={descriptionId} className="sr-only">
        Demo environment. Requests and decisions are real records. Email, Slack, Telegram, SMS, document scanning, and AI provider transport are simulated and do not contact a live provider. Sign out to change roles.
      </span>
      <PopoverContent align="end" className="w-[min(20rem,calc(100vw-2rem))]">
        <div className="flex flex-col gap-2">
          <p className="font-medium text-foreground">Simulated integrations</p>
          <p className="text-sm leading-5 text-muted-foreground">
            Requests and decisions in this workspace are real records. These external integrations are simulated and do not contact a live provider:
          </p>
          <ul className="list-disc space-y-1 pl-4 text-sm text-foreground">
            {simulated.map((item) => (
              <li key={item}>{item}</li>
            ))}
          </ul>
          <p className="text-xs leading-5 text-muted-foreground">Sign out to change roles.</p>
        </div>
      </PopoverContent>
    </Popover>
  );
}
