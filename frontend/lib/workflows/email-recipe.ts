import {createNode} from "@/lib/workflows/definition";
import type {DocumentField, FormDefinition, GraphDefinition, NodeType} from "@/lib/workflows/types";

/** A reviewable draft recipe; publication and every human decision remain explicit. */
export function emailReviewRecipe(connection:string,template:{id:string;fields:DocumentField[]},sender:string,recipient:string):{graph_definition:GraphDefinition;form_definition:FormDefinition} {
  const types:NodeType[]=["email_trigger","email_filter","attachment_inspector","document_validation","approval","email_send","email_reply","revision","end","end"];
  const nodes=types.map((t,i)=>createNode(t,{x:i%3*320,y:Math.floor(i/3)*240}));
  const [trigger,filter,inspect,validate,approve,send,reply,revision,complete,reject]=nodes;
  const approvedOutcome=createNode("email_reply",{x:960,y:480});
  const rejectedOutcome=createNode("email_reply",{x:960,y:720});
  approvedOutcome.data={...approvedOutcome.data,label:"Notify applicant of approval",connection_id:connection,destination:sender,text:"Your request {{request.uuid}} was {{approval.decision}}. View the decision in FlowMV: {{request.link}}",allowed_field_ids:[]};
  rejectedOutcome.data={...rejectedOutcome.data,label:"Notify applicant of rejection",connection_id:connection,destination:sender,text:"Your request {{request.uuid}} was {{approval.decision}}. View the decision in FlowMV: {{request.link}}",allowed_field_ids:[]};
  nodes.push(approvedOutcome,rejectedOutcome);
  const fields=template.fields.filter(f=>f.type!=="supporting_document").map(f=>({id:f.id,type:(f.type==="date"||f.type==="number"||f.type==="select"?f.type:"text") as "date"|"number"|"select"|"text",label:f.label,description:"Extracted from private evidence; correct here when requested.",required:false,default_value:null,validation:{},options:(f.options??[]).map(x=>({value:x,label:x}))}));
  trigger.data={...trigger.data,connection_id:connection,input_mapping:Object.fromEntries(fields.map(f=>[f.id,`document.${f.id}`]))};
  filter.data={...filter.data,rules:[{field:"subject",operator:"contains",value:"application"}],match:"all"};
  validate.data={...validate.data,template_id:template.id,document_fields:template.fields,field_map:Object.fromEntries(fields.map(f=>[f.id,f.id]))};
  send.data={...send.data,connection_id:connection,destination:recipient,subject:"Approved request",text:"Request {{request.uuid}}: {{approval.decision}}. {{request.link}}",allowed_field_ids:[]};
  reply.data={...reply.data,connection_id:connection,destination:sender,text:"Please correct: {{validation.missing}}. Sign in to FlowMV: {{request.link}}",allowed_field_ids:[]};
  revision.data={...revision.data,max_returns:3,return_to_node_id:validate.id};
  reject.data={...reject.data,label:"Rejected",result:"rejected"};
  const edges:GraphDefinition["edges"]=[];
  const edge=(from:typeof trigger,handle:string,to:typeof trigger)=>edges.push({id:crypto.randomUUID(),source:from.id,target:to.id,source_handle:handle,target_handle:"in"});
  edge(trigger,"out",filter);edge(filter,"matched",inspect);edge(filter,"unmatched",reject);edge(inspect,"inspected",validate);edge(inspect,"missing",reject);edge(inspect,"blocked",reject);edge(validate,"complete",approve);edge(validate,"missing",reply);edge(validate,"invalid",reply);edge(validate,"unknown",reject);edge(reply,"out",revision);edge(revision,"return",validate);edge(revision,"exhausted",reject);edge(approve,"approved",send);edge(approve,"rejected",rejectedOutcome);edge(send,"out",approvedOutcome);edge(approvedOutcome,"out",complete);edge(rejectedOutcome,"out",reject);
  return {graph_definition:{nodes,edges},form_definition:{fields}};
}
