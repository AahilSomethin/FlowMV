<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\User;
use App\Services\Integrations\ConnectionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Workflows\InteractsWithExecution;
use Tests\TestCase;

class ConnectionOverviewTest extends TestCase
{
    use InteractsWithExecution;
    use RefreshDatabase;

    public function test_overview_preserves_personal_privacy_and_does_not_invent_historical_evidence(): void
    {
        $org = $this->signInMember(OrganizationRole::Admin);
        $other = User::factory()->create();
        foreach ([null, auth()->id(), $other->id] as $index => $owner) {
            $id = (string) Str::uuid();
            DB::table('connections')->insert(['id' => $id, 'organization_id' => $org->id, 'provider_id' => 'telegram', 'owner_user_id' => $owner, 'name' => 'Connection '.$index, 'credential_type' => 'bot_token', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('integration_audit_events')->insert(['organization_id' => $org->id, 'connection_id' => $id, 'event' => 'telegram.test', 'created_at' => now()]);
        }
        $response = $this->getJson("/api/v1/organizations/{$org->uuid}/connections/overview")->assertOk();
        $telegram = collect($response->json('data.providers'))->firstWhere('provider_id', 'telegram');
        $this->assertCount(2, $telegram['connections']);
        $this->assertNull($telegram['connections'][0]['last_operation']);
        $this->assertStringNotContainsString('Connection 2', $response->getContent());
        $this->assertStringNotContainsString('ciphertext', $response->getContent());
        $this->signInMember(OrganizationRole::Admin);
        $this->getJson("/api/v1/organizations/{$org->uuid}/connections/overview")->assertNotFound();
    }

    public function test_new_verified_operation_records_simulated_provenance_without_backfill(): void
    {
        $org = $this->signInMember(OrganizationRole::Admin);
        config(['integrations.telegram_simulated' => true]);
        $id = (string) Str::uuid();
        DB::table('connections')->insert(['id' => $id, 'organization_id' => $org->id, 'provider_id' => 'telegram', 'name' => 'Synthetic', 'credential_type' => 'bot_token', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
        app(ConnectionManager::class)->audit($org->id, $id, auth()->id(), 'telegram.test');
        $this->assertDatabaseHas('integration_audit_events', ['connection_id' => $id, 'evidence_source' => 'simulated']);
        $response = $this->getJson("/api/v1/organizations/{$org->uuid}/connections/overview")->assertOk();
        $telegram = collect($response->json('data.providers'))->firstWhere('provider_id', 'telegram');
        $this->assertSame('simulated', $telegram['connections'][0]['last_operation']['source']);
        $this->assertSame('telegram.test', $telegram['connections'][0]['last_operation']['operation']);
        $this->assertDatabaseCount('integration_audit_events', 1);
    }
}
