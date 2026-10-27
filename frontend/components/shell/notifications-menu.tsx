"use client";

import { useEffect, useState } from "react";
import { Bell } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { api } from "@/lib/api";

type Notice = { id: string; read_at: string | null; data: { subject?: string; body?: string } };
type NoticePage = { items: Notice[]; current_page: number; last_page: number; total: number };

export function NotificationsMenu({ organizationId }: { organizationId: string }) {
  const [open, setOpen] = useState(false);
  const [result, setResult] = useState<{ key: string; data: NoticePage } | null>(null);
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<"all" | "unread" | "read">("all");
  const [error, setError] = useState<string | null>(null);
  const [reading, setReading] = useState<string | null>(null);
  const [reload, setReload] = useState(0);
  const path = `/api/v1/organizations/${organizationId}/notifications`;
  const requestKey = `${path}?${new URLSearchParams({ status, page: String(page) })}`;
  const data = result?.key === requestKey ? result.data : null;
  const items = data?.items;

  useEffect(() => {
    if (!open) return;
    let active = true;
    void api<NoticePage>(requestKey).then((data) => {
      if (active) { setResult({ key: requestKey, data }); setError(null); }
    }).catch(() => { if (active) setError("Could not load workspace notifications."); });
    return () => { active = false; };
  }, [open, requestKey, reload]);

  async function markRead(id: string) {
    setReading(id);
    try {
      await api(`${path}/${id}/read`, { method: "POST" });
      setResult((current) => current ? { ...current, data: { ...current.data, items: current.data.items.map((item) => item.id === id ? { ...item, read_at: new Date().toISOString() } : item) } } : null);
      if (status === "unread") { setPage(1); setReload((value) => value + 1); }
      setError(null);
    } catch { setError("Could not mark the notification as read. Try again."); }
    finally { setReading(null); }
  }

  return <Dialog open={open} onOpenChange={setOpen}>
    <DialogTrigger asChild><Button variant="ghost" size="icon" aria-label="Workspace notifications"><Bell /></Button></DialogTrigger>
    <DialogContent className="max-h-[85dvh] overflow-y-auto">
      <DialogHeader><DialogTitle>Workspace notifications</DialogTitle><DialogDescription>Notifications sent to you in this workspace.</DialogDescription></DialogHeader>
      <div className="flex gap-2" aria-label="Notification status">{(["all", "unread", "read"] as const).map((choice) => <Button key={choice} variant={status === choice ? "secondary" : "outline"} aria-pressed={status === choice} disabled={reading !== null} onClick={() => { setStatus(choice); setPage(1); setError(null); }}>{choice === "all" ? "All" : choice === "unread" ? "Unread" : "Read"}</Button>)}</div>
      {error ? <div role="alert" className="space-y-2"><p>{error}</p><Button variant="outline" onClick={() => setReload((value) => value + 1)}>Try again</Button></div> : null}
      {!items && !error ? <p role="status">Loading notifications…</p> : null}
      {items?.length === 0 ? <p className="text-sm text-muted-foreground">{status === "all" ? "No notifications yet." : `No ${status} notifications.`}</p> : null}
      <ul className="space-y-3">{items?.map((item) => <li key={item.id} className="space-y-2 rounded-lg border p-3">
        <p className="font-medium">{item.data.subject ?? "Workflow notification"}</p>
        <p className="whitespace-pre-wrap text-sm">{item.data.body}</p>
        {item.read_at ? <p className="text-xs text-muted-foreground">Read</p> : <Button variant="outline" size="sm" disabled={reading !== null} onClick={() => void markRead(item.id)}>Mark as read</Button>}
      </li>)}</ul>
      {data ? <div className="flex items-center justify-between gap-2"><Button variant="outline" disabled={page <= 1 || reading !== null} onClick={() => setPage(page - 1)}>Previous</Button><span className="text-sm">Page {data.current_page} of {data.last_page}</span><Button variant="outline" disabled={page >= data.last_page || reading !== null} onClick={() => setPage(page + 1)}>Next</Button></div> : null}
    </DialogContent>
  </Dialog>;
}
