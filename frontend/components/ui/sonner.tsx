"use client";

import { Toaster as Sonner, type ToasterProps } from "sonner";
import { useTheme } from "@/components/theme/theme-provider";

export function Toaster(props: ToasterProps) {
  const { theme } = useTheme();

  return (
    <Sonner
      theme={theme === "system" ? "system" : theme}
      className="toaster group"
      toastOptions={{
        classNames: {
          toast: "bg-popover text-popover-foreground border-border shadow-lg",
          description: "text-muted-foreground",
        },
      }}
      {...props}
    />
  );
}
