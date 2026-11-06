import { Loader2 } from "lucide-react";
import { cn } from "cn";

export function Spinner({ className, label = "Working" }: { className?: string; label?: string }) {
  return <Loader2 className={cn("size-4 animate-spin", className)} aria-label={label} />;
}
