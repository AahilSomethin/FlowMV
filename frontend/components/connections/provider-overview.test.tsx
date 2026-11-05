import {render,screen} from "@testing-library/react";
import {describe,it,expect,vi} from "vitest";
import {ProviderOverview} from "./provider-overview";
const api=vi.hoisted(()=>vi.fn());
vi.mock("@/lib/api",()=>({api}));
describe("provider evidence",()=>{
 it("keeps unknown historical evidence distinct from simulated responses",async()=>{
  api.mockResolvedValue({providers:[{provider_id:"telegram",name:"Telegram Bot",path:"/connections/telegram",setup:"Approved chat",provider_approved:true,connections:[{id:"old",name:"Historical bot",ownership:"organization",status:"enabled",can_manage:true,last_operation:null},{id:"qa",name:"QA bot",ownership:"organization",status:"disabled",can_manage:true,last_operation:{operation:"telegram.test",source:"simulated",at:"2026-11-02T00:00:00Z"}}]}]});
  render(<ProviderOverview organizationId="org"/>);await screen.findByText("Historical bot");
  expect(screen.getByText(/Live provider unverified/)).toBeInTheDocument();
  expect(screen.getByText("Simulated operation")).toBeInTheDocument();
  expect(screen.queryByText("Provider response recorded")).not.toBeInTheDocument();
  expect(screen.getByRole("link",{name:"Open Telegram Bot setup"})).toHaveAttribute("href","/connections/telegram");
 });
 it("reports malformed status data without fabricating evidence or crashing",async()=>{api.mockResolvedValue({});render(<ProviderOverview organizationId="org"/>);expect(await screen.findByRole("alert")).toHaveTextContent("Provider status is unavailable");expect(screen.queryByText("Provider response recorded")).not.toBeInTheDocument();});
});
