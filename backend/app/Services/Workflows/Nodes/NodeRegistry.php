<?php

namespace App\Services\Workflows\Nodes;

use App\Enums\WorkflowNodeType;
use App\Services\AI\AiNodes;
use App\Services\Integrations\EmailNodes;
use App\Services\Integrations\SlackNodes;
use App\Services\Integrations\SmsNodes;
use App\Services\Integrations\TelegramNodes;
use InvalidArgumentException;

/**
 * Versioned catalog of workflow node contracts.
 *
 * V1 covers the original five types with frozen handles and config shapes.
 * V2 adds notification, assignment, and delay. Published snapshots stamp the
 * registry version used at publish time. Execution never reinterprets a V1
 * graph under V2-only rules or types.
 */
class NodeRegistry
{
    public const V1 = 1;

    public const V2 = 2;

    public const V3 = 3;

    public const V4 = 4;

    public const AUTHORING = self::V4;

    public const CURRENT = self::V2;

    /**
     * @var array<string, list<string>>
     */
    private const HANDLES = [
        'start' => ['out'],
        'approval' => ['approved', 'rejected'],
        'condition' => ['true', 'false'],
        'revision' => ['return', 'exhausted'],
        'end' => [],
        'notification' => ['out'],
        'assignment' => ['out'],
        'delay' => ['out'],
        'integration_action' => ['out'],
        'sms_notification' => ['out'],
        'sms_send_message' => ['out'],
        'slack_trigger' => ['out'],
        'slack_filter' => ['matched', 'unmatched'],
        'slack_send_message' => ['out'],
        'slack_notification' => ['out'],
        'telegram_trigger' => ['out'],
        'telegram_filter' => ['matched', 'unmatched'],
        'telegram_send_message' => ['out'],
        'telegram_notification' => ['out'],
        'email_trigger' => ['out'],
        'email_filter' => ['matched', 'unmatched'],
        'email_send' => ['out'],
        'email_reply' => ['out'],
        'attachment_inspector' => ['inspected', 'missing', 'blocked'],
        'document_validation' => ['complete', 'missing', 'invalid', 'unknown'],
    ];

    /**
     * @return list<string>
     */
    public function typesFor(int $registryVersion): array
    {
        if (! in_array($registryVersion, [self::V1, self::V2, self::V3, self::V4], true)) {
            return [];
        }
        $types = array_map(
            fn (WorkflowNodeType $type) => $type->value,
            WorkflowNodeType::v1(),
        );

        if ($registryVersion >= self::V2) {
            foreach (WorkflowNodeType::v2Additions() as $type) {
                $types[] = $type->value;
            }
        }

        if ($registryVersion >= self::V3) {
            $types[] = 'integration_action';
            $types = [...$types, ...TelegramNodes::TYPES, ...SlackNodes::TYPES, ...SmsNodes::TYPES];
            $types = [...$types, ...EmailNodes::TYPES];
        }

        if ($registryVersion === self::V4) {
            $types = [...$types, ...array_keys(AiNodes::OPERATIONS)];
        }

        return $types;
    }

    public function allows(int $registryVersion, string $type): bool
    {
        return in_array($type, $this->typesFor($registryVersion), true);
    }

    /**
     * @return list<string>
     */
    public function sourceHandles(string $type): array
    {
        if (isset(AiNodes::OPERATIONS[$type])) {
            return AiNodes::HANDLES;
        }
        if (! array_key_exists($type, self::HANDLES)) {
            throw new InvalidArgumentException("Unknown node type [{$type}].");
        }

        return self::HANDLES[$type];
    }

    /**
     * @return array<string, list<string>>
     */
    public function allSourceHandles(int $registryVersion): array
    {
        $handles = [];

        foreach ($this->typesFor($registryVersion) as $type) {
            $handles[$type] = $this->sourceHandles($type);
        }

        return $handles;
    }

    public function introducedIn(string $type): int
    {
        if (isset(AiNodes::OPERATIONS[$type])) {
            return self::V4;
        }
        if ($type === 'integration_action' || in_array($type, SmsNodes::TYPES, true) || in_array($type, TelegramNodes::TYPES, true) || in_array($type, EmailNodes::TYPES, true) || in_array($type, SlackNodes::TYPES, true)) {
            return self::V3;
        }
        $enum = WorkflowNodeType::tryFrom($type);

        return $enum?->introducedIn() ?? PHP_INT_MAX;
    }
}
