<?php

namespace App\Services\Workflows;

class WorkflowDefinitionHasher
{
    /**
     * @param  array<string, mixed>  $graph
     * @param  array<string, mixed>  $form
     */
    public function hash(array $graph, array $form): string
    {
        $payload = json_encode([
            'form_definition' => $this->canonicalize($form),
            'graph_definition' => $this->canonicalize($graph),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
