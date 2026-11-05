<?php

namespace App\Services\Workflows\Execution;

class MessageFilter
{
    public function matches(array $config, array $event): bool
    {
        $value = $event[$config['filter_field']] ?? '';
        if (! is_string($value) || ! is_string($config['match_value'] ?? null)) {
            return false;
        }

        return match ($config['match_mode'] ?? '') {
            'equals' => $value === $config['match_value'],
            'contains' => str_contains($value, $config['match_value']),
            'starts_with' => str_starts_with($value, $config['match_value']),
            default => false,
        };
    }
}
