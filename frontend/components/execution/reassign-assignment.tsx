"use client";

import { useEffect, useRef, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { IdempotencyKeys, stableJson } from "@/lib/execution";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";

export function ReassignAssignment({ organizationId, taskId, onUpdated }: { organizationId: string; taskId: string; onUpdated: () => void }) {
  const [open, setOpen] = useState(false);
  const [members, setMembers] = useState<Array<{ user_id: number; name: string; role: string }> | null>(null);
  const [member, setMember] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const keys = useRef(new IdempotencyKeys());
  useEffect(() => {
    if (!open) return;
    let active = true;
    void api<Array<{ user_id: number; name: string; role: string }>>(`/api/v1/organizations/${organizationId}/members`).then((result) => {
      if (active) { setMembers(result); setError(null); }
    }).catch(() => { if (active) setError("Could not load members. Close and reopen to retry."); });
    return () => { active = false; };
  }, [open, organizationId]);
  async function reassign() {
    const path = `/api/v1/organizations/${organizationId}/tasks/${taskId}/reassign`;
    const body = stableJson({ assignee_user_id: Number(member) });
    setPending(true);
    try {
      await api(path, { method: "POST", body, idempotencyKey: keys.current.for(`POST ${path} ${body}`) });
      setOpen(false); onUpdated();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not reach FlowMV. Retry with the same selection.");
      if (caught instanceof ApiError && caught.status === 409) onUpdated();
    } finally { setPending(false); }
  }
  return <Dialog open={open} onOpenChange={(next) => { if (!pending) setOpen(next); }}>
    <DialogTrigger asChild><Button variant="outline">Reassign task</Button></DialogTrigger>
    <DialogContent><DialogHeader><DialogTitle>Reassign task</DialogTitle><DialogDescription>Choose an active workspace member. FlowMV checks eligibility before saving.</DialogDescription></DialogHeader>
      {error ? <p role="alert">{error}</p> : null}
      {!members ? <p role="status">Loading members…</p> : <Select value={member} onValueChange={setMember}><SelectTrigger aria-label="New assignee"><SelectValue placeholder="Select a member" /></SelectTrigger><SelectContent>{members.map((item) => <SelectItem key={item.user_id} value={String(item.user_id)}>{item.name} · {item.role}</SelectItem>)}</SelectContent></Select>}
      <Button disabled={!member || pending} onClick={() => void reassign()}>{pending ? "Reassigning…" : "Confirm reassignment"}</Button>
    </DialogContent>
  </Dialog>;
}
