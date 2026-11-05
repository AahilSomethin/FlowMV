"use client";
import {useEffect,useState} from "react";
import { IntentLink as Link } from "@/components/shell/intent-link";
import {Mail,Send,Hash,MessageSquare,ShieldCheck,type LucideIcon} from "lucide-react";
import {api} from "@/lib/api";
import {Card,CardHeader,CardTitle,CardDescription,CardContent} from "@/components/ui/card";
import {Button} from "@/components/ui/button";
import {Badge} from "@/components/ui/badge";

import type {ProviderOverviewData} from "@/lib/integration-tools";
const icons:Record<string,LucideIcon>={gmail:Mail,outlook:Mail,telegram:Send,slack:Hash,twilio_sms:MessageSquare};
export function ProviderOverview({organizationId}:{organizationId:string}){
  const [state,setState]=useState<{org:string;providers:ProviderOverviewData[]}|null>(null),[error,setError]=useState(false);
  useEffect(()=>{let active=true;void api<{providers:ProviderOverviewData[]}>(`/api/v1/organizations/${organizationId}/connections/overview`).then(data=>{if(!Array.isArray(data.providers))throw new Error("Provider overview unavailable");if(active){setState({org:organizationId,providers:data.providers});setError(false);}}).catch(()=>{if(active)setError(true);});return()=>{active=false;};},[organizationId]);
  if(error)return <p role="alert">Provider status is unavailable. Open the provider setup page to review its permissions.</p>;
  if(state?.org!==organizationId)return <p role="status">Loading provider status…</p>;
  return <section aria-label="Connected services" className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{state.providers.map(p=>{const Icon=icons[p.provider_id]??ShieldCheck;return <Card key={p.provider_id} className="min-w-0"><CardHeader><CardTitle className="flex items-center gap-2"><Icon aria-hidden="true" className="size-5"/>{p.name}</CardTitle><CardDescription>{p.setup}</CardDescription></CardHeader><CardContent className="flex flex-col gap-4"><Badge variant="secondary" className="self-start">{p.provider_approved?"Workspace approved":"Approval required"}</Badge>{p.connections.length===0?<p className="text-sm text-muted-foreground">Requires configuration. No connection is available to your account.</p>:<ul className="space-y-3">{p.connections.map(c=><li key={c.id} className="space-y-1 break-words rounded-lg border p-3 text-sm"><p className="font-medium">{c.name}</p><p className="text-muted-foreground capitalize">{c.ownership} · {c.status}</p>{c.last_operation?<><p>{c.last_operation.source==="simulated"?"Simulated operation":"Provider response recorded"}</p><p className="text-xs text-muted-foreground">{c.last_operation.operation.replaceAll("."," · ")} · {new Date(c.last_operation.at).toLocaleString()}</p></>:<p className="text-xs text-muted-foreground">Live provider unverified. No operation evidence with recorded provenance.</p>}</li>)}</ul>}<Button asChild variant="outline" className="mt-auto"><Link href={p.path}>Open {p.name} setup</Link></Button><p className="text-xs text-muted-foreground">Each workflow needs its own permission. Provider acceptance does not establish delivery.</p></CardContent></Card>;})}</section>;
}
