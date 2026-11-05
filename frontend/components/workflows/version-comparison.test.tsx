import {render,screen,fireEvent} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import {describe,it,expect,vi} from "vitest";
import {VersionComparison} from "./version-comparison";
const api=vi.hoisted(()=>vi.fn());
vi.mock("@/lib/api",async original=>({...await original<typeof import("@/lib/api")>(),api}));
describe("version comparison",()=>{
 it("compares selected immutable versions and shows safe structural changes",async()=>{
  api.mockResolvedValue({from:1,to:2,changes:[{node_id:"step",kind:"configuration_changed",type:"approval",fields:["assignee_roles"]}],connections_changed:false,form_structure_changed:false,notice:"Structural comparison only."});
  render(<VersionComparison organizationId="org" workflowId="workflow" versions={[{uuid:"v1",version_number:1,published_at:null,published_by:null},{uuid:"v2",version_number:2,published_at:null,published_by:null}]}/>);
  fireEvent.change(screen.getByLabelText("Earlier version"),{target:{value:"v1"}});fireEvent.change(screen.getByLabelText("Later version"),{target:{value:"v2"}});
  await userEvent.setup().click(screen.getByRole("button",{name:"Compare"}));
  expect(await screen.findByText(/configuration changed/)).toBeInTheDocument();
  expect(api).toHaveBeenCalledWith("/api/v1/organizations/org/workflows/workflow/compare?from=v1&to=v2");
  expect(screen.getByText(/Routing unchanged/)).toBeInTheDocument();
 });
});
