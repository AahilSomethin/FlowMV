import{readdirSync,readFileSync,existsSync}from'node:fs';
import{resolve,dirname,join,relative}from'node:path';
import{fileURLToPath}from'node:url';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const excluded=new Set(['node_modules','vendor','.git','storage','.next','.ai','.agents','.codex']);
function files(dir){return readdirSync(dir,{withFileTypes:true}).flatMap(e=>e.isDirectory()?(excluded.has(e.name)||e.name.startsWith('.next')?[]:files(join(dir,e.name))):e.name.endsWith('.md')?[join(dir,e.name)]:[]);}
const failures=[];let checked=0;
for(const file of files(root)){
 const text=readFileSync(file,'utf8').replace(/```[\s\S]*?```/g,'');
 for(const match of text.matchAll(/!?\[[^\]]*\]\(([^\s)]+)(?:\s+"[^"]*")?\)/g)){
  const href=match[1].replace(/^<|>$/g,'');
  if(/^(https?:|mailto:|plugin:|app:|codex:|#)/.test(href))continue;
  const target=decodeURIComponent(href.split('#')[0]);
  if(!target)continue;
  checked++;
  if(!existsSync(resolve(dirname(file),target)))failures.push(`${relative(root,file)} → ${target}`);
 }
}
for(const failure of failures)console.error(failure);
console.log(`${checked} local file links checked; ${failures.length} missing targets. Anchor and remote URL checks are outside this check.`);
process.exitCode=failures.length?1:0;
