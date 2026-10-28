import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ConnectionsScreen } from "./connections-screen";
const api = vi.hoisted(() => vi.fn());
vi.mock("@/lib/api", async importOriginal => ({...await importOriginal<typeof import("@/lib/api")>(),api}));
vi.mock("@/lib/auth",()=>({useAuth:()=>({user:{current_organization:{uuid:"workspace-a"}}})}));
const catalog = {items:[],admin:false,mock_enabled:true,personal_connections_allowed:false,provider_approved:true,provider:{reads:"Synthetic event identifiers only.",sends:"No messages leave FlowMV."}};
describe("connection permissions and credential handling",()=>{
  beforeEach(()=>api.mockReset());
  it("explains data access and disables personal creation when policy forbids it",async()=>{
    api.mockResolvedValue(catalog);
    render(<ConnectionsScreen/>);
    await screen.findByText("What FlowMV can read");
    expect(screen.getByText("No messages leave FlowMV.")).toBeInTheDocument();
    expect(screen.getByRole("button",{name:"Connect"})).toBeDisabled();
    expect(screen.queryByText("Workspace controls")).not.toBeInTheDocument();
  });
  it("requires confirmation before sending credentials and never renders saved secrets",async()=>{
    const user = userEvent.setup();
    api.mockResolvedValue({...catalog,personal_connections_allowed:true});
    render(<ConnectionsScreen/>);
    await screen.findByText("What FlowMV can read");
    fireEvent.change(screen.getByLabelText("Connection name"),{target:{value:"My mock"}});
    await user.click(screen.getByRole("button",{name:"Connect"}));
    fireEvent.change(screen.getByLabelText("New API key"),{target:{value:"synthetic-ui-token-only"}});
    fireEvent.change(screen.getByLabelText("Your password"),{target:{value:"password"}});
    expect(api.mock.calls.filter(call=>call[1]?.method)).toHaveLength(0);
    await user.click(screen.getByRole("button",{name:"Confirm"}));
    await screen.findByText("Connection settings updated.");
    const mutations = api.mock.calls.filter(call=>call[1]?.method);
    expect(mutations[0][0]).toBe("/api/v1/integration-auth/confirm");
    expect(JSON.parse(mutations[1][1].body)).toEqual({name:"My mock",ownership:"personal",credential_type:"api_key",credentials:{api_key:"synthetic-ui-token-only"}});
    expect(screen.queryByDisplayValue("synthetic-ui-token-only")).not.toBeInTheDocument();
    expect(screen.queryByLabelText("Your password")).not.toBeInTheDocument();
  });
  it("shows workflow access and routes permanent revocation through confirmation",async()=>{
    const user=userEvent.setup();
    api.mockResolvedValue({...catalog,admin:true,items:[{id:"connection-a",name:"Review bot",ownership:"organization",status:"enabled",credential_type:"bot_token",workflows:[{workflow_id:"workflow-a",workflow_name:"Purchase review",operation:"mock.notify"}],destinations:["mock:review"]}]});
    render(<ConnectionsScreen/>);
    await screen.findByText("Review bot");
    expect(screen.getByText(/Purchase review/)).toBeInTheDocument();
    await user.click(screen.getByRole("button",{name:"Revoke"}));
    expect(screen.getByText(/permanently removes the credential/)).toBeInTheDocument();
    await user.type(screen.getByLabelText("Your password"),"password");
    await user.click(screen.getByRole("button",{name:"Confirm"}));
    await waitFor(()=>expect(api).toHaveBeenCalledWith("/api/v1/organizations/workspace-a/connections/connection-a/revoke",expect.objectContaining({method:"POST"})));
  });
});
