import{readdirSync,readFileSync,writeFileSync,mkdirSync}from'node:fs';
import{resolve,dirname,join}from'node:path';
import{fileURLToPath}from'node:url';
import{execFileSync}from'node:child_process';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const directory=join(root,'docs/diagrams'),output=join(directory,'rendered');
mkdirSync(output,{recursive:true});
const cli=join(root,'tools/docs/node_modules/@mermaid-js/mermaid-cli/src/cli.js');
let count=0;
for(const file of readdirSync(directory).filter(f=>f.endsWith('.md'))){
 const text=readFileSync(join(directory,file),'utf8');let index=0;
 for(const block of text.matchAll(/```mermaid\r?\n([\s\S]*?)```/g)){
  const base=join(output,file.slice(0,-3)+'-'+(++index));writeFileSync(base+'.mmd',block[1]);
  for(const format of process.argv.includes('--png')?['svg','png']:['svg'])execFileSync('node',[cli,'-c',join(root,'tools/docs/mermaid-config.json'),'-i',base+'.mmd','-o',base+'.'+format,'-b',format==='png'?'white':'transparent'],{cwd:root,stdio:'inherit',windowsHide:true,timeout:60000});count++;
 }
}
if(count===0)throw new Error('No diagrams were rendered.');
console.log(`${count} Mermaid diagrams parsed and rendered. Inspect SVGs before acceptance.`);
