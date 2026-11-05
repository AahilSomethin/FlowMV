<?php

namespace App\Services\AI;

use App\Services\Integrations\DocumentTemplates;
use Illuminate\Support\Facades\Validator;

class AiResult
{
    public function validate(array $result, object $run, array $configuration): array
    {
        $provider = substr($run->provider_id, 3);
        abort_unless(($result['contract'] ?? '') === 'flowmv.ai.v1' && ($result['request_id'] ?? '') === $run->id && ($result['provider'] ?? '') === $provider && ($result['model'] ?? '') === $run->requested_model && ($result['prompt_version'] ?? '') === 'workplace-v1' && ($result['source'] ?? '') === (config('ai.simulated') ? 'simulated' : 'provider_response'), 503);
        $validated = Validator::make($result, ['input_tokens' => 'required|integer|min:0|max:262144', 'output_tokens' => 'required|integer|min:0|max:4096', 'advice' => 'required|array:summary,classification,fields,missing,inconsistent,route,draft_reply,recommendation,uncertain', 'advice.summary' => 'present|string|max:2000', 'advice.classification' => 'required|string|max:80', 'advice.fields' => 'present|array|max:20', 'advice.fields.*' => 'present|string|max:1000', 'advice.missing' => 'present|array|max:20', 'advice.inconsistent' => 'present|array|max:20', 'advice.route' => 'present|string|max:80', 'advice.draft_reply' => 'present|string|max:6000', 'advice.recommendation' => 'required|in:review,request_correction,continue_review', 'advice.uncertain' => 'required|boolean'])->validate();
        $advice = $validated['advice'];
        Validator::make($advice, ['missing.*' => 'required|string|max:80', 'inconsistent.*' => 'required|string|max:80'])->validate();
        $ids = array_column($configuration['fields'], 'id');
        abort_unless(array_diff(array_keys($advice['fields']), $ids) === [] && array_diff($advice['missing'], $ids) === [] && array_diff($advice['inconsistent'], $ids) === [] && in_array($advice['classification'], ['unknown', ...$configuration['categories']], true) && in_array($advice['route'], ['', ...$configuration['routes']], true), 503);
        $validation = $configuration['fields'] === [] ? ['status' => 'complete', 'missing' => [], 'invalid' => [], 'uncertain' => [], 'values' => []] : app(DocumentTemplates::class)->validate($configuration['fields'], $advice['fields'], [], $advice['inconsistent']);
        $advice['missing'] = array_values(array_unique([...$advice['missing'], ...$validation['missing']]));
        $advice['inconsistent'] = array_values(array_unique([...$advice['inconsistent'], ...$validation['invalid'], ...$validation['uncertain']]));
        $advice['uncertain'] = $advice['uncertain'] || $advice['inconsistent'] !== [];
        $advice['validation'] = $validation;
        $advice['outcome'] = $advice['uncertain'] ? 'uncertain' : ($advice['missing'] !== [] ? 'missing' : 'complete');

        return [...$validated, 'advice' => $advice];
    }
}
