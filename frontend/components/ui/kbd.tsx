import { cn } from "cn";

export function Kbd({ className, ...props }: React.ComponentProps<"kbd">) {
  return (
    <kbd
      className={cn(
        "inline-flex h-5 min-w-5 items-center justify-center rounded border bg-surface-subtle px-1 font-sans text-[0.7rem] font-medium text-muted-foreground",
        className,
      )}
      {...props}
    />
  );
}
