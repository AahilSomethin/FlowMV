import {readFileSync,existsSync} from 'node:fs';
import {resolve,dirname,join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {spawn,execFileSync} from 'node:child_process';
import {createServer} from 'node:net';

const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const backend=join(root,'backend'),frontend=join(root,'frontend');
const file=join(backend,'.env.demo');
if(!existsSync(file))throw new Error('Run node scripts/demo-setup.mjs native first.');
const env=Object.fromEntries(readFileSync(file,'utf8').split(/\r?\n/).filter(l=>l&&!l.startsWith('#')).map(l=>{const i=l.indexOf('=');return[l.slice(0,i),l.slice(i+1).replace(/^'|'$/g,'')];}));
if(env.APP_ENV!=='demo'||env.FLOWMV_DEMO_PROFILE!=='native'||env.DB_HOST!=='127.0.0.1'||env.DB_PORT!=='55490')throw new Error('Native demo profile refused.');
for(const port of [8010,3010,8020])await new Promise((resolve,reject)=>{const server=createServer();server.once('error',()=>reject(new Error(`Demo port ${port} is in use; no existing process was stopped.`)));server.listen(port,'127.0.0.1',()=>server.close(resolve));});
const appEnv={...process.env,...env};
if(env.AI_ENABLED!=='true'||env.AI_SIMULATED!=='true'||!/^[a-f0-9]{64}$/.test(env.AI_SERVICE_KEY??''))throw new Error('Run the guarded demo setup to prepare AI settings first.');
const goRoot=join(root,'services','ai-go');
const goBinary=join(goRoot,'bin',process.platform==='win32'?'ai-go.exe':'ai-go');
execFileSync('go',['build','-trimpath','-o',goBinary,'./cmd/server'],{cwd:goRoot,stdio:'inherit',windowsHide:true});
execFileSync('php',['artisan','demo:verify','--env=demo','--no-interaction'],{cwd:backend,env:appEnv,stdio:'inherit',windowsHide:true});
const children=[];
function launch(name,command,args,cwd,extra={},isolated=false){
 const child=spawn(command,args,{cwd,env:isolated?extra:{...appEnv,...extra},stdio:'inherit',windowsHide:true});
 children.push(child);child.once('error',()=>{console.error(`${name} could not start.`);shutdown(1);});
 child.once('exit',code=>{const index=children.indexOf(child);if(index!==-1)children.splice(index,1);if(!stopping){console.error(`${name} stopped (${code}).`);shutdown(code??1);}});
}
let stopping=false;
function shutdown(code=0){
 if(stopping)return;stopping=true;process.stdin.pause();
 for(const child of [...children]){
  if(child.exitCode!==null||child.signalCode!==null)continue;
  if(process.platform==='win32'){
   try{execFileSync('taskkill',['/PID',String(child.pid),'/T','/F'],{stdio:'ignore',windowsHide:true});}catch{child.kill('SIGTERM');}
  }else child.kill('SIGTERM');
 }
 process.exitCode=code;
}
process.on('SIGINT',()=>shutdown());process.on('SIGTERM',()=>shutdown());
process.stdin.on('data',data=>{if(data.toString().trim()==='stop')shutdown();});
launch('AI Go',goBinary,[],goRoot,{SystemRoot:process.env.SystemRoot??'',PATH:process.env.PATH??'',AI_SERVICE_KEY:env.AI_SERVICE_KEY,AI_LISTEN:'127.0.0.1:8020',AI_SIMULATED:'true',AI_DEMO_INSTALLATION:env.FLOWMV_DEMO_INSTALLATION_TOKEN,AI_DEMO_DATABASE:env.DB_DATABASE},true);
launch('API','php',['-S','127.0.0.1:8010',join(backend,'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],join(backend,'public'));
launch('Worker','php',['artisan','queue:work','--env=demo','--sleep=1','--tries=3','--timeout=120'],backend);
launch('Scheduler','php',['artisan','schedule:work','--env=demo'],backend);
const production=process.argv.includes('--production');
launch('Web','node',['node_modules/next/dist/bin/next',production?'start':'dev','--hostname','127.0.0.1','--port','3010'],frontend,{NEXT_PUBLIC_API_URL:'http://localhost:8010',FLOWMV_SESSION_COOKIE:'flowmv10-demo',FLOWMV_DIST_DIR:process.env.FLOWMV_DIST_DIR??(production?'.next-phase12-native-production':'.next-phase12-native')});
console.log('Synthetic demo: http://localhost:3010 — ordinary login required. Ctrl+C or typing stop stops this launcher’s processes.');
