"use client";
import {useEffect,useState} from "react";
import { IntentLink as Link } from "@/components/shell/intent-link";
import {api} from "@/lib/api";
import {Input} from "@/components/ui/input";
import {Label} from "@/components/ui/label";
import {Textarea} from "@/components/ui/textarea";
import type {TelegramConnection} from "@/components/connections/telegram-screen";
import type {FormField,WorkflowNode,WorkflowNodeData} from "@/lib/workflows/types";
export function TelegramInspector({node,fields,organizationId,onChange}:{node:WorkflowNode;fields:FormField[];organizationId?:string|null;onChange:(patch:Partial<WorkflowNodeData>)=>void}){
  const [result,setResult]=useState<{org:string;items:TelegramConnection[]}|null>(null);
  useEffect(()=>{
    if(!organizationId||node.type==="telegram_filter")return;
    let active=true;
    void api<{items:TelegramConnection[]}>("/api/v1/organizations/"+organizationId+"/integrations/telegram").then(data=>{if(active)setResult({org:organizationId,items:data.items});}).catch(()=>{if(active)setResult({org:organizationId,items:[]});});
    return()=>{active=false;};
  },[organizationId,node.type]);
  const items=result?.org===organizationId?result?.items??[]:[];
  const selected=items.find(c=>c.id===node.data.connection_id);
  if(node.type==="telegram_filter")return <div className="space-y-3">
    <Label htmlFor="tg-filter-field">Message field</Label><select id="tg-filter-field" className="h-10 w-full rounded-md border bg-background px-3" value={node.data.filter_field??"command"} onChange={e=>onChange({filter_field:e.target.value as WorkflowNodeData["filter_field"]})}><option value="command">Bot command</option><option value="text">Message text</option><option value="chat_type">Chat type</option></select>
    <Label htmlFor="tg-match-mode">Match rule</Label><select id="tg-match-mode" className="h-10 w-full rounded-md border bg-background px-3" value={node.data.match_mode??"equals"} onChange={e=>onChange({match_mode:e.target.value as WorkflowNodeData["match_mode"]})}><option value="equals">Equals</option><option value="contains">Contains</option><option value="starts_with">Starts with</option></select>
    <Label htmlFor="tg-match-value">Match value</Label><Input id="tg-match-value" maxLength={100} value={node.data.match_value??""} onChange={e=>onChange({match_value:e.target.value})} placeholder="/request"/>
    <p className="text-xs text-muted-foreground">Comparisons are case-sensitive. Bot command names are normalized to lowercase. Connect both Matches and Does not match outcomes.</p>
  </div>;
  return <div className="space-y-3">
    <Label htmlFor="tg-node-connection">Telegram bot</Label><select id="tg-node-connection" className="h-10 w-full rounded-md border bg-background px-3" value={node.data.connection_id??""} onChange={e=>onChange({connection_id:e.target.value,destination:""})}><option value="">Choose an approved bot</option>{items.filter(c=>c.approved&&c.status==="enabled").map(c=><option key={c.id} value={c.id}>{c.name} (@{c.username})</option>)}</select>
    <Label htmlFor="tg-node-chat">{node.type==="telegram_trigger"?"Incoming chat":"Send to"}</Label><select id="tg-node-chat" className="h-10 w-full rounded-md border bg-background px-3" value={node.data.destination??""} onChange={e=>onChange({destination:e.target.value})}><option value="">Choose an approved chat</option>{selected?.destinations.filter(d=>d.approved).map(d=><option key={d.chat_id} value={d.chat_id}>{d.label}</option>)}</select>
    <p className="text-xs text-muted-foreground"><Link href="/connections/telegram" className="underline">Manage Telegram connections</Link>. Grant this workflow its specific trigger or action permission before publishing.</p>
    {node.type==="telegram_trigger"?<fieldset className="space-y-3"><legend className="font-medium text-sm">Map the incoming message to the request</legend><p className="text-xs text-muted-foreground">Only confirmed linked members can start requests. The published version stays pinned to its trigger subscription.</p>{fields.filter(f=>["text","textarea","number"].includes(f.type)).map(f=><div key={f.id} className="space-y-1"><Label htmlFor={"tg-map-"+f.id}>{f.label}{f.required?" (required)":""}</Label><select id={"tg-map-"+f.id} className="h-10 w-full rounded-md border bg-background px-3" value={node.data.input_mapping?.[f.id]??""} onChange={e=>{
      const mapping={...node.data.input_mapping};if(e.target.value)mapping[f.id]=e.target.value as NonNullable<WorkflowNodeData["input_mapping"]>[string];else delete mapping[f.id];onChange({input_mapping:mapping});
    }}><option value="">Not mapped</option>{f.type!=="number"&&<><option value="text">Message text</option><option value="command">Bot command</option></>}<option value="chat_id">Chat ID</option><option value="sender_id">Linked sender ID</option><option value="message_id">Message ID</option></select></div>)}</fieldset>:<>
      <Label htmlFor="tg-message-text">Message</Label><Textarea id="tg-message-text" value={node.data.text??""} maxLength={2000} onChange={e=>onChange({text:e.target.value})}/>
      <p className="text-xs text-muted-foreground">Plain text only. Safe variables: {"{{request.uuid}}"}, {"{{request.status}}"}, {"{{approval.decision}}"}. Messages are delivered through a retry queue; delivery history appears in Connections.</p>
      <fieldset className="space-y-2"><legend className="text-sm font-medium">Request answers permitted in this message</legend><p className="text-xs text-muted-foreground">Only select answers that this destination is authorized to receive. Use {"{{input.field_id}}"} for a selected field.</p>{fields.filter(f=>f.type!=="file").map(f=><label key={f.id} className="flex gap-2 text-sm"><input type="checkbox" checked={node.data.allowed_field_ids?.includes(f.id)??false} onChange={e=>{const ids=new Set(node.data.allowed_field_ids??[]);if(e.target.checked)ids.add(f.id);else ids.delete(f.id);onChange({allowed_field_ids:[...ids]});}}/>{f.label} <span className="text-muted-foreground">({f.id})</span></label>)}</fieldset>
    </>}
  </div>;
}
