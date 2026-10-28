"use client";
import { useCallback, useEffect, useState } from "react";
import { KeyRound, Plug, ShieldCheck, LockKeyhole, Workflow, FlaskConical } from "lucide-react";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from "@/components/ui/dialog";
import { WorkflowFilter } from "@/components/analytics/workflow-filter";

type Connection = { id: string; name: string; ownership: string; status: string; credential_type: string; workflows: {workflow_id:string;workflow_name:string;operation:string}[]; destinations:string[] };
type Catalog = { items:Connection[]; admin:boolean; mock_enabled:boolean; personal_connections_allowed:boolean; provider_approved:boolean; provider:{ reads:string;sends:string } };
type Operation = { title:string; path:string; method?:string; body:Record<string,unknown> };
export function ConnectionsScreen() {
  const { user } = useAuth();
  const organization = user?.current_organization?.uuid;
  const base = `/api/v1/organizations/${organization}`;
  const [catalog, setCatalog] = useState<Catalog|null>(null);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [operation, setOperation] = useState<Operation|null>(null);
  const [password, setPassword] = useState("");
  const [secret, setSecret] = useState("");
  const [name, setName] = useState("");
  const [ownership, setOwnership] = useState("personal");
  const [credentialType, setCredentialType] = useState("api_key");
  const [selected, setSelected] = useState<Connection|null>(null);
  const [workflowId, setWorkflowId] = useState("");
  const [destination, setDestination] = useState("mock:inbox");
  const [allowedOperation, setAllowedOperation] = useState("mock.notify");
  const load = useCallback(async () => {
    if (!organization) return;
    const data = await api<Catalog>(`${base}/connections`);
    setCatalog(data);
  }, [base, organization]);
  useEffect(() => {
    let active = true;
    if (organization) void api<Catalog>(`${base}/connections`).then(data => { if (active) setCatalog(data); }).catch(() => { if(active) setError("Could not load connections. Try again."); });
    return () => { active = false; };
  }, [base, organization]);
  function prepare(value:Operation) { setError(""); setNotice(""); setPassword(""); setOperation(value); }
  async function execute(event:React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!operation || busy) return;
    setBusy(true); setError("");
    const confirmation = password;
    setPassword("");
    try {
      await api("/api/v1/integration-auth/confirm", {method:"POST", body:JSON.stringify({password:confirmation})});
      if (operation.path === "mock-oauth") {
        const flow = await api<{state:string;code_challenge:string}>(`${base}/integration-oauth/start`, {method:"POST", body:JSON.stringify(operation.body)});
        const consent = await api<{state:string;code:string;issuer:string}>(`${base}/integration-oauth/authorize`, {method:"POST", body:JSON.stringify(flow)});
        await api(`${base}/integration-oauth/callback`, {method:"POST", body:JSON.stringify(consent)});
      } else {
        const body = {...operation.body};
        if (operation.title === "Rename") body.name = name;
        if (operation.title === "Share") Object.assign(body, {workflow_id:workflowId,operation:allowedOperation,destination});
        if (operation.title === "Connect" || operation.title === "Rotate credential") {
          body.credentials = {[credentialType]:secret};
        }
        await api(operation.path, {method:operation.method ?? "POST", body:JSON.stringify(body)});
      }
      setSecret(""); setSelected(null); setOperation(null); setName("");
      setNotice("Connection settings updated.");
      await load();
    } catch (failure) {
      setSecret("");
      setOperation(null);
      setError(failure instanceof ApiError ? failure.message : "Could not update the connection.");
    } finally { setBusy(false); }
  }
  function act(connection:Connection, action:string, body:Record<string,unknown> = {}) {
    setSelected(connection);
    setCredentialType(connection.credential_type);
    prepare({title:action === "revoke" ? "Revoke connection permanently" : action === "rotate" ? "Rotate credential" : action.charAt(0).toUpperCase()+action.slice(1), path:`${base}/connections/${connection.id}/${action}`, body});
  }
  return <div className="flex flex-col gap-6">
    <div className="flex items-start gap-3"><Plug className="mt-1 size-7 text-primary" aria-hidden="true"/><div><h1 className="text-2xl font-semibold tracking-tight">Connections</h1><p className="text-muted-foreground">Choose what FlowMV can access and which workflows may use it.</p></div></div>
    {error && <p role="alert" className="rounded-lg border border-destructive p-3 text-sm">{error} <Button variant="ghost" onClick={() => void load().catch(() => setError("Could not load connections."))}>Reload</Button></p>}
    {notice && <p role="status" className="rounded-lg border p-3 text-sm">{notice}</p>}
    {!catalog ? <p>Loading connections…</p> : <>
      <Card><CardHeader><CardTitle className="flex items-center gap-2"><FlaskConical className="size-5" aria-hidden="true"/>Local demonstration <Badge variant="secondary">Mock provider</Badge></CardTitle><CardDescription>No real service is connected. Credentials and delivery stay inside FlowMV.</CardDescription></CardHeader><CardContent className="grid gap-4 md:grid-cols-3">
        <div><h2 className="font-medium">What FlowMV can read</h2><p className="text-sm text-muted-foreground">{catalog.provider.reads}</p></div>
        <div><h2 className="font-medium">What FlowMV can send</h2><p className="text-sm text-muted-foreground">{catalog.provider.sends}</p></div>
        <div><h2 className="flex items-center gap-2 font-medium"><LockKeyhole className="size-4" aria-hidden="true"/>Your credentials stay private</h2><p className="text-sm text-muted-foreground">Secrets are encrypted and cannot be viewed after saving. Shared credentials require explicit workflow permission.</p></div>
      </CardContent></Card>
      {catalog.admin && <Card><CardHeader><CardTitle className="flex items-center gap-2"><ShieldCheck className="size-5" aria-hidden="true"/>Workspace controls</CardTitle></CardHeader><CardContent className="flex flex-wrap gap-3">
        <Button variant="outline" disabled={busy} onClick={() => prepare({title:catalog.provider_approved ? "Withdraw provider approval" : "Approve local provider", path:`${base}/integration-policy`,method:"PUT",body:{personal_connections_allowed:catalog.personal_connections_allowed,provider_approved:!catalog.provider_approved}})}>{catalog.provider_approved ? "Withdraw provider approval" : "Approve local provider"}</Button>
        <Button variant="outline" disabled={busy} onClick={() => prepare({title:"Change personal connection policy",path:`${base}/integration-policy`,method:"PUT",body:{personal_connections_allowed:!catalog.personal_connections_allowed,provider_approved:catalog.provider_approved}})}>{catalog.personal_connections_allowed ? "Stop new personal connections" : "Allow personal connections"}</Button>
        {!catalog.mock_enabled && <p className="w-full text-sm text-muted-foreground">The local demonstration is disabled on this server. An operator must enable it before connecting.</p>}
      </CardContent></Card>}
      <Card><CardHeader><CardTitle className="flex items-center gap-2"><KeyRound className="size-5" aria-hidden="true"/>Connect a credential</CardTitle><CardDescription>Personal credentials are visible only to you. Organization credentials are managed by admins. No workflow receives access automatically.</CardDescription></CardHeader><CardContent>
        <form className="grid items-end gap-4 sm:grid-cols-2" onSubmit={event => {event.preventDefault(); setSelected(null); prepare({title:credentialType === "oauth" ? "Authorize mock OAuth" : "Connect",path:credentialType === "oauth" ? "mock-oauth" : `${base}/connections`,body:{name,ownership,credential_type:credentialType}});}}>
          <div className="space-y-2"><Label htmlFor="connection-name">Connection name</Label><Input id="connection-name" value={name} onChange={e=>setName(e.target.value)} required maxLength={100} placeholder="Review notifications"/></div>
          <div className="space-y-2"><Label htmlFor="ownership">Who owns it?</Label><select id="ownership" className="h-10 w-full rounded-md border bg-background px-3" value={ownership} onChange={e=>setOwnership(e.target.value)}><option value="personal" disabled={!catalog.personal_connections_allowed}>Only me{!catalog.personal_connections_allowed ? " — requires policy approval" : ""}</option>{catalog.admin && <option value="organization">Organization</option>}</select></div>
          <div className="space-y-2"><Label htmlFor="credential-type">Credential type</Label><select id="credential-type" className="h-10 w-full rounded-md border bg-background px-3" value={credentialType} onChange={e=>{setCredentialType(e.target.value);setSecret("");}}><option value="api_key">API key</option><option value="bot_token">Bot token</option><option value="oauth">OAuth authorization (local simulation)</option></select></div>
          <Button disabled={busy || !catalog.provider_approved || !catalog.mock_enabled || (ownership === "personal" && !catalog.personal_connections_allowed)} type="submit">Connect</Button>
        </form>
      </CardContent></Card>
      <div className="grid gap-4 md:grid-cols-2">{catalog.items.map(connection=><Card key={connection.id}><CardHeader><div className="flex items-center justify-between gap-2"><CardTitle>{connection.name}</CardTitle><Badge variant="outline">{connection.status}</Badge></div><CardDescription>{connection.ownership === "personal" ? "Personal · visible only to you" : "Organization · admin managed"} · {connection.credential_type}</CardDescription></CardHeader><CardContent className="space-y-4">
        <div><h2 className="flex items-center gap-2 text-sm font-medium"><Workflow className="size-4" aria-hidden="true"/>Workflows with permission</h2>{connection.workflows.length ? <ul className="mt-2 space-y-1 text-sm">{connection.workflows.map(g=><li key={g.workflow_id+g.operation}>{g.workflow_name} · {g.operation}<Button variant="ghost" size="sm" onClick={()=>act(connection,"unshare",{workflow_id:g.workflow_id,operation:g.operation,destination:connection.destinations[0] ?? "mock:inbox"})}>Remove access</Button></li>)}</ul> : <p className="text-sm text-muted-foreground">None. This connection cannot be used by a workflow yet.</p>}</div>
        {connection.status !== "revoked" && <div className="flex flex-wrap gap-2">
          <Button size="sm" variant="outline" disabled={busy || connection.status !== "enabled"} onClick={()=>act(connection,"test")}>Test</Button>
          <Button size="sm" variant="outline" disabled={busy} onClick={()=>act(connection,connection.status === "enabled" ? "disable" : "enable")}>{connection.status === "enabled" ? "Disable" : "Enable"}</Button>
          <Button size="sm" variant="outline" disabled={busy} onClick={()=>{act(connection,"rename");setName(connection.name);}}>Rename</Button>
          {connection.credential_type !== "oauth" && <Button size="sm" variant="outline" disabled={busy} onClick={()=>{setSecret("");act(connection,"rotate");}}>Rotate</Button>}
          {connection.credential_type === "oauth" && <Button size="sm" variant="outline" disabled={busy} onClick={()=>prepare({title:"Reconnect OAuth",path:"mock-oauth",body:{name:connection.name,ownership:connection.ownership,connection_id:connection.id}})}>Reconnect OAuth</Button>}
          <Button size="sm" variant="outline" disabled={busy} onClick={()=>{setWorkflowId("");act(connection,"share");}}>Grant workflow access</Button>
          <Button size="sm" variant="destructive" disabled={busy} onClick={()=>act(connection,"revoke")}>Revoke</Button>
        </div>}
      </CardContent></Card>)}</div>
      {catalog.items.length === 0 && <p className="rounded-lg border border-dashed p-8 text-center text-muted-foreground">No connections yet. Connect a credential after your workspace approves the provider.</p>}
    </>}
    <Dialog open={!!operation} onOpenChange={open=>{if(!open && !busy){setOperation(null);setSecret("");setPassword("");}}}><DialogContent><DialogHeader><DialogTitle>{operation?.title}</DialogTitle><DialogDescription>{operation?.title.startsWith("Revoke") ? "This permanently removes the credential and all workflow access. Existing jobs will be denied." : "Confirm your password to protect this credential operation."}</DialogDescription></DialogHeader>
      <form onSubmit={event=>void execute(event)} className="space-y-4">
        {(operation?.title === "Connect" || operation?.title === "Rotate credential") && <div className="space-y-2"><Label htmlFor="credential-secret">New {credentialType === "api_key" ? "API key" : "bot token"}</Label><Input id="credential-secret" type="password" autoComplete="off" minLength={16} maxLength={512} required value={secret} onChange={e=>setSecret(e.target.value)}/><p className="text-sm text-muted-foreground">Local mock only. Do not enter a real provider token.</p></div>}
        {operation?.title === "Rename" && <div className="space-y-2"><Label htmlFor="rename">New connection name</Label><Input id="rename" value={name} onChange={e=>setName(e.target.value)} required maxLength={100}/></div>}
        {operation?.title === "Share" && <><p className="text-sm">Allow one workflow to use {selected?.name}. Personal credentials remain restricted to requests submitted by their owner.</p><div className="space-y-2"><Label>Workflow</Label>{organization && <WorkflowFilter organizationId={organization} value={workflowId} onChange={setWorkflowId} allowAll={false}/>}</div><Label htmlFor="grant-operation">Permission</Label><select id="grant-operation" className="h-10 w-full rounded-md border bg-background px-3" value={allowedOperation} onChange={e=>setAllowedOperation(e.target.value)}><option value="mock.notify">Send a simulated notification</option><option value="mock.event">Receive a synthetic event</option></select><Label htmlFor="destination">Allowed destination</Label><select id="destination" className="h-10 w-full rounded-md border bg-background px-3" value={destination} onChange={e=>setDestination(e.target.value)}><option value="mock:inbox">Mock inbox</option><option value="mock:review">Mock review</option></select></>}
        <div className="space-y-2"><Label htmlFor="step-up-password">Your password</Label><Input id="step-up-password" type="password" autoComplete="current-password" required value={password} onChange={e=>setPassword(e.target.value)}/></div>
        <Button className="w-full" disabled={busy || (operation?.title === "Share" && !workflowId)} type="submit">{busy ? "Saving…" : "Confirm"}</Button>
      </form>
    </DialogContent></Dialog>
  </div>;
}
