import {render,screen} from "@testing-library/react";
import {describe,it,expect,vi} from "vitest";
import {TelegramApprovalInspector} from "./telegram-approval-inspector";
vi.mock("@/lib/api",()=>({api:vi.fn().mockResolvedValue({items:[{id:"qa",name:"Review bot",status:"enabled",approved:true,destinations:[{chat_id:"888",label:"Manager private",chat_type:"private",approved:true},{chat_id:"-999",label:"Public group",chat_type:"group",approved:true}]}]})}));
describe("Telegram approval settings",()=>{
  it("offers approved private chats and explains the existing task eligibility and expiry",async()=>{
    render(<TelegramApprovalInspector data={{label:"Approval",description:"",telegram_approval:{connection_id:"qa",destination:"888"}}} organizationId="org" onChange={vi.fn()}/>);
    expect(await screen.findByRole("option",{name:"Manager private"})).toBeInTheDocument();
    expect(screen.queryByRole("option",{name:"Public group"})).not.toBeInTheDocument();
    expect(screen.getByText(/Self-approval is blocked/)).toBeInTheDocument();
    expect(screen.getByRole("button",{name:"Approve"})).toBeDisabled();
    expect(screen.getByText(/Buttons expire in 10 minutes/)).toBeInTheDocument();
  });
});
