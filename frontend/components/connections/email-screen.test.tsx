import {render,screen,waitFor} from "@testing-library/react";
import {describe,it,expect,vi} from "vitest";
import {EmailScreen} from "@/components/connections/email-screen";
vi.mock("@/lib/auth",()=>({useAuth:()=>({user:{email:"member@example.com",current_organization:{uuid:"org",role:"employee"}}})}));
vi.mock("@/lib/api",async importOriginal=>({...await importOriginal<typeof import("@/lib/api")>(),api:vi.fn(async(path:string)=>path.endsWith("/templates")||path.endsWith("/intakes")?{items:[]}:{items:[],admin:false,enabled:true,simulated:false,personal_connections_allowed:false,approvals:{gmail:false,outlook:false}})}));
describe("email connection privacy",()=>{
  it("explains scope, blocks unapproved personal setup and hides administrator controls",async()=>{
    render(<EmailScreen/>);await waitFor(()=>expect(screen.getByRole("heading",{name:"What Orqestra can access"})).toBeInTheDocument());
    expect(screen.getByRole("button",{name:"Continue to provider"})).toBeDisabled();
    expect(screen.queryByRole("button",{name:"Approve Gmail"})).not.toBeInTheDocument();
    expect(screen.queryByRole("button",{name:"Create review draft"})).not.toBeInTheDocument();
    expect(screen.getByText(/No recognized incoming messages/)).toBeInTheDocument();
    expect(screen.queryByLabelText(/email password/i)).not.toBeInTheDocument();
  });
});
