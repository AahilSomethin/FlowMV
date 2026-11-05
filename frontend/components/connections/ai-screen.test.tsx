import userEvent from "@testing-library/user-event";
import {render,screen,waitFor} from "@testing-library/react";
import {beforeEach,describe,expect,it,vi} from "vitest";
import {AiScreen} from "./ai-screen";
import {AI_POLICY_DEFAULTS,type AiCatalog} from "@/lib/ai";
const api=vi.hoisted(()=>vi.fn());
vi.mock("@/lib/api",async original=>({...await original<typeof import("@/lib/api")>(),api}));
vi.mock("@/lib/auth",()=>({useAuth:()=>({user:{current_organization:{uuid:"org-ai"}}})}));
const catalog:AiCatalog={enabled:true,simulated:true,admin:false,personal_connections_allowed:false,policy:null,models:{openai:["synthetic-model"],gemini:["synthetic-gemini"]},operations:["summarize"],items:[],runs:[],notice:"Advisory"};
describe("AI privacy, approval and evidence",()=>{
 beforeEach(()=>api.mockReset());
 it("explains disabled policy without exposing administrator controls to members",async()=>{
  api.mockResolvedValue(catalog);render(<AiScreen/>);
  await screen.findByText(/Organization AI processing is disabled/);
  expect(screen.queryByRole("button",{name:"Organization AI policy"})).not.toBeInTheDocument();
  expect(screen.queryByRole("button",{name:"Connect AI provider"})).not.toBeInTheDocument();
  expect(screen.getByText(/Simulated AI/)).toBeInTheDocument();
 });
 it("restores focus when policy confirmation is cancelled and performs no mutation",async()=>{
  const user=userEvent.setup();api.mockResolvedValue({...catalog,admin:true});render(<AiScreen/>);
  const button=await screen.findByRole("button",{name:"Organization AI policy"});await user.click(button);
  expect(await screen.findByRole("dialog")).toBeInTheDocument();await user.keyboard("{Escape}");
  await waitFor(()=>expect(screen.queryByRole("dialog")).not.toBeInTheDocument());expect(button).toHaveFocus();
  expect(api.mock.calls.filter(call=>call[1]?.method)).toHaveLength(0);
 });
 it("allows opted-in personal approval without offering key management to the admin reviewer",async()=>{
  api.mockResolvedValue({...catalog,admin:true,policy:{...AI_POLICY_DEFAULTS,enabled:true},items:[{id:"private",name:"Owner requested review",provider:"openai",ownership:"personal",status:"enabled",model:"synthetic-model",operations:["summarize"],max_input_bytes:20000,max_output_tokens:1024,daily_units:50000,approved:false,approval_requested:true,can_approve:true,can_manage:false,grants:[]}]});
  render(<AiScreen/>);await screen.findByText("Owner requested review");
  expect(screen.getByRole("button",{name:"Approve use"})).toBeEnabled();
  expect(screen.queryByRole("button",{name:"Rotate"})).not.toBeInTheDocument();
  expect(screen.queryByRole("button",{name:"Test"})).not.toBeInTheDocument();
 });
});
