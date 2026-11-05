import{readdirSync,readFileSync,writeFileSync,mkdirSync,statSync}from'node:fs';
import{resolve,dirname,join,relative}from'node:path';
import{fileURLToPath}from'node:url';
import{execFileSync}from'node:child_process';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const output=join(root,'docs/diagrams/rendered');
mkdirSync(output,{recursive:true});
const cli=join(root,'tools/docs/node_modules/@mermaid-js/mermaid-cli/src/cli.js');
const sources=[
 join(root,'docs/architecture.md'),
 join(root,'docs/workflows-and-integrations.md'),
 join(root,'docs/operations-and-security.md'),
 join(root,'docs/showcase-guide.md'),
 join(root,'docs/quick-start.md'),
 ...readdirSync(join(root,'docs/diagrams')).filter(f=>f.endsWith('.md')).map(f=>join(root,'docs/diagrams',f)),
];
let count=0;
for(const file of sources){
 if(!statSync(file,{throwIfNoEntry:false})?.isFile())continue;
 const text=readFileSync(file,'utf8');
 const slug=relative(join(root,'docs'),file).replace(/[\\/]/g,'-').replace(/\.md$/,'');
 let index=0;
 for(const block of text.matchAll(/```mermaid\r?\n([\s\S]*?)```/g)){
  const base=join(output,`${slug}-${++index}`);
  writeFileSync(base+'.mmd',block[1]);
  for(const format of process.argv.includes('--png')?['svg','png']:['svg']){
   execFileSync('node',[cli,'-c',join(root,'tools/docs/mermaid-config.json'),'-i',base+'.mmd','-o',base+'.'+format,'-b',format==='png'?'white':'transparent'],{cwd:root,stdio:'inherit',windowsHide:true,timeout:60000});
  }
  count++;
 }
}
if(count===0)throw new Error('No diagrams were rendered.');
console.log(`${count} Mermaid diagrams parsed and rendered. Inspect SVGs before acceptance.`);
