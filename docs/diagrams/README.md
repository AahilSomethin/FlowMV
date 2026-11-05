# Architecture diagrams

Diagrams summarize implemented classes, migrations and launch configuration. They omit private payloads and many nonessential columns. Solid paths describe implemented behavior; the production deployment diagram explicitly labels proposed components.

- [System context](system-context.md)
- [Component architecture](component-architecture.md)
- [Workflow execution](workflow-execution.md)
- [Approval and request states](approval-lifecycle.md)
- [Email and document processing](email-document-processing.md)
- [Telegram decisions](telegram-integration.md)
- [Slack and SMS](slack-sms-integration.md)
- [Credentials and permissions](credential-permissions.md)
- [Event delivery and recovery](event-delivery.md)
- [Database ERDs by subsystem](database-erd.md)
- [Implemented demo and proposed production deployment](deployment-topology.md)

From root, run `npm ci --prefix tools/docs` then `node scripts/render-diagrams.mjs --png`. It uses pinned Mermaid CLI 12.2.0 and renders ignored SVGs and optional PNG previews under `docs/diagrams/rendered`. The neutral CLI theme is configured in `tools/docs/mermaid-config.json`; Markdown viewers may use their own Mermaid theme. A compatible Puppeteer browser must be installed. Do not bypass browser sandbox protections for rendering. Syntax success alone is insufficient: inspect labels, clipping and subsystem relationships. See the Phase 10 report for actual render results.

- [AI boundaries, correction sequence, schema and native/container topology](ai-processing.md)

- [Phase 12 intent navigation, release candidate and recovery](phase12-operations.md)
