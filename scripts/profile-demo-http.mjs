import{readFileSync,writeFileSync}from'node:fs';
import{resolve,dirname,join}from'node:path';
import{fileURLToPath}from'node:url';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const profile=process.argv[2]??'native';
if(!['native','container'].includes(profile))throw new Error('Choose an owned demo profile.');
const accountFile=join(root,`backend/storage/app/phase10-demo/${profile}/accounts.json`);
const accounts=JSON.parse(readFileSync(accountFile,'utf8'));
const account=accounts.accounts.find(a=>a.email==='mindco.admin1@example.com');
const base=profile==='native'?'http://localhost:8010':'http://localhost:8011',origin=profile==='native'?'http://localhost:3010':'http://localhost:3011',jar=new Map();
async function request(path,method='GET',data){
 const headers={Accept:'application/json',Origin:origin,Referer:origin+'/',Cookie:[...jar].map(([k,v])=>k+'='+v).join('; ')};
 if(method!=='GET'){headers['Content-Type']='application/json';headers['X-XSRF-TOKEN']=decodeURIComponent(jar.get('XSRF-TOKEN')??'');}
 const at=performance.now();
 const response=await fetch(base+path,{method,headers,body:data===undefined?undefined:JSON.stringify(data),signal:AbortSignal.timeout(30000)});
 for(const cookie of response.headers.getSetCookie()){const part=cookie.split(';')[0],i=part.indexOf('=');jar.set(part.slice(0,i),part.slice(i+1));}
 const body=await response.json().catch(()=>null);
 return{status:response.status,ms:performance.now()-at,body};
}
const csrf=await request('/sanctum/csrf-cookie');
if(csrf.status!==204)throw new Error('Demo CSRF session unavailable: '+csrf.status);
const login=await request('/api/v1/auth/login','POST',{email:account.email,password:account.password});
if(login.status!==200)throw new Error('Demo authentication failed: '+login.status);
const prefix='/api/v1/organizations/'+accounts.organization;
const guard=await request(prefix+'/demo-status');
if(guard.status!==200||guard.body?.data?.enabled!==true)throw new Error('Server did not confirm owned demo: '+guard.status);
const workloads={requests:prefix+'/instances',analytics:prefix+'/analytics?from=2026-09-01&to=2026-11-03'};
const results={};
for(const[name,path]of Object.entries(workloads)){
 const samples=[],statuses={};let wall=performance.now();
 for(let batch=0;batch<10;batch++){
  const pair=await Promise.all([request(path),request(path)]);
  for(const result of pair){statuses[result.status]=(statuses[result.status]??0)+1;if(result.status===200)samples.push(result.ms);}
 }
 wall=performance.now()-wall;samples.sort((a,b)=>a-b);
 const percentile=p=>samples.length?Number(samples[Math.ceil(samples.length*p)-1].toFixed(3)):null;
 results[name]={successful_samples:samples.length,statuses,p50_ms:percentile(.5),p95_ms:percentile(.95),wall_ms:Number(wall.toFixed(3)),throughput_per_second:Number((samples.length/(wall/1000)).toFixed(2))};
}
await request('/api/v1/auth/logout','POST',{});
const output={target:`owned ${profile} demo`,concurrency:2,planned_samples_per_endpoint:20,results,limitations:['PHP development server serializes requests; not a production concurrency claim.','Includes real session, CSRF bootstrap, membership and policy checks. No rate limits bypassed.','429 responses are reported separately; no p99 below 10000 successes.','No injected provider delay. SQL/application attribution is in the separate in-process profile.']};
writeFileSync(join(root,`backend/storage/app/phase10-audit/profile-http-${profile}-${Date.now()}.json`),JSON.stringify(output,null,2),{flag:'wx'});
console.log(JSON.stringify(output));
if(Object.values(results).some(r=>r.successful_samples!==20))process.exitCode=1;
