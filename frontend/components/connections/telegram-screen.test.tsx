import {fireEvent,render,screen,waitFor} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import {beforeEach,describe,expect,it,vi} from "vitest";
import {TelegramScreen,type TelegramConnection} from "./telegram-screen";
const api=vi.hoisted(()=>vi.fn());
vi.mock("@/lib/api",async original=>({...await original<typeof import("@/lib/api")>(),api}));
vi.mock("@/lib/auth",()=>({useAuth:()=>({user:{current_organization:{uuid:"org-telegram"}}})}));
const connection:TelegramConnection={id:"bot-one",name:"Review bot",username:"review_bot",status:"enabled",ownership:"organization",can_manage:true,approved:true,webhook_configured:true,destinations:[{chat_id:"777",label:"Review inbox",chat_type:"private",approved:true}],grants:[],subscriptions:[],identity:null,deliveries:[],audit:[]};
const catalog={items:[],admin:true,enabled:true,provider_approved:true,personal_connections_allowed:true,simulated:false,provider:{reads:"Confirmed member messages",sends:"Approved destination messages"}};
describe("Telegram connection controls",()=>{
  beforeEach(()=>api.mockReset());
  it("shows simulated decision controls only to the bound recipient and keeps token management hidden",async()=>{
    api.mockResolvedValue({...catalog,admin:false,simulated:true,items:[{...connection,can_manage:false,approval_deliveries:[{id:"decision",status:"accepted",error_code:null,attempt_count:1,task_status:"open",instance_uuid:"request",is_mine:true}]}]});
    render(<TelegramScreen/>);await screen.findByText("Review bot");
    expect(screen.getByRole("button",{name:"Simulate Approve"})).toBeEnabled();
    expect(screen.queryByRole("button",{name:"Revoke"})).not.toBeInTheDocument();
    expect(screen.getByRole("link",{name:"Open full request"})).toHaveAttribute("href","/requests/request");
  });
  it("hides expired Telegram simulation buttons and offers the authenticated fallback",async()=>{
    api.mockResolvedValue({...catalog,admin:false,simulated:true,items:[{...connection,can_manage:false,approval_deliveries:[{id:"expired",status:"accepted",error_code:null,attempt_count:1,task_status:"open",instance_uuid:"request",is_mine:true,can_decide:false}]}]});
    render(<TelegramScreen/>);await screen.findByText("Review bot");
    expect(screen.getByText(/Buttons expired/)).toBeInTheDocument();
    expect(screen.queryByRole("button",{name:"Simulate Approve"})).not.toBeInTheDocument();
    expect(screen.getByRole("link",{name:"Open full request"})).toHaveAttribute("href","/requests/request");
  });
  it("returns keyboard focus to the opener when a sensitive-operation dialog is dismissed",async()=>{
    const user=userEvent.setup();api.mockResolvedValue({...catalog,items:[connection]});
    render(<TelegramScreen/>);await screen.findByText("Review bot");
    const rotate=screen.getByRole("button",{name:"Rotate token"});
    await user.click(rotate);await screen.findByLabelText("Bot token");
    await user.keyboard("{Escape}");
    await waitFor(()=>expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(rotate).toHaveFocus();
    expect(api.mock.calls.filter(c=>c[1]?.method)).toHaveLength(0);
  });
  it("labels the simulation and refuses connection while operator enablement is absent",async()=>{
    api.mockResolvedValue({...catalog,enabled:false,simulated:true});
    render(<TelegramScreen/>);
    await screen.findByText(/Local QA simulation/);
    expect(screen.getByRole("button",{name:"Connect bot"})).toBeDisabled();
    expect(screen.getByText(/Incoming messages create requests only after identity confirmation/)).toBeInTheDocument();
  });
  it("confirms the session before submitting a synthetic token and clears entered credentials",async()=>{
    const user=userEvent.setup();api.mockResolvedValue({...catalog,simulated:true});
    render(<TelegramScreen/>);await screen.findByText("Connect your bot");
    fireEvent.change(screen.getByLabelText("Connection name"),{target:{value:"QA bot"}});
    await user.click(screen.getByRole("button",{name:"Connect bot"}));
    await user.click(screen.getByRole("button",{name:"Generate simulated credential"}));
    const token=(screen.getByLabelText("Bot token") as HTMLInputElement).value;
    expect(token).toMatch(/^900001:.*_FLOWMV_QA_ONLY$/);
    fireEvent.change(screen.getByLabelText("Your Orqestra password"),{target:{value:"password"}});
    expect(api.mock.calls.filter(c=>c[1]?.method)).toHaveLength(0);
    await user.click(screen.getByRole("button",{name:"Confirm"}));
    await screen.findByText("Telegram settings updated.");
    const calls=api.mock.calls.filter(c=>c[1]?.method);
    expect(calls[0][0]).toBe("/api/v1/integration-auth/confirm");
    expect(JSON.parse(calls[1][1].body)).toEqual({name:"QA bot",ownership:"personal",credentials:{bot_token:token}});
    expect(screen.queryByLabelText("Bot token")).not.toBeInTheDocument();
    expect(screen.queryByText(token)).not.toBeInTheDocument();
  });
  it("shows shared connection metadata without offering unauthorized credential management",async()=>{
    api.mockResolvedValue({...catalog,admin:false,items:[{...connection,can_manage:false}]});
    render(<TelegramScreen/>);await screen.findByText("Review bot");
    expect(screen.getByRole("button",{name:"Link my account"})).toBeEnabled();
    expect(screen.queryByRole("button",{name:"Rotate token"})).not.toBeInTheDocument();
    expect(screen.queryByRole("button",{name:"Revoke"})).not.toBeInTheDocument();
    expect(screen.queryByRole("button",{name:"Add a chat ID"})).not.toBeInTheDocument();
  });
  it("shows a one-time linking command and requires confirmation of the received identity",async()=>{
    const user=userEvent.setup();
    api.mockImplementation((path:string,init?:{method?:string})=>Promise.resolve(init?.method?(path.endsWith("/identity/start")?{command:"/flowmv_link synthetic-challenge"}:{}):{...catalog,items:[{...connection,identity:{telegram_user_id:"777",confirmed:false,expires_at:null}}]}));
    render(<TelegramScreen/>);await screen.findByText("Review bot");
    await user.click(screen.getByRole("button",{name:"Link my account"}));
    fireEvent.change(screen.getByLabelText("Your Orqestra password"),{target:{value:"password"}});
    await user.click(screen.getByRole("button",{name:"Confirm"}));
    await screen.findByText("/flowmv_link synthetic-challenge");
    expect(screen.getByRole("button",{name:"Confirm account"})).toBeInTheDocument();
    await user.click(screen.getByRole("button",{name:"Confirm account"}));
    await user.click(screen.getByRole("button",{name:/^Confirm$/}));
    await waitFor(()=>expect(api).toHaveBeenCalledWith("/api/v1/organizations/org-telegram/integrations/telegram/bot-one/identity/confirm",expect.objectContaining({method:"POST"})));
  });
  it("requires duplicate-risk acknowledgment for an uncertain delivery retry",async()=>{
    const user=userEvent.setup();api.mockResolvedValue({...catalog,items:[{...connection,deliveries:[{id:"delivery",status:"uncertain",error_code:"transport_timeout",attempt_count:1,instance_uuid:"instance"}]}]});
    render(<TelegramScreen/>);await screen.findByText("Delivery history");
    await user.click(screen.getByRole("button",{name:"Review retry"}));
    const risk=screen.getByRole("checkbox",{name:/Telegram may already have received/});
    expect(risk).toBeRequired();await user.click(risk);
    fireEvent.change(screen.getByLabelText("Your Orqestra password"),{target:{value:"password"}});
    await user.click(screen.getByRole("button",{name:"Confirm"}));
    await screen.findByText("Telegram settings updated.");
    const call=api.mock.calls.find(c=>c[0].endsWith("/retry-delivery"));
    expect(JSON.parse(call![1].body)).toEqual({delivery_id:"delivery",acknowledge_duplicate_risk:true});
  });
});
