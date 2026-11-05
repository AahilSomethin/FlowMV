import {createNode} from "@/lib/workflows/definition";
import type {GraphDefinition,FormDefinition,NodeType} from "@/lib/workflows/types";

/** Generic draft only: grants and publication are explicit website actions. */
export function slackReviewRecipe(connection:string,channel:string):{graph_definition:GraphDefinition;form_definition:FormDefinition}{
  const types:NodeType[]=["slack_trigger","slack_filter","approval","slack_notification","end","end"];
  const nodes=types.map((t,i)=>createNode(t,{x:i%3*320,y:Math.floor(i/3)*240}));
  const [trigger,filter,approval,notify,complete,reject]=nodes;
  trigger.data={...trigger.data,connection_id:connection,destination:channel,input_mapping:{note:"text"}};
  filter.data={...filter.data,filter_field:"command",match_mode:"equals",match_value:"/request"};
  notify.data={...notify.data,connection_id:connection,destination:channel,text:"Request {{request.uuid}}: {{approval.decision}}",allowed_field_ids:[]};
  reject.data={...reject.data,label:"Rejected",result:"rejected"};
  const edges:GraphDefinition["edges"]=[];
  const edge=(source:typeof trigger,handle:string,target:typeof trigger)=>edges.push({id:crypto.randomUUID(),source:source.id,target:target.id,source_handle:handle,target_handle:"in"});
  edge(trigger,"out",filter);edge(filter,"matched",approval);edge(filter,"unmatched",reject);edge(approval,"approved",notify);edge(approval,"rejected",reject);edge(notify,"out",complete);
  return {graph_definition:{nodes,edges},form_definition:{fields:[{id:"note",type:"textarea",label:"Request note",description:"A linked member's workplace request.",required:false,default_value:null,validation:{max_length:2000},options:[]}]}};
}
