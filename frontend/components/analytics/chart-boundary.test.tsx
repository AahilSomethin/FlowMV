import { render, screen } from "@testing-library/react";
import { afterEach, expect, it, vi } from "vitest";
import { ChartBoundary } from "./chart-boundary";
afterEach(() => vi.restoreAllMocks());
it("preserves the report when an optional chart chunk fails", () => {
 vi.spyOn(console,"error").mockImplementation(()=>{});
 function Unavailable(): never { throw new Error("Synthetic chunk failure"); }
 render(<><ChartBoundary><Unavailable/></ChartBoundary><table><tbody><tr><td>Authorized report</td></tr></tbody></table></>);
 expect(screen.getByRole("alert")).toHaveTextContent("report remains available");
 expect(screen.getByText("Authorized report")).toBeInTheDocument();
});
