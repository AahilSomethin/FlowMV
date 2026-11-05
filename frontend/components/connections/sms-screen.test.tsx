import userEvent from "@testing-library/user-event";
import {render,screen,waitFor} from "@testing-library/react";
import {beforeEach,describe,expect,it,vi} from "vitest";
import {SmsScreen} from "./sms-screen";
const api=vi.hoisted(()=>vi.fn());
vi.mock("@/lib/api",async original=>({...await original<typeof import("@/lib/api")>(),api}));
vi.mock("@/lib/auth",()=>({useAuth:()=>({user:{current_organization:{uuid:"org-sms"}}})}));
describe("SMS permissions and disclosure",()=>{
 beforeEach(()=>api.mockReset());
 it("does not expose recipient or credential management to members",async()=>{
  api.mockResolvedValue({items:[],templates:[],admin:false,enabled:true,simulated:false,provider_approved:true,policy:null});
  render(<SmsScreen/>);
  expect(await screen.findByText(/SMS credentials and recipient approval/)).toBeInTheDocument();
  expect(screen.queryByRole("button",{name:"Connect account"})).not.toBeInTheDocument();
  expect(screen.queryByRole("button",{name:"Authorize recipient"})).not.toBeInTheDocument();
  expect(screen.getByText(/No incoming messages or message history/)).toBeInTheDocument();
 });
 it("restores keyboard focus when a sensitive dialog is dismissed",async()=>{
  const user=userEvent.setup();api.mockResolvedValue({items:[],templates:[],admin:true,enabled:true,simulated:true,provider_approved:true,policy:null});
  render(<SmsScreen/>);const opener=await screen.findByRole("button",{name:"Configure policy"});
  await user.click(opener);await screen.findByRole("dialog");await user.keyboard("{Escape}");
  await waitFor(()=>expect(screen.queryByRole("dialog")).not.toBeInTheDocument());expect(opener).toHaveFocus();
  expect(api.mock.calls.filter(c=>c[1]?.method)).toHaveLength(0);
 });
 it("distinguishes simulated delivery and uncertain sends from delivery confirmation",async()=>{
  api.mockResolvedValue({items:[{id:"sms",name:"QA account",status:"enabled",health:"healthy",approved:true,senders:[],recipients:[],grants:[],deliveries:[{id:"delivery",status:"uncertain",error_code:"transport_timeout",attempt_count:1,updated_at:"2026-10-30 00:00:00"}]}],templates:[],admin:true,enabled:true,simulated:true,provider_approved:true,policy:null});
  render(<SmsScreen/>);await screen.findByText("QA account");
  expect(screen.getByText(/No SMS is sent or billed/)).toBeInTheDocument();
  expect(screen.getByText(/uncertain · 1 attempts/)).toBeInTheDocument();
  expect(screen.getByText(/Uncertain sends are never automatically resent/)).toBeInTheDocument();
  expect(screen.getByRole("button",{name:"Grant workflow"})).toBeEnabled();
 });
});
