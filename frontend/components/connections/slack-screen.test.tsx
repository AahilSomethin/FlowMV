import {render,screen} from "@testing-library/react";
import {beforeEach,describe,expect,it,vi} from "vitest";
import {SlackScreen,type SlackConnection} from "./slack-screen";
const api=vi.hoisted(()=>vi.fn());
vi.mock("@/lib/api",async original=>({...await original<typeof import("@/lib/api")>(),api}));
vi.mock("@/lib/auth",()=>({useAuth:()=>({user:{current_organization:{uuid:"org-slack"}}})}));
const connection:SlackConnection={id:"slack-one",name:"Team requests",workspace_id:"T12345678",status:"enabled",health:"healthy",ownership:"organization",can_manage:false,approved:true,channels:[{channel_id:"C12345678",label:"requests"}],grants:[],identity:null,deliveries:[]};
describe("Slack connection permissions",()=>{
  beforeEach(()=>api.mockReset());
  it("offers identity linking without exposing shared credential management to a member",async()=>{
    api.mockResolvedValue({items:[connection],admin:false,enabled:true,provider_approved:true});
    render(<SlackScreen/>);await screen.findByText("Team requests");
    expect(screen.getByRole("button",{name:"Link my identity"})).toBeEnabled();
    expect(screen.queryByRole("button",{name:"Revoke"})).not.toBeInTheDocument();
    expect(screen.queryByRole("button",{name:"Approve channel"})).not.toBeInTheDocument();
    expect(screen.getByText(/Workspace message history is not requested/)).toBeInTheDocument();
  });
  it("shows explicit workflow permissions and safe delivery failure codes to administrators",async()=>{
    api.mockResolvedValue({items:[{...connection,can_manage:true,grants:[{workflow_id:"w",workflow_name:"Leave review",operation:"slack.notification",destination:"C12345678"}],deliveries:[{id:"delivery",status:"uncertain",error_code:"transport_timeout",attempt_count:1}]}],admin:true,enabled:true,provider_approved:true});
    render(<SlackScreen/>);await screen.findByText("Team requests");
    expect(screen.getByText(/Leave review · slack.notification/)).toBeInTheDocument();
    expect(screen.getByText(/uncertain · 1 attempts/)).toBeInTheDocument();
    expect(screen.getByRole("button",{name:"Revoke"})).toBeEnabled();
  });
});
