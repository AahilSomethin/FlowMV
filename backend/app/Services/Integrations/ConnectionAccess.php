<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\DB;

class ConnectionAccess
{
    public function role(int $userId, int $organizationId): string
    {
        $role = DB::table('organization_memberships')->where('organization_id', $organizationId)->where('user_id', $userId)->whereNull('revoked_at')->lockForUpdate()->value('role');
        abort_unless(is_string($role), 404);

        return $role;
    }

    public function manage(int $userId, object $connection): void
    {
        abort_unless($connection->provider_id === 'local_mock', 403);
        $role = $this->role($userId, $connection->organization_id);
        abort_unless($connection->owner_user_id === null ? $role === 'admin' : (int) $connection->owner_user_id === $userId, 404);
    }

    public function create(int $userId, int $organizationId, string $ownership): void
    {
        abort_unless(in_array($ownership, ['personal', 'organization'], true), 422);
        $role = $this->role($userId, $organizationId);
        if ($ownership === 'organization') {
            abort_unless($role === 'admin', 403);
        } else {
            abort_unless(DB::table('integration_policies')->where('organization_id', $organizationId)->value('personal_connections_allowed'), 403, 'Personal connections require administrator approval.');
        }
        $this->approved($organizationId);
    }

    public function approved(int $organizationId): void
    {
        abort_unless(config('integrations.mock_enabled') && DB::table('integration_providers')->where('id', 'local_mock')->value('enabled') && DB::table('integration_approvals')->where('organization_id', $organizationId)->where('provider_id', 'local_mock')->value('approved'), 403, 'Integration is not approved.');
    }
}
