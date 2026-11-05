# Request and task lifecycles

These states come from `WorkflowInstanceStatus`, `WorkflowTaskStatus`, `WorkflowEngine` and task actions. The graph's end-node result determines completed/rejected. There is no fabricated automatic recovery transition out of failed.

```mermaid
stateDiagram-v2
  [*] --> running: authorized start
  running --> awaiting_approval: approval node
  awaiting_approval --> running: eligible decision
  running --> awaiting_assignment: assignment node
  awaiting_assignment --> running: authorized completion
  running --> awaiting_revision: revision node
  awaiting_revision --> running: validated submitter correction
  running --> awaiting_delay: delay node
  awaiting_delay --> running: due resume
  running --> completed: completed end
  running --> rejected: rejected end
  running --> failed: safe evaluation failure
  completed --> [*]
  rejected --> [*]
```

```mermaid
stateDiagram-v2
  [*] --> open: engine assigns task
  open --> completed: authorized approval / assignment / revision action
  completed --> [*]: immutable completed task
```

An approval decision may route to revision rather than rejection. An external provider callback still uses the same locked task action and eligibility checks.
