"use client";
import {useEffect,useState} from "react";
import { IntentLink as Link } from "@/components/shell/intent-link";
import {api} from "@/lib/api";
import {Label} from "@/components/ui/label";
import type {WorkflowNode,WorkflowNodeData} from "@/lib/workflows/types";
import type {SmsCatalog} from "@/components/connections/sms-screen";

export function SmsInspector({node,organizationId,workflowId,onChange}:{node:WorkflowNode;organizationId?:string|null;workflowId?:string|null;onChange:(patch:Partial<WorkflowNodeData>)=>void}){
  const [result,setResult]=useState<{org:string;data:SmsCatalog}|null>(null);
  useEffect(()=>{let active=true;if(organizationId)void api<SmsCatalog>(`/api/v1/organizations/${organizationId}/integrations/sms${workflowId?`?workflow_id=${encodeURIComponent(workflowId)}`:""}`).then(data=>{if(active)setResult({org:organizationId,data});}).catch(()=>{if(active)setResult(null);});return()=>{active=false;};},[organizationId,workflowId]);
  const catalog=result&&result.org===organizationId?result.data:null;
  const connection=catalog?.items.find(c=>c.id===node.data.connection_id);
  const template=catalog?.templates.find(t=>t.id===node.data.template_id);
  return <div className="space-y-3">
    <Label htmlFor="sms-node-connection">SMS connection</Label><select id="sms-node-connection" className="h-10 w-full rounded-md border bg-background px-3" value={node.data.connection_id??""} onChange={e=>onChange({connection_id:e.target.value,sender_id:"",recipient_id:"",template_id:""})}><option value="">Choose an approved connection</option>{catalog?.items.filter(c=>c.approved&&c.status==="enabled").map(c=><option key={c.id} value={c.id}>{c.name}</option>)}</select>
    <Label htmlFor="sms-node-sender">Approved sender</Label><select id="sms-node-sender" className="h-10 w-full rounded-md border bg-background px-3" value={node.data.sender_id??""} onChange={e=>onChange({sender_id:e.target.value})}><option value="">Choose a sender</option>{connection?.senders.filter(s=>s.enabled).map(s=><option key={s.id} value={s.id}>{s.label}</option>)}</select>
    <Label htmlFor="sms-node-recipient">Authorized recipient</Label><select id="sms-node-recipient" className="h-10 w-full rounded-md border bg-background px-3" value={node.data.recipient_id??""} onChange={e=>onChange({recipient_id:e.target.value})}><option value="">Choose a recipient</option>{connection?.recipients.filter(r=>r.enabled).map(r=><option key={r.id} value={r.id}>{r.label}</option>)}</select>
    <Label htmlFor="sms-node-template">Approved template</Label><select id="sms-node-template" className="h-10 w-full rounded-md border bg-background px-3" value={node.data.template_id??""} onChange={e=>onChange({template_id:e.target.value})}><option value="">Choose a template</option>{catalog?.templates.filter(t=>t.enabled).map(t=><option key={t.id} value={t.id}>{t.name}</option>)}</select>
    {template&&<div className="rounded-md border p-3"><p className="text-xs font-medium">Template preview (variables fill at execution)</p><p className="mt-2 break-words whitespace-pre-wrap text-sm">{template.body}</p></div>}
    <p className="text-xs text-muted-foreground">One short SMS only. No request answers, HR fields or document content. An administrator must grant this exact sender, recipient, template and action to the workflow. Consent expires after 30 days. Organization limits can stop sending.</p>
    <Link href="/connections/sms" className="text-sm underline">Manage SMS permissions and delivery history</Link>
  </div>;
}
