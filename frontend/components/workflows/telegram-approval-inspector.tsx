"use client";
import {useEffect,useState} from "react";
import { IntentLink as Link } from "@/components/shell/intent-link";
import {api} from "@/lib/api";
import {Label} from "@/components/ui/label";
import {Button} from "@/components/ui/button";
import type {TelegramConnection} from "@/components/connections/telegram-screen";
import type {WorkflowNodeData} from "@/lib/workflows/types";

export function TelegramApprovalInspector({data,organizationId,onChange}:{data:WorkflowNodeData;organizationId?:string|null;onChange:(patch:Partial<WorkflowNodeData>)=>void}){
  const [result,setResult]=useState<{org:string;items:TelegramConnection[]}|null>(null);
  useEffect(()=>{let active=true;if(organizationId)void api<{items:TelegramConnection[]}>(`/api/v1/organizations/${organizationId}/integrations/telegram`).then(r=>{if(active)setResult({org:organizationId,items:r.items});}).catch(()=>{if(active)setResult({org:organizationId,items:[]});});return()=>{active=false;};},[organizationId]);
  const items=result?.org===organizationId?result?.items??[]:[];const config=data.telegram_approval;const selected=items.find(c=>c.id===config?.connection_id);
  return <fieldset className="space-y-3 rounded-lg border p-3"><legend className="px-1 text-sm font-medium">Telegram decision buttons</legend>
    <label className="flex gap-2 text-sm"><input type="checkbox" checked={!!config} onChange={e=>onChange({telegram_approval:e.target.checked?{connection_id:"",destination:""}:undefined})}/>Send this approval to a linked reviewer</label>
    {config&&<><Label htmlFor="approval-bot">Telegram connection</Label><select id="approval-bot" className="h-10 w-full rounded-md border bg-background px-3" value={config.connection_id} onChange={e=>onChange({telegram_approval:{connection_id:e.target.value,destination:""}})}><option value="">Choose approved bot</option>{items.filter(c=>c.approved&&c.status==="enabled").map(c=><option key={c.id} value={c.id}>{c.name}</option>)}</select>
      <Label htmlFor="approval-chat">Reviewer’s approved private chat</Label><select id="approval-chat" className="h-10 w-full rounded-md border bg-background px-3" value={config.destination} onChange={e=>onChange({telegram_approval:{...config,destination:e.target.value}})}><option value="">Choose private chat</option>{selected?.destinations.filter(d=>d.approved&&d.chat_type==="private").map(d=><option key={d.chat_id} value={d.chat_id}>{d.label}</option>)}</select>
      <p className="text-xs text-muted-foreground">The recipient must be a confirmed linked FlowMV member eligible for this task. Self-approval is blocked. Grant this workflow the Telegram approval permission before publishing.</p>
      <div className="space-y-2 rounded-md bg-muted p-3 text-xs"><p>Preview: A FlowMV approval needs your decision. Review the full request first.</p><div className="flex flex-wrap gap-2"><Button size="sm" disabled>Approve</Button><Button size="sm" variant="outline" disabled>Reject</Button></div><p>Open full request in FlowMV · Buttons expire in 10 minutes and can be used once. Detailed revision stays in the authenticated FlowMV form.</p></div>
      <Link className="text-xs underline" href="/connections/telegram">Manage bot, reviewer identity and grants</Link>
    </>}
  </fieldset>;
}
