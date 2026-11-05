<?php

namespace App\Services\Workflows;

use App\Models\Organization;
use App\Models\User;
use App\Services\Integrations\EmailAuthorization;
use App\Services\Integrations\SlackAuthorization;
use App\Services\Integrations\TelegramAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Versioned recipes only. Creation never publishes, grants access or registers intake. */
class WorkflowTemplates
{
    public const VERSION = 1;

    private const RECIPES = [
        'leave-request' => ['Employee leave request', 'People', 'A Manager reviews dates and the employee request.', 'form', [], ['Request', 'Manager approval', 'Outcome']],
        'document-correction' => ['Incomplete document correction', 'Documents', 'Inspect private email evidence and return missing information for correction.', 'email', ['email'], ['Email', 'Document validation', 'Correction loop', 'Manager approval', 'Outcome reply']],
        'email-application' => ['Email application review', 'Operations', 'A recognized sender confirms intake before a Manager reviews the application.', 'email', ['email'], ['Email', 'Authenticated confirmation', 'Document validation', 'Manager approval', 'Outcome reply']],
        'telegram-approval' => ['Telegram approval and notification', 'Communication', 'Review in a linked private chat, using the ordinary FlowMV approval engine.', 'form', ['telegram'], ['Request', 'Manager approval via Telegram', 'Telegram notification']],
        'slack-intake' => ['Slack request intake', 'Communication', 'A linked member command starts an authorized Manager review.', 'slack', ['slack'], ['Slack command', 'Message rule', 'Manager approval', 'Slack outcome']],
        'expense-reimbursement' => ['Expense reimbursement', 'Finance', 'Manager review; requests above 1000 also require administrator approval.', 'form', [], ['Request', 'Manager approval', 'Amount condition', 'Additional approval if needed', 'Outcome']],
        'equipment-procurement' => ['Equipment procurement request', 'Operations', 'Approve equipment needs, then assign a fulfillment task.', 'form', [], ['Request', 'Manager approval', 'Fulfillment assignment', 'Outcome']],
        'it-access' => ['IT access request', 'IT', 'Manager review and an explicit provisioning task; no system access is granted automatically.', 'form', [], ['Request', 'Manager approval', 'Provisioning assignment', 'Outcome']],
        'onboarding-checklist' => ['HR onboarding checklist', 'People', 'Track Manager review and two authorized onboarding assignments.', 'form', [], ['Request', 'Manager approval', 'First assignment', 'Second assignment', 'Outcome']],
        'multi-stage-approval' => ['General multi-stage approval', 'General', 'An adaptable Manager and administrator review sequence.', 'form', [], ['Request', 'Manager approval', 'Administrator approval', 'Outcome']],
    ];

    public function catalog(): array
    {
        $items = [];
        foreach (self::RECIPES as $id => [$name,$category,$description,$trigger,$providers,$steps]) {
            $items[] = ['id' => $id, 'version' => self::VERSION, 'name' => $name, 'category' => $category, 'description' => $description, 'trigger' => $trigger, 'required_connections' => $providers, 'required_roles' => in_array($id, ['expense-reimbursement', 'multi-stage-approval'], true) ? ['manager', 'admin'] : (in_array($id, ['equipment-procurement', 'it-access', 'onboarding-checklist'], true) ? ['manager', 'employee'] : ['manager']), 'steps' => $steps, 'permissions' => $providers ? ['Approve the connection/destination, then grant each operation to the new draft before publishing.'] : ['Authors create drafts; only an administrator can publish.'], 'simulation' => 'Hypothetical preview; external steps require configured permissions.', 'binding_fields' => match ($id) {
                'telegram-approval','slack-intake' => ['connection_id', 'destination'],'document-correction','email-application' => ['connection_id', 'document_template_id', 'email_sender'],default => []
            }];
        }

        return $items;
    }

    public function recipe(string $id): array
    {
        abort_unless(isset(self::RECIPES[$id]), 404);

        return self::RECIPES[$id];
    }

    public function bind(User $user, Organization $org, string $id, array $b): array
    {
        $recipe = $this->recipe($id);
        $fields = collect($this->catalog())->firstWhere('id', $id)['binding_fields'];
        abort_unless(array_diff(array_keys($b), $fields) === [] && array_diff($fields, array_keys($b)) === [], 422);
        foreach ($b as $value) {
            abort_unless(is_string($value) && strlen($value) <= 254, 422);
        }
        if ($recipe[4] !== []) {
            abort_unless(Str::isUuid($b['connection_id'] ?? ''), 422);
            if ($recipe[4] === ['telegram']) {
                $c = app(TelegramAuthorization::class)->connection($org->id, $b['connection_id']);
                $destination = app(TelegramAuthorization::class)->destination($c, $b['destination']);
                abort_unless($destination->chat_type === 'private', 422);
            } elseif ($recipe[4] === ['slack']) {
                $c = app(SlackAuthorization::class)->connection($org->id, $b['connection_id']);
                abort_unless(DB::table('slack_channels')->where('connection_id', $c->id)->where('channel_id', $b['destination'])->exists(), 403);
            } else {
                $c = app(EmailAuthorization::class)->connection($org->id, $b['connection_id'], 'read');
                abort_unless(Str::isUuid($b['document_template_id']) && DB::table('email_destinations')->where('connection_id', $c->id)->where('address', $b['email_sender'])->exists(), 403);
                $template = DB::table('document_templates')->where('id', $b['document_template_id'])->where('organization_id', $org->id)->first();
                abort_unless($template, 404);
                $b['document_fields'] = json_decode($template->fields, true, flags: JSON_THROW_ON_ERROR);
            }
            abort_unless($c->owner_user_id === null || (int) $c->owner_user_id === $user->id, 403);
        }

        return $b;
    }

    public function definition(string $id, array $b = []): array
    {
        $this->recipe($id);
        if (in_array($id, ['document-correction', 'email-application'], true)) {
            return $this->email($b);
        }
        $nodes = [];
        $edges = [];
        $add = function (string $type, string $label, array $data = []) use (&$nodes): string {
            $id = (string) Str::uuid();
            $nodes[] = ['id' => $id, 'type' => $type, 'position' => ['x' => (count($nodes) % 3) * 300, 'y' => intdiv(count($nodes), 3) * 220], 'data' => ['label' => $label, 'description' => '', ...$data]];

            return $id;
        };
        $edge = function (string $a, string $handle, string $z) use (&$edges): void {
            $edges[] = ['id' => (string) Str::uuid(), 'source' => $a, 'target' => $z, 'source_handle' => $handle, 'target_handle' => 'in'];
        };
        $start = $id === 'slack-intake' ? $add('slack_trigger', 'Slack request', ['connection_id' => $b['connection_id'], 'destination' => $b['destination'], 'input_mapping' => ['details' => 'text']]) : $add('start', 'Request submitted');
        $approvalData = ['assignee_roles' => ['manager']];
        if ($id === 'telegram-approval') {
            $approvalData['telegram_approval'] = ['connection_id' => $b['connection_id'], 'destination' => $b['destination']];
        }
        $approval = $add('approval', 'Manager review', $approvalData);
        $complete = $add('end', 'Completed', ['result' => 'completed']);
        $reject = $add('end', 'Rejected', ['result' => 'rejected']);
        if ($id === 'slack-intake') {
            $filter = $add('slack_filter', 'Configured command', ['filter_field' => 'command', 'match_mode' => 'equals', 'match_value' => '/request']);
            $edge($start, 'out', $filter);
            $edge($filter, 'matched', $approval);
            $edge($filter, 'unmatched', $reject);
        } else {
            $edge($start, 'out', $approval);
        }
        $edge($approval, 'rejected', $reject);
        $last = $approval;
        $handle = 'approved';
        if (in_array($id, ['expense-reimbursement', 'multi-stage-approval'], true)) {
            $admin = $add('approval', 'Administrator review', ['assignee_roles' => ['admin']]);
            $edge($admin, 'rejected', $reject);
            $edge($admin, 'approved', $complete);
            if ($id === 'expense-reimbursement') {
                $condition = $add('condition', 'Additional approval needed', ['match' => 'all', 'rules' => [['id' => (string) Str::uuid(), 'field_id' => 'amount', 'operator' => 'greater_than', 'value' => 1000]]]);
                $edge($approval, 'approved', $condition);
                $edge($condition, 'true', $admin);
                $edge($condition, 'false', $complete);
            } else {
                $edge($approval, 'approved', $admin);
            }
        } else {
            if (in_array($id, ['equipment-procurement', 'it-access', 'onboarding-checklist'], true)) {
                $count = $id === 'onboarding-checklist' ? 2 : 1;
                for ($i = 0; $i < $count; $i++) {
                    $task = $add('assignment', $id === 'it-access' ? 'Provision authorized access' : ($i === 0 ? 'Prepare requested resources' : 'Complete onboarding checklist'), ['assignee_mode' => 'role', 'assignee_roles' => ['employee'], 'exclude_submitter' => false, 'instructions' => 'An authorized assignee completes this task in FlowMV. Configure the intended assignee before publishing.']);
                    $edge($last, $handle, $task);
                    $last = $task;
                    $handle = 'out';
                }
            }
            if (in_array($id, ['telegram-approval', 'slack-intake'], true)) {
                $type = $id === 'telegram-approval' ? 'telegram_notification' : 'slack_notification';
                $notify = $add($type, 'Approved outcome', ['connection_id' => $b['connection_id'], 'destination' => $b['destination'], 'text' => 'Request {{request.uuid}}: {{approval.decision}}', 'allowed_field_ids' => []]);
                $edge($last, $handle, $notify);
                $last = $notify;
                $handle = 'out';
            }
            $edge($last, $handle, $complete);
        }
        $fields = [['id' => 'details', 'type' => 'textarea', 'label' => 'Request details', 'description' => 'Share the information needed for review.', 'required' => true, 'default_value' => null, 'validation' => ['max_length' => 2000], 'options' => []]];
        if ($id === 'leave-request') {
            foreach (['start_date' => 'First day', 'end_date' => 'Last day'] as $key => $label) {
                $fields[] = ['id' => $key, 'type' => 'date', 'label' => $label, 'description' => '', 'required' => true, 'default_value' => null, 'validation' => [], 'options' => []];
            }
        }
        if ($id === 'expense-reimbursement') {
            $fields[] = ['id' => 'amount', 'type' => 'number', 'label' => 'Requested amount', 'description' => 'Use your organization currency.', 'required' => true, 'default_value' => null, 'validation' => ['min' => 1], 'options' => []];
        }

        return ['graph_definition' => ['nodes' => $nodes, 'edges' => $edges], 'form_definition' => ['fields' => $fields]];
    }

    private function email(array $b): array
    {
        $nodes = [];
        $edges = [];
        $add = function (string $type, string $label, array $data = []) use (&$nodes): string {
            $id = (string) Str::uuid();
            $nodes[] = ['id' => $id, 'type' => $type, 'position' => ['x' => (count($nodes) % 3) * 300, 'y' => intdiv(count($nodes), 3) * 220], 'data' => ['label' => $label, 'description' => '', ...$data]];

            return $id;
        };
        $edge = function (string $a, string $h, string $z) use (&$edges): void {
            $edges[] = ['id' => (string) Str::uuid(), 'source' => $a, 'target' => $z, 'source_handle' => $h, 'target_handle' => 'in'];
        };
        $fields = [];
        foreach ($b['document_fields'] as $f) {
            if ($f['type'] === 'supporting_document') {
                continue;
            }$fields[] = ['id' => $f['id'], 'type' => in_array($f['type'], ['date', 'number', 'select'], true) ? $f['type'] : 'text', 'label' => $f['label'], 'description' => 'Correct extracted information in FlowMV when requested.', 'required' => false, 'default_value' => null, 'validation' => [], 'options' => array_map(fn ($v) => ['label' => $v, 'value' => $v], $f['options'] ?? [])];
        }
        $mapping = array_column($fields, 'id');
        $trigger = $add('email_trigger', 'Recognized email intake', ['connection_id' => $b['connection_id'], 'input_mapping' => array_combine($mapping, array_map(fn ($v) => 'document.'.$v, $mapping)), 'rules' => [], 'match' => 'all']);
        $inspection = $add('attachment_inspector', 'Inspect private attachments', ['require_attachments' => true]);
        $validation = $add('document_validation', 'Check document information', ['template_id' => $b['document_template_id'], 'document_fields' => $b['document_fields'], 'field_map' => array_combine($mapping, $mapping), 'require_document' => true]);
        $approve = $add('approval', 'Manager review', ['assignee_roles' => ['manager']]);
        $replyData = ['connection_id' => $b['connection_id'], 'destination' => $b['email_sender'], 'allowed_field_ids' => []];
        $correction = $add('email_reply', 'Request corrections', [...$replyData, 'text' => 'Please correct: {{validation.missing}}. Sign in: {{request.link}}']);
        $revision = $add('revision', 'Authenticated correction', ['max_returns' => 3, 'return_to_node_id' => $validation]);
        $outcome = $add('email_reply', 'Approved outcome', [...$replyData, 'text' => 'Request {{request.uuid}}: {{approval.decision}}. {{request.link}}']);
        $completed = $add('end', 'Completed', ['result' => 'completed']);
        $rejected = $add('end', 'Rejected', ['result' => 'rejected']);
        $edge($trigger, 'out', $inspection);
        $edge($inspection, 'inspected', $validation);
        $edge($inspection, 'missing', $rejected);
        $edge($inspection, 'blocked', $rejected);
        $edge($validation, 'complete', $approve);
        $edge($validation, 'missing', $correction);
        $edge($validation, 'invalid', $correction);
        $edge($validation, 'unknown', $rejected);
        $edge($correction, 'out', $revision);
        $edge($revision, 'return', $validation);
        $edge($revision, 'exhausted', $rejected);
        $edge($approve, 'approved', $outcome);
        $edge($approve, 'rejected', $rejected);
        $edge($outcome, 'out', $completed);

        return ['graph_definition' => ['nodes' => $nodes, 'edges' => $edges], 'form_definition' => ['fields' => $fields]];
    }
}
