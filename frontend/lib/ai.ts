import type {DocumentField,NodeType} from "@/lib/workflows/types";
export const AI_NODES = {
  ai_email_analysis: {operation:"analyze/email",title:"AI Email Analysis"},
  ai_document_analysis: {operation:"analyze/document",title:"AI Document Analysis"},
  ai_slack_analysis: {operation:"analyze/slack",title:"AI Slack Analysis"},
  ai_summarization: {operation:"summarize",title:"AI Summarization"},
  ai_classification: {operation:"classify",title:"AI Classification"},
  ai_field_extraction: {operation:"extract/fields",title:"AI Field Extraction"},
  ai_reply_draft: {operation:"draft/reply",title:"AI Reply Draft"},
} as const;
export type AiNodeType = keyof typeof AI_NODES;
export function isAiNode(type:NodeType):type is AiNodeType{return type in AI_NODES;}
export type AiConfiguration={input_fields:string[];fields:DocumentField[];categories:string[];routes:string[];data_categories:string[]};
export type AiPolicy={enabled:boolean;providers:string[];data_categories:string[];max_input_bytes:number;max_output_tokens:number;daily_organization_units:number;daily_user_units:number;daily_workflow_units:number;max_corrections:number;retention_days:number};
export type AiConnection={id:string;name:string;provider:"openai"|"gemini";ownership:"personal"|"organization";status:string;model:string;operations:string[];max_input_bytes:number;max_output_tokens:number;daily_units:number;approved:boolean;approval_requested:boolean;can_manage:boolean;can_approve:boolean;grants:{workflow_id:string;workflow_name:string;operation:string}[]};
export type AiCatalog={enabled:boolean;simulated:boolean;admin:boolean;personal_connections_allowed:boolean;policy:AiPolicy|null;models:Record<string,string[]>;operations:string[];items:AiConnection[];runs:{id:string;operation:string;status:string;provenance:string|null;requested_model:string;error_code:string|null}[];notice:string};
export type AiResult={id:string;status:string;operation:string;source:"simulated"|"provider_response"|"unknown";requested_model:string;resolved_model:string|null;error_code:string|null;expires_at:string|null;advice?:{suggestions?:{field_id:string;label:string;value:string}[];summary:string;classification:string;fields:Record<string,string>;missing:string[];inconsistent:string[];route:string;draft_reply:string;uncertain:boolean;outcome:string}};
export const AI_POLICY_DEFAULTS:AiPolicy={enabled:false,providers:[],data_categories:[],max_input_bytes:20000,max_output_tokens:1024,daily_organization_units:200000,daily_user_units:50000,daily_workflow_units:50000,max_corrections:2,retention_days:7};
export const AI_DATA_CATEGORIES=["workplace_request","email_body","document_text","slack_message","hr_sensitive","financial"];
