export type ConnectionEvidence={operation:string;source:"simulated"|"provider_response";at:string};

export type WorkflowTemplate={id:string;version:number;name:string;category:string;description:string;trigger:string;required_connections:string[];required_roles:string[];steps:string[];permissions:string[];simulation:string;binding_fields:string[]};

export type WorkflowPreviewResult={mode:"hypothetical";definition_hash:string;persisted:false;external_delivery:false;result:string;trace:{node_id:string;type:string;visit:number;status:string;branch:string|null;summary:string}[];skipped:string[];findings:{code:string;message:string}[]};

export type ProviderOverviewData={provider_id:string;name:string;path:string;setup:string;provider_approved:boolean;connections:{id:string;name:string;ownership:string;status:string;can_manage:boolean;last_operation:ConnectionEvidence|null}[]};
