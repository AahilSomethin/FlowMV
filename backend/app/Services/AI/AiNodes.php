<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Validator;

class AiNodes
{
    public const OPERATIONS = ['ai_email_analysis' => 'analyze/email', 'ai_document_analysis' => 'analyze/document', 'ai_slack_analysis' => 'analyze/slack', 'ai_summarization' => 'summarize', 'ai_classification' => 'classify', 'ai_field_extraction' => 'extract/fields', 'ai_reply_draft' => 'draft/reply'];

    public const HANDLES = ['complete', 'missing', 'uncertain', 'unavailable'];

    public function normalize(string $type, array $data): array
    {
        abort_unless(isset(self::OPERATIONS[$type]) && array_diff(array_keys($data), ['label', 'description', 'connection_id', 'source_kind', 'document_field', 'configuration', 'field_map']) === [], 422);
        $clean = Validator::make($data, ['connection_id' => 'required|uuid', 'source_kind' => 'required|in:request,email,document,slack', 'document_field' => 'present|nullable|string|max:64', 'configuration' => 'required|array', 'field_map' => 'present|array|max:20', 'field_map.*' => 'required|string|max:64|distinct'])->validate();
        $required = ['ai_email_analysis' => 'email', 'ai_document_analysis' => 'document', 'ai_slack_analysis' => 'slack'][$type] ?? null;
        abort_unless($required === null || $clean['source_kind'] === $required, 422);
        $clean['configuration'] = app(AiProcessing::class)->configuration($clean['configuration']);
        abort_unless(array_diff(array_keys($clean['field_map']), array_column($clean['configuration']['fields'], 'id')) === [], 422);

        return $clean;
    }

    public function fieldsValid(array $data, array $form): bool
    {
        $fields = array_column($form['fields'], null, 'id');
        foreach ($data['configuration']['input_fields'] as $id) {
            if (! isset($fields[$id]) || $fields[$id]['type'] === 'file') {
                return false;
            }
        }
        if ($data['source_kind'] === 'request' && $data['configuration']['input_fields'] === []) {
            return false;
        }
        if ($data['source_kind'] === 'document' && $data['document_field'] !== null && ($fields[$data['document_field'] ?? '']['type'] ?? '') !== 'file') {
            return false;
        }
        foreach ($data['field_map'] as $target) {
            if (! isset($fields[$target]) || $fields[$target]['type'] === 'file') {
                return false;
            }
        }

        return true;
    }
}
