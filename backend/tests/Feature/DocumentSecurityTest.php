<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\User;
use App\Services\Integrations\CredentialVault;
use App\Services\Integrations\DocumentPipeline;
use App\Services\Integrations\DocumentScanner;
use App\Services\Integrations\DocumentTemplates;
use App\Services\Integrations\EmailQaTransport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Workflows\InteractsWithExecution;
use Tests\TestCase;

class DocumentSecurityTest extends TestCase
{
    use InteractsWithExecution { setUp as protected spaSetUp; }
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->spaSetUp();
        config(['email_integrations.enabled' => true, 'integrations.keys.v1' => base64_encode(random_bytes(32))]);
        Storage::fake('integration_documents');
        $this->scanner('clean');
    }

    private function scanner(string $result): void
    {
        $this->app->instance(DocumentScanner::class, new class($result) implements DocumentScanner
        {
            public function __construct(private string $result) {}

            public function scan(#[\SensitiveParameter] string $bytes): string
            {
                return $this->result;
            }
        });
    }

    private function docx(string $text = 'Employee name: Synthetic Member', array $extra = []): string
    {
        $path = tempnam(storage_path('framework/testing'), 'docx');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $body = '';
        foreach (explode("\n", $text) as $line) {
            $body .= '<w:p><w:r><w:t>'.htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</w:t></w:r></w:p>';
        }
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>');
        foreach ($extra as $name => $value) {
            $zip->addFromString($name, $value);
        }
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private function fixture(): array
    {
        $org = $this->signInMember(OrganizationRole::Admin);
        $user = User::findOrFail($org->created_by);

        return [$org, $user, "/api/v1/organizations/{$org->uuid}/integrations/email"];
    }

    public function test_dry_run_is_private_deterministic_and_never_creates_requests_or_outbound_work(): void
    {
        [$org,$user,$base] = $this->fixture();
        $fields = [['id' => 'employee_name', 'label' => 'Employee name', 'type' => 'text', 'required' => true]];
        $template = app(DocumentTemplates::class)->create($org->id, $user->id, 'Review template', $fields);
        $doc = app(DocumentPipeline::class)->store($org->id, $user->id, 'preview.docx', $this->docx());
        $body = ['document_id' => $doc, 'template_id' => $template, 'rules' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'application']], 'match' => 'all', 'preview_inputs' => ['subject' => 'APPLICATION QA']];
        $this->postJson($base.'/preview', $body)->assertConflict();
        app(DocumentPipeline::class)->inspect($org->id, $doc);
        $this->postJson($base.'/preview', $body)->assertOk()->assertJsonPath('data.dry_run', true)->assertJsonPath('data.matched', true)->assertJsonPath('data.validation.status', 'complete')->assertJsonPath('data.external_delivery', false);
        $this->assertDatabaseCount('workflow_instances', 0);
        $this->assertDatabaseCount('email_action_outbox', 0);
        $this->assertDatabaseCount('email_intakes', 0);
        $this->signInMember(OrganizationRole::Admin);
        $this->postJson($base.'/preview', $body)->assertNotFound();
    }

    public function test_simulation_transport_refuses_every_normal_or_test_database(): void
    {
        $this->fixture();
        config(['email_integrations.simulated' => true]);
        try {
            app(EmailQaTransport::class)->assertLocal();
            $this->fail('Simulation accepted a non-QA database');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
    }

    public function test_docx_is_encrypted_scanned_extracted_and_downloaded_only_by_authorized_user(): void
    {
        [$org,$user,$base] = $this->fixture();
        $bytes = $this->docx();
        $id = $this->post($base.'/documents', ['document' => UploadedFile::fake()->createWithContent('application.docx', $bytes)])->assertCreated()->json('data.id');
        $this->get($base.'/documents/'.$id.'/download')->assertForbidden();
        $row = DB::table('private_documents')->where('id', $id)->first();
        $cipher = Storage::disk('integration_documents')->get($row->storage_key);
        $this->assertFalse(str_starts_with($cipher, "PK\x03\x04"));
        $this->assertStringNotContainsString(base64_encode($bytes), $cipher);
        $sealed = app(CredentialVault::class)->decrypt((object) ['ciphertext' => $cipher, 'key_version' => $row->key_version], 'document:'.$id);
        $restored = base64_decode($sealed['bytes'], true);
        $this->assertIsString($restored);
        $this->assertSame(hash('sha256', $bytes), hash('sha256', $restored));
        $this->assertStringNotContainsString('Synthetic Member', $cipher);
        $this->postJson($base.'/documents/'.$id.'/inspect')->assertOk()->assertJsonPath('data.status', 'extracted')->assertJsonPath('data.text', 'Employee name: Synthetic Member');
        $this->assertSame($bytes, $this->get($base.'/documents/'.$id.'/download')->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->getContent());
        $other = $this->addMember($org, OrganizationRole::Employee);
        $this->withCredentials(false);
        $this->signIn($other);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->withCredentials();
        $this->get($base.'/documents/'.$id.'/download')->assertNotFound();
        $this->postJson($base.'/documents/'.$id.'/inspect')->assertNotFound();
    }

    public function test_cross_tenant_download_and_traversal_or_spoofed_uploads_are_rejected(): void
    {
        [$org,$user,$base] = $this->fixture();
        $pipeline = app(DocumentPipeline::class);
        $id = $pipeline->store($org->id, $user->id, 'valid.docx', $this->docx());
        $pipeline->inspect($org->id, $id);
        foreach (['../escape.docx', 'C:\\escape.docx', 'wrong.pdf'] as $name) {
            try {
                $pipeline->store($org->id, $user->id, $name, $this->docx());
                $this->fail('Unsafe name or content accepted');
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
        $this->signInMember(OrganizationRole::Admin);
        $this->get($base.'/documents/'.$id.'/download')->assertNotFound();
    }

    public function test_scanner_outage_and_malware_fail_closed_and_never_allow_download(): void
    {
        [$org,$user,$base] = $this->fixture();
        $id = app(DocumentPipeline::class)->store($org->id, $user->id, 'application.docx', $this->docx());
        $this->scanner('unavailable');
        $this->postJson($base.'/documents/'.$id.'/inspect')->assertOk()->assertJsonPath('data.error_code', 'scanner_unavailable');
        $this->get($base.'/documents/'.$id.'/download')->assertForbidden();
        $this->scanner('infected');
        $this->postJson($base.'/documents/'.$id.'/inspect')->assertOk()->assertJsonPath('data.error_code', 'malware_detected');
        $this->scanner('clean');
        $this->postJson($base.'/documents/'.$id.'/inspect')->assertOk()->assertJsonPath('data.error_code', 'malware_detected');
        $this->get($base.'/documents/'.$id.'/download')->assertForbidden();
    }

    public function test_zip_traversal_external_relationships_macros_bombs_and_malformed_xml_stay_quarantined(): void
    {
        [$org,$user] = $this->fixture();
        foreach ([
            ['../escape.xml' => '<x/>'],
            ['word/vbaProject.bin' => 'synthetic macro marker'],
            ['word/_rels/document.xml.rels' => '<Relationships><Relationship TargetMode="&#69;xternal" Target="https://evil.example"/></Relationships>'],
            ['word/document.xml' => '<!DOCTYPE w [<!ENTITY x SYSTEM "file:///private">]><w>&x;</w>'],
            ['word/document.xml' => '<broken>private text'],
            ['word/bomb.xml' => '<x>'.str_repeat('x', 200000).'</x>'],
        ] as $extra) {
            $p = app(DocumentPipeline::class);
            $id = $p->store($org->id, $user->id, 'blocked.docx', $this->docx('Employee name: Synthetic', $extra));
            $result = $p->inspect($org->id, $id);
            $this->assertSame('unable_to_determine', $result['status']);
            $this->assertDatabaseHas('private_documents', ['id' => $id, 'status' => 'quarantined']);
            $this->assertStringNotContainsString('private text', json_encode($result));
        }
    }

    public function test_pdf_without_a_pinned_resource_limited_parser_remains_quarantined(): void
    {
        [$org,$user] = $this->fixture();
        config(['email_integrations.documents.container_runtime' => null, 'email_integrations.documents.pdf_image' => null]);
        $p = app(DocumentPipeline::class);
        $id = $p->store($org->id, $user->id, 'application.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $this->assertSame('parser_unavailable', $p->inspect($org->id, $id)['error_code']);
        $this->assertDatabaseHas('private_documents', ['id' => $id, 'status' => 'quarantined']);
    }

    public function test_validation_distinguishes_missing_invalid_unknown_and_complete_without_authenticity_claims(): void
    {
        $t = app(DocumentTemplates::class);
        $fields = [['id' => 'name', 'label' => 'Employee name', 'type' => 'text', 'required' => true], ['id' => 'employee_id', 'label' => 'Employee ID', 'type' => 'identifier', 'required' => true], ['id' => 'start', 'label' => 'Start date', 'type' => 'date', 'required' => true], ['id' => 'end', 'label' => 'End date', 'type' => 'date', 'required' => true, 'after_or_equal_to' => 'start']];
        $this->assertSame('missing_information', $t->validate($fields, [])['status']);
        $data = ['name' => 'Synthetic', 'employee_id' => 'QA-123', 'start' => '2026-02-30', 'end' => '2026-02-01'];
        $this->assertSame('invalid_information', $t->validate($fields, $data)['status']);
        $data['start'] = '2026-02-10';
        $this->assertContains('end', $t->validate($fields, $data)['invalid']);
        $data['end'] = '2026-02-20';
        $this->assertSame('complete', $t->validate($fields, $data)['status']);
        $extracted = $t->extract($fields, "Employee name: One\nEmployee name: Two");
        $this->assertSame(['name'], $extracted['uncertain']);
        $this->assertSame('unable_to_determine', $t->validate($fields, $extracted['values'], [], $extracted['uncertain'])['status']);
    }

    public function test_templates_are_immutable_and_key_rotation_preserves_private_document_evidence(): void
    {
        [$org,$user] = $this->fixture();
        $templates = app(DocumentTemplates::class);
        $template = $templates->create($org->id, $user->id, 'Generic application', [['id' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true]]);
        try {
            DB::table('document_templates')->where('id', $template)->update(['name' => 'Changed']);
            $this->fail('Immutable template changed');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        $p = app(DocumentPipeline::class);
        $bytes = $this->docx();
        $id = $p->store($org->id, $user->id, 'original.docx', $bytes);
        $p->inspect($org->id, $id);
        config(['integrations.keys.v2' => base64_encode(random_bytes(32)), 'integrations.active_key' => 'v2']);
        $this->artisan('integrations:rotate-keys')->assertSuccessful();
        $this->assertDatabaseHas('private_documents', ['id' => $id, 'key_version' => 'v2', 'extracted_key_version' => 'v2']);
        $this->assertSame($bytes, $p->download($user, $org->id, $id)['bytes']);
    }

    public function test_retention_expires_original_and_extracted_content(): void
    {
        [$org,$user] = $this->fixture();
        $p = app(DocumentPipeline::class);
        $id = $p->store($org->id, $user->id, 'expires.docx', $this->docx());
        $p->inspect($org->id, $id);
        $key = DB::table('private_documents')->where('id', $id)->value('storage_key');
        $this->travel(31)->days();
        $p->expire();
        $this->assertDatabaseHas('private_documents', ['id' => $id, 'status' => 'expired', 'extracted_ciphertext' => null]);
        Storage::disk('integration_documents')->assertMissing($key);
    }
}
