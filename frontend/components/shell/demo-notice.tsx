"use client";

import {useEffect,useState} from "react";
import {FlaskConical} from "lucide-react";
import {api} from "@/lib/api";

export function DemoNotice({organizationId}:{organizationId:string}) {
  const [notice,setNotice]=useState<string|null>(null);
  useEffect(()=>{let active=true;void api<{enabled:boolean;notice:string|null}>(`/api/v1/organizations/${organizationId}/demo-status`).then(data=>{if(active)setNotice(data.enabled?data.notice:null);}).catch(()=>undefined);return()=>{active=false;};},[organizationId]);
  if(!notice)return null;
  return <aside aria-label="Demonstration environment" className="flex shrink-0 items-start gap-2 border-b bg-accent/40 px-4 py-2 text-xs text-foreground"><FlaskConical aria-hidden="true" className="mt-0.5 size-4 shrink-0"/><p>{notice}</p></aside>;
}
