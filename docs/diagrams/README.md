# Specialist diagram sources

Primary guides embed the diagrams readers need:

- [Architecture](../architecture.md)
- [Workflows & integrations](../workflows-and-integrations.md)
- [Operations & security](../operations-and-security.md)
- [Showcase guide](../showcase-guide.md)

Markdown files in this folder remain specialist Mermaid sources (subsystem ERDs, detailed sequences). Prefer the primary guides for normal reading.

Render all docs Mermaid (primary guides + this folder):

```powershell
npm ci --prefix tools/docs
node scripts/render-diagrams.mjs
```

Rendered SVGs are ignored under `docs/diagrams/rendered`.
