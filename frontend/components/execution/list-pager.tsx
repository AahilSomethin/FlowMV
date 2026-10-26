import { Button } from "@/components/ui/button";

export function ListPager({
  page,
  lastPage,
  total,
  label,
  onPage,
}: {
  page: number;
  lastPage: number;
  total: number;
  label: string;
  onPage: (page: number) => void;
}) {
  if (lastPage <= 1) {
    return null;
  }

  return (
    <div className="flex flex-wrap items-center justify-between gap-2">
      <p className="text-sm text-muted-foreground">
        {total} {label}
      </p>
      <div className="flex gap-2">
        <Button type="button" variant="outline" className="h-10" disabled={page <= 1} onClick={() => onPage(page - 1)}>
          Previous
        </Button>
        <Button type="button" variant="outline" className="h-10" disabled={page >= lastPage} onClick={() => onPage(page + 1)}>
          Next
        </Button>
      </div>
    </div>
  );
}
