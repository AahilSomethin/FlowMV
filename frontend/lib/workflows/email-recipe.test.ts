import {describe,it,expect} from "vitest";
import {emailReviewRecipe} from "@/lib/workflows/email-recipe";
import {validateDefinition} from "@/lib/workflows/definition";
import {addWorkflowNode,createEditorState,connectNodes} from "@/lib/workflows/editor-state";
import {paletteNodeTypes,sourceHandlesFor} from "@/lib/workflows/node-registry";

const template={id:crypto.randomUUID(),fields:[{id:"reference",label:"Reference",type:"identifier" as const,required:true}]};
describe("email review contracts",()=>{
  it("builds a bounded human review and correction path without required intake fields",()=>{
    const result=emailReviewRecipe(crypto.randomUUID(),template,"sender@example.com","hr@example.com");
    expect(validateDefinition(result.graph_definition,result.form_definition)).toEqual([]);
    expect(result.form_definition.fields.every(f=>!f.required)).toBe(true);
    const approval=result.graph_definition.nodes.find(n=>n.type==="approval")!;
    const send=result.graph_definition.nodes.find(n=>n.type==="email_send")!;
    expect(result.graph_definition.edges).toContainEqual(expect.objectContaining({source:approval.id,source_handle:"approved",target:send.id}));
    expect(send.data.allowed_field_ids).toEqual([]);
    expect(JSON.stringify(result)).not.toMatch(/access_token|refresh_token|client_secret/);
  });
  it("offers additions only in V3 and freezes existing handles",()=>{
    for(const type of ["email_trigger","email_filter","email_send","email_reply","attachment_inspector","document_validation"] as const){expect(paletteNodeTypes(3)).toContain(type);expect(paletteNodeTypes(2)).not.toContain(type);}
    expect(sourceHandlesFor("document_validation")).toEqual(["complete","missing","invalid","unknown"]);
    expect(sourceHandlesFor("approval")).toEqual(["approved","rejected"]);
  });
  it("replaces Start with one Email Trigger and permits revision returns to validation",()=>{
    let state=createEditorState({nodes:[],edges:[]},{fields:[]},1);
    state=addWorkflowNode(state,"start",{x:0,y:0},"entry");
    state=addWorkflowNode(state,"email_trigger",{x:1,y:1});
    expect(state.nodes).toHaveLength(1);expect(state.nodes[0].type).toBe("email_trigger");
    state=addWorkflowNode(state,"document_validation",{x:0,y:200},"validate");state=addWorkflowNode(state,"revision",{x:0,y:400},"revise");
    state=connectNodes(state,{source:"revise",target:"validate",sourceHandle:"return"});
    expect(state.edges.some(e=>e.source==="revise"&&e.target==="validate")).toBe(true);
  });
});
