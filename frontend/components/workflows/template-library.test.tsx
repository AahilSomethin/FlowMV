import {render,screen,waitFor} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import {describe,it,expect,vi,beforeEach} from "vitest";
import {TemplateLibrary,type WorkflowTemplate} from "./template-library";
const {api,push}=vi.hoisted(()=>({api:vi.fn(),push:vi.fn()}));
vi.mock("@/lib/api",async original=>({...await original<typeof import("@/lib/api")>(),api}));
vi.mock("@/lib/auth",()=>({useAuth:()=>({user:{current_organization:{uuid:"org"}}})}));
vi.mock("next/navigation",()=>({useRouter:()=>({push})}));
const template:WorkflowTemplate={id:"leave-request",version:1,name:"Employee leave request",category:"People",description:"Manager reviews dates.",trigger:"form",required_connections:[],required_roles:["manager"],steps:["Request","Manager","Outcome"],permissions:["Admin publishes."],simulation:"Hypothetical preview",binding_fields:[]};
describe("template discovery",()=>{
 beforeEach(()=>{api.mockReset();push.mockReset();});
 it("creates a draft without publishing or granting credentials",async()=>{
  api.mockImplementation((_path:string,init?:{method?:string})=>Promise.resolve(init?.method?{uuid:"new-draft"}:{items:[template],can_create:true}));
  const user=userEvent.setup();render(<TemplateLibrary/>);await screen.findByText("Employee leave request");
  await user.click(screen.getByRole("button",{name:"Use template"}));await user.click(screen.getByRole("button",{name:"Create draft"}));
  await waitFor(()=>expect(push).toHaveBeenCalledWith("/workflows/new-draft/edit"));
  const writes=api.mock.calls.filter(c=>c[1]?.method);expect(writes).toHaveLength(1);
  expect(writes[0][0]).toBe("/api/v1/organizations/org/workflow-templates/leave-request");expect(JSON.parse(writes[0][1].body)).toEqual({name:"Employee leave request",bindings:{}});
 });
 it("lets employees understand templates while keeping draft creation unavailable",async()=>{
  api.mockResolvedValue({items:[template],can_create:false});render(<TemplateLibrary/>);await screen.findByText("Employee leave request");
  expect(screen.getByRole("button",{name:"Use template"})).toBeDisabled();expect(screen.getByText(/Ask a Manager/)).toBeInTheDocument();
 });
});
