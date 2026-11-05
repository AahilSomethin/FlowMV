import {randomBytes,createHash} from 'node:crypto';
import {existsSync,mkdirSync,readFileSync,writeFileSync,realpathSync} from 'node:fs';
import {resolve,join,dirname,relative,isAbsolute} from 'node:path';
import {fileURLToPath} from 'node:url';
import {execFileSync} from 'node:child_process';

const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const profile=process.argv[2]??'native';
if(!['native','container'].includes(profile))throw new Error('Choose native or container.');
const backend=join(root,'backend');
const target=join(backend,profile==='native'?'.env.demo':'.env.demo.container');
const privateRoot=join(backend,'storage','app','phase10-demo',profile);
function parse(text){return Object.fromEntries(text.split(/\r?\n/).filter(l=>l&&!l.startsWith('#')).map(l=>{const i=l.indexOf('=');return[l.slice(0,i),l.slice(i+1)];}));}
let env;
if(existsSync(target)){
 env=parse(readFileSync(target,'utf8'));
 if(env.APP_ENV!=='demo'||env.FLOWMV_DEMO_PROFILE!==profile||env.DB_DATABASE!=='flowmv_phase10_demo'||!/^([a-f0-9]{64})$/.test(env.FLOWMV_DEMO_INSTALLATION_TOKEN??''))throw new Error('Existing demo environment is not owned/recognized; refusing overwrite.');
}else{
 const container=profile==='container',api=container?8011:8010,web=container?3011:3010;
 const key=randomBytes(32).toString('base64');
 env={APP_NAME:'FlowMV',APP_ENV:'demo',APP_KEY:'base64:'+randomBytes(32).toString('base64'),APP_DEBUG:'false',APP_URL:`http://localhost:${api}`,FRONTEND_URL:`http://localhost:${web}`,SANCTUM_STATEFUL_DOMAINS:`localhost:${web},localhost:${api}`,SESSION_DRIVER:'database',SESSION_COOKIE:container?'flowmv10-demo-container':'flowmv10-demo',SESSION_LIFETIME:'120',DB_CONNECTION:'pgsql',DB_HOST:container?'postgres':'127.0.0.1',DB_PORT:container?'5432':'55490',DB_DATABASE:'flowmv_phase10_demo',DB_USERNAME:'flowmv_demo_owner',DB_PASSWORD:randomBytes(24).toString('hex'),DB_URL:'',DB_SSLMODE:'disable',CACHE_STORE:'database',CACHE_PREFIX:container?'flowmv10_container':'flowmv10_native',QUEUE_CONNECTION:'database',DB_QUEUE_RETRY_AFTER:'180',MAIL_MAILER:'array',LOG_CHANNEL:'stderr',INTEGRATION_ACTIVE_KEY:'v1',INTEGRATION_KEYS:JSON.stringify({v1:key}),INTEGRATION_TELEGRAM_ENABLED:'true',INTEGRATION_TELEGRAM_SIMULATED:'true',INTEGRATION_TELEGRAM_WEBHOOK_ORIGIN:'https://flowmv.example.test',INTEGRATION_EMAIL_ENABLED:'true',INTEGRATION_EMAIL_SIMULATED:'true',INTEGRATION_EMAIL_WEBHOOK_ORIGIN:'https://flowmv.example.test',GOOGLE_MAIL_CLIENT_ID:'FLOWMV_QA_EMAIL_CLIENT',GOOGLE_MAIL_CLIENT_SECRET:'FLOWMV_QA_EMAIL_SECRET',GOOGLE_MAIL_REDIRECT_URI:'https://flowmv.example.test/integrations/email-callback/gmail',GOOGLE_MAIL_PUBSUB_TOPIC:'projects/flowmv-demo/topics/mail',GOOGLE_MAIL_PUSH_AUDIENCE:'https://flowmv.example.test',GOOGLE_MAIL_PUSH_SERVICE_ACCOUNT:'synthetic@example.test',MICROSOFT_MAIL_CLIENT_ID:'FLOWMV_QA_EMAIL_CLIENT',MICROSOFT_MAIL_CLIENT_SECRET:'FLOWMV_QA_EMAIL_SECRET',MICROSOFT_MAIL_REDIRECT_URI:'https://flowmv.example.test/integrations/email-callback/outlook',SLACK_ENABLED:'true',SLACK_SIMULATED:'true',SLACK_CLIENT_ID:'FLOWMV_QA_SLACK_CLIENT',SLACK_CLIENT_SECRET:'FLOWMV_QA_SLACK_SECRET',SLACK_SIGNING_SECRET:'FLOWMV_QA_SLACK_SIGNING_SECRET',SLACK_APP_ID:'A90000001',SLACK_REDIRECT_URI:'https://flowmv.example.test/integrations/slack-callback',SLACK_PKCE_ENABLED:'false',SMS_ENABLED:'true',SMS_SIMULATED:'true',SMS_WEBHOOK_ORIGIN:'https://flowmv.example.test',FLOWMV_DEMO_PROFILE:profile,FLOWMV_DEMO_INSTALLATION_TOKEN:randomBytes(32).toString('hex'),FLOWMV_DEMO_PRIVATE_ROOT:container?'/app/backend/storage/app/phase10-demo':privateRoot,INTEGRATION_DOCUMENTS_ROOT:container?'/app/backend/storage/app/phase10-demo/documents':join(privateRoot,'documents')};
 writeFileSync(target,Object.entries(env).map(([k,v])=>`${k}=${k==='INTEGRATION_KEYS'?`'${v}'`:v}`).join('\n')+'\n',{mode:0o600});
}
// Append Phase 11 settings only; existing application, database and vault keys stay intact.
const aiDefaults={AI_ENABLED:'true',AI_SIMULATED:'true',AI_SERVICE_URL:profile==='native'?'http://127.0.0.1:8020':'http://ai-go:8020',AI_SERVICE_KEY:randomBytes(32).toString('hex')};
const additions=Object.entries(aiDefaults).filter(([name])=>env[name]===undefined);
if(additions.length){writeFileSync(target,readFileSync(target,'utf8')+'\n'+additions.map(([name,value])=>`${name}=${value}`).join('\n')+'\n',{mode:0o600});for(const [name,value] of additions)env[name]=value;}
if(env.AI_ENABLED!=='true'||env.AI_SIMULATED!=='true'||env.AI_SERVICE_URL!==aiDefaults.AI_SERVICE_URL||!/^[a-f0-9]{64}$/.test(env.AI_SERVICE_KEY??''))throw new Error('Demo AI settings refused; existing settings were preserved.');
const expectedHost=profile==='native'?'127.0.0.1':'postgres';
const expectedPort=profile==='native'?'55490':'5432';
if(env.DB_CONNECTION!=='pgsql'||env.DB_HOST!==expectedHost||env.DB_PORT!==expectedPort||env.DB_USERNAME!=='flowmv_demo_owner'||env.DB_URL!==''||env.MAIL_MAILER!=='array'||['INTEGRATION_TELEGRAM_SIMULATED','INTEGRATION_EMAIL_SIMULATED','SLACK_SIMULATED','SMS_SIMULATED'].some(name=>env[name]!=='true'))throw new Error('Demo configuration target or transport flags refused before database setup.');
mkdirSync(privateRoot,{recursive:true});
const privateRelative=relative(realpathSync(join(backend,'storage','app')),realpathSync(privateRoot));
if(privateRelative.startsWith('..')||isAbsolute(privateRelative))throw new Error('Private demo path escapes storage/app.');
// Never print keys, passwords, connection URLs or the installation token.
if(profile==='container'){console.log('Container demo environment prepared in backend/.env.demo.container. No database was modified.');process.exit(0);}
const pgBin=process.env.FLOWMV_PG_BIN??(process.platform==='win32'?'C:/Program Files/PostgreSQL/18/bin':'');
const binary=name=>pgBin?join(pgBin,name+(process.platform==='win32'?'.exe':'')):name;
const childEnv={...process.env,PGPASSWORD:env.DB_PASSWORD};
for(const name of ['PGHOSTADDR','PGSERVICE','PGSERVICEFILE','PGOPTIONS','PGDATABASE','PGUSER','PGPORT'])delete childEnv[name];
// A running PostgreSQL child can retain captured pg_ctl pipes on Windows.
function pg(name,args){return execFileSync(binary(name),args,{env:childEnv,encoding:'utf8',windowsHide:true,timeout:60000,stdio:name==='pg_ctl'?'ignore':['ignore','pipe','pipe']});}
const data=join(privateRoot,'postgres');
const owned=join(privateRoot,'postgres-owner.json');
if(!existsSync(data)){
 if(existsSync(owned))throw new Error('Owned cluster directory is missing; no automatic reinitialization.');
 const pw=join(privateRoot,'bootstrap-password');writeFileSync(pw,env.DB_PASSWORD+'\n',{mode:0o600});
 pg('initdb',['-D',data,'-U','flowmv_demo_owner','--pwfile='+pw,'--auth-local=scram-sha-256','--auth-host=scram-sha-256','--encoding=UTF8','--no-locale']);
 writeFileSync(owned,JSON.stringify({schema:1,path:resolve(data),installation:createHash('sha256').update(env.FLOWMV_DEMO_INSTALLATION_TOKEN).digest('hex')},null,2),{mode:0o600});
}else{
 if(realpathSync(data).toLowerCase()!==resolve(data).toLowerCase())throw new Error('Symlinked database directories are refused.');
 if(!existsSync(owned)||JSON.parse(readFileSync(owned,'utf8')).path!==resolve(data)||JSON.parse(readFileSync(owned,'utf8')).installation!==createHash('sha256').update(env.FLOWMV_DEMO_INSTALLATION_TOKEN).digest('hex'))throw new Error('Unowned PostgreSQL directory; refusing to start.');
}
console.log('Checking the owned PostgreSQL cluster.');
try{pg('pg_ctl',['-D',data,'status']);}catch{pg('pg_ctl',['-D',data,'-l',join(privateRoot,'postgres.log'),'-o','-h 127.0.0.1 -p 55490','-w','start']);}
console.log('Verifying demo database ownership.');
const args=['-X','-v','ON_ERROR_STOP=1','-h','127.0.0.1','-p','55490','-U','flowmv_demo_owner','-d','postgres','-At'];
const actualData=pg('psql',[...args,'-c','show data_directory']).trim();
if(realpathSync(actualData).toLowerCase()!==realpathSync(data).toLowerCase())throw new Error('The server on the demo port does not own this data directory.');
if(pg('psql',[...args,'-c',"select count(*) from pg_database where datname='flowmv_phase10_demo'"]).trim()==='0')pg('createdb',['-h','127.0.0.1','-p','55490','-U','flowmv_demo_owner','--owner=flowmv_demo_owner','flowmv_phase10_demo']);
const expected='flowmv-demo:v1:'+createHash('sha256').update(env.FLOWMV_DEMO_INSTALLATION_TOKEN).digest('hex');
const sqlArgs=['-X','-v','ON_ERROR_STOP=1','-h','127.0.0.1','-p','55490','-U','flowmv_demo_owner','-d','flowmv_phase10_demo','-At'];
const marker=pg('psql',[...sqlArgs,'-c',"select coalesce(shobj_description(oid,'pg_database'),'') from pg_database where datname=current_database()"]).trim();
if(!marker){
 const tables=pg('psql',[...sqlArgs,'-c',"select count(*) from pg_tables where schemaname='public'"]).trim();
 if(tables!=='0')throw new Error('Nonempty unowned database; refusing marker creation.');
 pg('psql',[...sqlArgs,'-c',`COMMENT ON DATABASE flowmv_phase10_demo IS '${expected}'`]);
}else if(marker!==expected)throw new Error('Demo database marker mismatch.');
const appEnv={...process.env,...env,INTEGRATION_KEYS:env.INTEGRATION_KEYS.replace(/^'|'$/g,''),APP_ENV:'demo'};
for(const args of [['artisan','migrate','--env=demo','--force','--no-interaction'],['artisan','demo:seed','--env=demo','--no-interaction']])execFileSync('php',args,{cwd:backend,env:appEnv,stdio:'inherit',windowsHide:true});
console.log('Native demo prepared. Login details are in the ignored private demo directory. Existing QA and hosted databases were not targeted.');
