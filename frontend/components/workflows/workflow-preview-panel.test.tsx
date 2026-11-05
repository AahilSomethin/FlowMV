import {render,screen,fireEvent,waitFor,act} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import {describe,it,expect,vi,beforeEach} from "vitest";
import {WorkflowPreviewPanel} from "./workflow-preview-panel";
import {createNode} from "@/lib/workflows/definition";
const api=vi.hoisted(()=>vi.fn());
vi.mock("@/lib/api",async original=>({...await original<typeof import("@/lib/api")>(),api}));
describe("safe editor preview",()=>{
 beforeEach(()=>{api.mockReset();});
 it("sends only a hypothetical preview and clearly labels assumed decisions",async()=>{
  const approval=createNode("approval",{x:0,y:0});approval.data.label="Manager review";
  api.mockResolvedValue({mode:"hypothetical",definition_hash:"hash",persisted:false,external_delivery:false,result:"completed",trace:[{node_id:approval.id,type:"approval",visit:1,status:"executed",branch:"approved",summary:"Hypothetical decision only."}],skipped:[],findings:[]});
  const user=userEvent.setup();render(<WorkflowPreviewPanel organizationId="org" workflowId="workflow" graph={{nodes:[approval],edges:[]}} form={{fields:[]}}/>);
  await user.click(screen.getByRole("button",{name:"Preview safely"}));fireEvent.change(screen.getByLabelText("Manager review"),{target:{value:"approved"}});
  expect(api).not.toHaveBeenCalled();await user.click(screen.getByRole("button",{name:"Run hypothetical preview"}));
  await waitFor(()=>expect(api).toHaveBeenCalledTimes(1));expect(api.mock.calls[0][0]).toBe("/api/v1/organizations/org/workflows/workflow/preview");
  expect(JSON.parse(api.mock.calls[0][1].body).scenario.decisions[approval.id]).toBe("approved");
  expect(await screen.findByText("Hypothetical decision only.")).toBeInTheDocument();expect(screen.getByText(/No persistence or external delivery/)).toBeInTheDocument();
  expect(screen.getByText("hash",{exact:true})).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText("Manager review"),{target:{value:"rejected"}});
  expect(screen.queryByText("Hypothetical decision only.")).not.toBeInTheDocument();
 });
 it("does not present a late response as a preview of a changed definition",async()=>{
  const approval=createNode("approval",{x:0,y:0});approval.data.label="Manager review";
  let finish!:(value:unknown)=>void;api.mockReturnValue(new Promise(resolve=>{finish=resolve;}));
  const user=userEvent.setup();const view=render(<WorkflowPreviewPanel organizationId="org" workflowId="workflow" graph={{nodes:[approval],edges:[]}} form={{fields:[]}}/>);
  await user.click(screen.getByRole("button",{name:"Preview safely"}));await user.click(screen.getByRole("button",{name:"Run hypothetical preview"}));
  view.rerender(<WorkflowPreviewPanel organizationId="org" workflowId="workflow" graph={{nodes:[{...approval,data:{...approval.data,label:"Changed review"}}],edges:[]}} form={{fields:[]}}/>);
  await act(async()=>{finish({mode:"hypothetical",definition_hash:"old-hash",persisted:false,external_delivery:false,result:"completed",trace:[],skipped:[],findings:[]});});
  expect(screen.queryByText(/Hypothetical result:/)).not.toBeInTheDocument();expect(screen.queryByText("old-hash")).not.toBeInTheDocument();expect(api).toHaveBeenCalledTimes(1);
 });
});
