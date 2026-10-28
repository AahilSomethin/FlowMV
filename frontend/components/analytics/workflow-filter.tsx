"use client";

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { api } from "@/lib/api";

type Page = { items: Array<{ uuid: string; name: string }>; current_page: number; last_page: number };

export function WorkflowFilter({ organizationId, value, onChange, allowAll = true }: { organizationId: string; value: string; onChange: (value: string) => void; allowAll?: boolean }) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<{ key: string; data: Page } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [label, setLabel] = useState("Selected workflow");
  const [retry, setRetry] = useState(0);
  const key = `${organizationId}:${search}:${page}`;
  useEffect(() => {
    if (!open) return;
    let active = true;
    const timer = window.setTimeout(() => {
      void api<Page>(`/api/v1/organizations/${organizationId}/workflows?${new URLSearchParams({ search, page: String(page) })}`).then((data) => {
        if (active) { setResult({ key, data }); setError(null); }
      }).catch(() => { if (active) setError("Could not load workflows."); });
    }, 200);
    return () => { active = false; window.clearTimeout(timer); };
  }, [open, organizationId, search, page, key, retry]);
  const data = result?.key === key ? result.data : null;
  function choose(uuid: string, name: string) { setLabel(name); onChange(uuid); setOpen(false); }
  return <Dialog open={open} onOpenChange={setOpen}>
    <DialogTrigger asChild><Button variant="outline" className="h-10 w-full justify-start truncate sm:w-56" aria-label="Filter by workflow">{value === "all" ? "All workflows" : label}</Button></DialogTrigger>
    <DialogContent>
      <DialogHeader><DialogTitle>Filter by workflow</DialogTitle><DialogDescription>Search all workflows you can access.</DialogDescription></DialogHeader>
      <Input aria-label="Search workflows" value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} />
      {allowAll && <Button variant="outline" onClick={() => choose("all", "All workflows")}>All workflows</Button>}
      {error ? <div role="alert">{error}<Button variant="outline" onClick={() => setRetry((count) => count + 1)}>Try again</Button></div> : !data ? <p role="status">Loading workflows…</p> : <>
        <ul className="max-h-64 space-y-1 overflow-y-auto">{data.items.map((item) => <li key={item.uuid}><Button variant={value === item.uuid ? "secondary" : "ghost"} className="w-full justify-start whitespace-normal text-left" onClick={() => choose(item.uuid, item.name)}>{item.name}</Button></li>)}</ul>
        {data.items.length === 0 ? <p>No matching workflows.</p> : null}
        <div className="flex items-center justify-between gap-2"><Button variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button><span className="text-sm">Page {data.current_page} of {data.last_page}</span><Button variant="outline" disabled={page >= data.last_page} onClick={() => setPage(page + 1)}>Next</Button></div>
      </>}
    </DialogContent>
  </Dialog>;
}
