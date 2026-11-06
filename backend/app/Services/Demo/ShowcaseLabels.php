<?php

namespace App\Services\Demo;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/** Display-name alignment for the showcase workspace. Identifiers stay put. */
class ShowcaseLabels
{
    public const ORGANIZATION_SLUG = 'mindco-showcase';

    public const ORGANIZATION_NAME = 'Demo Workspace';

    public const LEGACY_ORGANIZATION_NAME = 'MINDCo Demonstration';

    public const DOCUMENT_TEMPLATE_NAME = 'Demo application';

    public const LEGACY_DOCUMENT_TEMPLATE_NAME = 'MINDCo application';

    /**
     * Previous connection display names mapped to the current label.
     *
     * @var array<string, string>
     */
    public const CONNECTION_NAMES = [
        'MINDCo simulated Telegram' => 'Demo Telegram',
        'MINDCo simulated Slack' => 'Demo Slack',
        'MINDCo simulated applications mailbox' => 'Demo applications mailbox',
        'MINDCo simulated SMS' => 'Demo SMS',
        'MINDCo simulated OpenAI' => 'Demo OpenAI',
        'MINDCo exhausted synthetic budget' => 'Demo exhausted synthetic budget',
        'MINDCo simulated mailbox' => 'Demo mailbox',
        'MINDCo composed AI leave mailbox' => 'Demo AI leave mailbox',
        'MINDCo Phase 11 simulated mailbox' => 'Demo phase mailbox',
    ];

    /**
     * Rename showcase display labels in place.
     *
     * Only `organizations.name` and `connections.name` change, and only on the
     * organization whose slug is `mindco-showcase`. Workflows, requests, users,
     * memberships, secrets, and audit rows are not written. Document templates
     * are immutable, so a MINDCo template name is counted and left unchanged.
     *
     * @return array{organization: int, connections: int, skipped_document_templates: int}
     */
    public function align(): array
    {
        $organization = Organization::query()->where('slug', self::ORGANIZATION_SLUG)->first();
        if ($organization === null) {
            return ['organization' => 0, 'connections' => 0, 'skipped_document_templates' => 0];
        }

        $organizationUpdated = 0;
        if ($organization->name === self::LEGACY_ORGANIZATION_NAME) {
            $organization->forceFill(['name' => self::ORGANIZATION_NAME])->save();
            $organizationUpdated = 1;
        }

        $connections = 0;
        foreach (self::CONNECTION_NAMES as $from => $to) {
            $connections += DB::table('connections')
                ->where('organization_id', $organization->id)
                ->where('name', $from)
                ->update(['name' => $to, 'updated_at' => now()]);
        }

        $skipped = 0;
        if (DB::getSchemaBuilder()->hasTable('document_templates')) {
            $skipped = DB::table('document_templates')
                ->where('organization_id', $organization->id)
                ->where('name', 'like', 'MINDCo%')
                ->count();
        }

        return [
            'organization' => $organizationUpdated,
            'connections' => $connections,
            'skipped_document_templates' => $skipped,
        ];
    }

    /**
     * Current label plus any legacy label, so seed lookups still find existing rows.
     *
     * @return list<string>
     */
    public static function aliases(string $name): array
    {
        $names = [$name];
        foreach (self::CONNECTION_NAMES as $previous => $current) {
            if ($name === $current || $name === $previous) {
                $names[] = $previous;
                $names[] = $current;
            }
        }

        return array_values(array_unique($names));
    }
}
