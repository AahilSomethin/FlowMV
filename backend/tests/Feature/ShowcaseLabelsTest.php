<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Demo\ShowcaseLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShowcaseLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_alignment_renames_only_the_showcase_display_labels(): void
    {
        $owner = User::factory()->create();
        $showcase = Organization::factory()->create([
            'name' => 'MINDCo Demonstration',
            'slug' => 'mindco-showcase',
            'created_by' => $owner->id,
        ]);
        $other = Organization::factory()->create([
            'name' => 'MINDCo Demonstration',
            'slug' => 'other-workspace',
            'created_by' => $owner->id,
        ]);
        $workflow = Workflow::factory()->create([
            'organization_id' => $showcase->id,
            'created_by' => $owner->id,
            'name' => 'Leave request',
        ]);
        $connectionId = (string) Str::uuid();
        DB::table('connections')->insert([
            'id' => $connectionId,
            'organization_id' => $showcase->id,
            'provider_id' => 'local_mock',
            'name' => 'MINDCo simulated Telegram',
            'credential_type' => 'bot_token',
            'status' => 'enabled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $templateId = (string) Str::uuid();
        DB::table('document_templates')->insert([
            'id' => $templateId,
            'organization_id' => $showcase->id,
            'created_by' => $owner->id,
            'name' => 'MINDCo application',
            'fields' => json_encode([['id' => 'employee_name', 'label' => 'Employee name']], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        $first = app(ShowcaseLabels::class)->align();
        $second = app(ShowcaseLabels::class)->align();

        $this->assertSame(['organization' => 1, 'connections' => 1, 'skipped_document_templates' => 1], $first);
        $this->assertSame(['organization' => 0, 'connections' => 0, 'skipped_document_templates' => 1], $second);
        $this->assertSame('Demo Workspace', $showcase->fresh()->name);
        $this->assertSame('mindco-showcase', $showcase->fresh()->slug);
        $this->assertSame('MINDCo Demonstration', $other->fresh()->name);
        $this->assertSame('Demo Telegram', DB::table('connections')->where('id', $connectionId)->value('name'));
        $this->assertSame($connectionId, DB::table('connections')->where('organization_id', $showcase->id)->value('id'));
        $this->assertSame('MINDCo application', DB::table('document_templates')->where('id', $templateId)->value('name'));
        $this->assertSame($workflow->uuid, $workflow->fresh()->uuid);
        $this->assertSame('Leave request', $workflow->fresh()->name);
        $this->assertSame(1, Workflow::query()->count());
        $this->assertSame(1, User::query()->count());
    }
}
