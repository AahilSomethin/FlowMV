"use client";
import {useEffect,useRef,useState} from "react";
import Link from "next/link";
import {api} from "@/lib/api";
import {useAuth} from "@/lib/auth";
import {Button} from "@/components/ui/button";
import {Input} from "@/components/ui/input";
import {Label} from "@/components/ui/label";
export function SlackOAuthCallback(){
  const {user,status}=useAuth();const pending=useRef<{code:string;state:string}|null>(null);const initialized=useRef(false);
  const [message,setMessage]=useState("Confirming your provider authorization…");const [password,setPassword]=useState("");const [needsStepUp,setNeedsStepUp]=useState(false);const [busy,setBusy]=useState(false);
  useEffect(()=>{if(initialized.current)return;initialized.current=true;const query=new URLSearchParams(window.location.search);window.history.replaceState(null,"",window.location.pathname);if(query.has("error")){queueMicrotask(()=>setMessage("Provider authorization was declined. Return to Connections to try again."));return;}const code=query.get("code"),state=query.get("state");if(code&&state){pending.current={code,state};queueMicrotask(()=>{setNeedsStepUp(true);setMessage("Confirm your FlowMV password to finish authorization.");});}else queueMicrotask(()=>setMessage("This authorization callback is incomplete or has already been consumed. Start again from Connections."));},[]);
  return <main className="mx-auto w-full max-w-lg space-y-4 p-6"><h1 className="text-2xl font-semibold">Connect Slack</h1><p role="status">{status==="guest"?"Your secure session expired. Sign in, then restart authorization from Connections.":message}</p>{needsStepUp&&status==="authenticated"&&<form className="space-y-3" onSubmit={e=>{e.preventDefault();if(!pending.current||!user?.current_organization||busy)return;setBusy(true);const secret=password;setPassword("");void api("/api/v1/integration-auth/confirm",{method:"POST",body:JSON.stringify({password:secret})}).then(()=>api(`/api/v1/organizations/${user.current_organization!.uuid}/integrations/slack/oauth/finish`,{method:"POST",body:JSON.stringify({...pending.current})})).then(()=>{pending.current=null;setNeedsStepUp(false);setMessage("Slack workspace connected. Administrator approval is required.");}).catch(()=>setMessage("Authorization failed. Restart from Connections if it expired or was already used.")).finally(()=>setBusy(false));}}><Label htmlFor="callback-password">FlowMV password</Label><Input id="callback-password" type="password" autoComplete="current-password" required value={password} onChange={e=>setPassword(e.target.value)}/><Button disabled={busy}>Finish authorization</Button></form>}<Button asChild variant="outline"><Link href="/connections/slack">Return to Slack connections</Link></Button></main>;
}
