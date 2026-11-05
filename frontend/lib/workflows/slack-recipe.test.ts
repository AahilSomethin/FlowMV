import {describe,it,expect} from "vitest";
import {slackReviewRecipe} from "./slack-recipe";
import {validateDefinition} from "./definition";
describe("Slack review draft",()=>{
  it("routes a configured command through ordinary Manager approval and sends only a minimal outcome",()=>{
    const draft=slackReviewRecipe("aabbccdd-1122-4455-8899-aabbccddeeff","C12345678");
    expect(validateDefinition(draft.graph_definition,draft.form_definition)).toEqual([]);
    expect(draft.graph_definition.nodes.find(n=>n.type==="approval")?.data.assignee_roles).toEqual(["manager"]);
    expect(draft.graph_definition.nodes.find(n=>n.type==="slack_notification")?.data.allowed_field_ids).toEqual([]);
    expect(JSON.stringify(draft)).not.toContain("access_token");
  });
});
