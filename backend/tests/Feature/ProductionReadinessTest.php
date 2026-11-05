<?php

namespace Tests\Feature;

use App\Jobs\OperationsHeartbeat;
use App\Support\EnvironmentChecker;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    private function secureConfiguration(): void
    {
        config(['app.url' => 'https://flowmv.example', 'flowmv.frontend_url' => 'https://flowmv.example', 'app.debug' => false,
            'queue.connections.database.retry_after' => 180, 'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax', 'queue.default' => 'database',
            'database.connections.pgsql.sslmode' => 'verify-full', 'mail.default' => 'smtp', 'integrations.active_key' => 'v1',
            'integrations.keys' => ['v1' => base64_encode(str_repeat('v', 32))], 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'ai.enabled' => false, 'ai.simulated' => false, 'demo.profile' => null, 'integrations.mock_enabled' => false,
            'integrations.telegram_simulated' => false, 'email_integrations.simulated' => false, 'slack.simulated' => false, 'sms.simulated' => false]);
    }

    public function test_production_check_fails_closed_without_leaking_configuration_values(): void
    {
        $this->secureConfiguration();
        $this->assertSame([], EnvironmentChecker::productionFindings());
        config(['flowmv.migrator_secret_present' => true, 'app.debug' => true, 'slack.simulated' => true, 'mail.default' => 'log', 'session.secure' => false]);
        $findings = EnvironmentChecker::productionFindings();
        $this->assertCount(5, $findings);
        $this->assertStringNotContainsString(config('app.key'), json_encode($findings));
    }

    public function test_vault_and_application_keys_must_be_independent_even_for_raw_keys(): void
    {
        $this->secureConfiguration();
        config(['app.key' => str_repeat('v', 32)]);
        $this->assertContains('Independent 32-byte vault key required', EnvironmentChecker::productionFindings());
    }

    public function test_delayed_worker_heartbeat_cannot_hide_a_queue_backlog(): void
    {
        config(['cache.default' => 'array', 'operations.heartbeat_enabled' => true]);
        $issued = time() - 600;
        (new OperationsHeartbeat($issued))->handle();
        $this->assertSame($issued, Cache::get('operations.worker'));
        $this->assertLessThan(time() - 180, Cache::get('operations.worker'));
    }

    public function test_service_signing_key_cannot_reuse_vault_bytes_in_another_encoding(): void
    {
        $this->secureConfiguration();
        config(['ai.service_key' => bin2hex(str_repeat('v', 32))]);
        $this->assertContains('AI signing key must be independent of vault and application keys', EnvironmentChecker::productionFindings());
    }
}
