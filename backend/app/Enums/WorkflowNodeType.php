<?php

namespace App\Enums;

enum WorkflowNodeType: string
{
    case Start = 'start';
    case Approval = 'approval';
    case Condition = 'condition';
    case Revision = 'revision';
    case End = 'end';
    case Notification = 'notification';
    case Assignment = 'assignment';
    case Delay = 'delay';

    /**
     * Node types that existed before Phase 7. Historical published graphs are
     * stamped with registry V1 and must keep these semantics forever.
     *
     * @return list<self>
     */
    public static function v1(): array
    {
        return [
            self::Start,
            self::Approval,
            self::Condition,
            self::Revision,
            self::End,
        ];
    }

    /**
     * @return list<self>
     */
    public static function v2Additions(): array
    {
        return [
            self::Notification,
            self::Assignment,
            self::Delay,
        ];
    }

    public function introducedIn(): int
    {
        return match ($this) {
            self::Notification, self::Assignment, self::Delay => 2,
            default => 1,
        };
    }
}
