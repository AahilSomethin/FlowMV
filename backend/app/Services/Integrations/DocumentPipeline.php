<?php

namespace App\Services\Integrations;

use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\Workflows\Execution\WorkflowInstanceVisibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentPipeline
{
    public function __construct(private CredentialVault $vault, private DocumentScanner $scanner, private DocumentExtractor $extractor, private ConnectionAccess $access, private ConnectionManager $audit) {}

    public function store(int $org, ?int $owner, string $name, #[\SensitiveParameter] string $bytes, ?string $messageId = null, ?string $documentId = null): string
    {
        abort_unless(config('email_integrations.enabled'), 403);
        abort_unless(preg_match('/^[\pL\pN _().-]{1,120}$/uD', $name) && ! str_contains($name, '..') && ! str_contains($name, '/') && ! str_contains($name, '\\'), 422);
        abort_if(strlen($bytes) === 0 || strlen($bytes) > (int) config('email_integrations.documents.max_bytes'), 413);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        abort_unless(($extension === 'pdf' && str_starts_with($bytes, '%PDF-') && $detected === 'application/pdf') || ($extension === 'docx' && str_starts_with($bytes, "PK\x03\x04") && in_array($detected, ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)), 422);
        $mime = $extension === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

        return DB::transaction(function () use ($org, $owner, $name, $bytes, $messageId, $documentId, $mime) {
            if ($owner !== null) {
                $this->access->role($owner, $org);
            }
            if ($messageId !== null) {
                $m = DB::table('email_messages')->where('organization_id', $org)->where('id', $messageId)->lockForUpdate()->first();
                abort_unless($m && ($owner === null || (int) $m->sender_user_id === $owner), 404);
            }
            $id = $documentId ?? (string) Str::uuid();
            abort_unless(Str::isUuid($id), 422);
            $existing = DB::table('private_documents')->where('id', $id)->first();
            if ($existing) {
                abort_unless((int) $existing->organization_id === $org && hash_equals($existing->content_hash, hash('sha256', $bytes)), 409);

                return $id;
            }
            if ($messageId !== null) {
                abort_if(DB::table('private_documents')->where('message_id', $messageId)->count() >= (int) config('email_integrations.documents.max_count'), 422);
            } elseif ($owner !== null) {
                abort_if(DB::table('private_documents')->where('owner_user_id', $owner)->where('created_at', '>', now()->subDay())->count() >= 20, 429);
            }
            $orgUuid = DB::table('organizations')->where('id', $org)->value('uuid');
            abort_unless(Str::isUuid($orgUuid), 404);
            $key = $orgUuid.'/'.$id.'.'.Str::random(16).'.enc';
            $encrypted = $this->vault->encrypt(['bytes' => base64_encode($bytes)], 'document:'.$id);
            Storage::disk('integration_documents')->put($key, $encrypted['ciphertext']);
            try {
                DB::table('private_documents')->insert(['id' => $id, 'organization_id' => $org, 'owner_user_id' => $owner, 'message_id' => $messageId, 'name' => $name, 'mime' => $mime, 'size' => strlen($bytes), 'content_hash' => hash('sha256', $bytes), 'storage_key' => $key, 'key_version' => $encrypted['key_version'], 'status' => 'quarantined', 'expires_at' => now()->addDays(min(90, max(1, (int) config('email_integrations.documents.retention_days')))), 'created_at' => now(), 'updated_at' => now()]);
            } catch (\Throwable $e) {
                Storage::disk('integration_documents')->delete($key);
                throw $e;
            }
            $this->audit->audit($org, null, $owner, 'document.quarantined');

            return $id;
        });
    }

    public function inspect(int $org, string $id): array
    {
        return DB::transaction(function () use ($org, $id) {
            $row = DB::table('private_documents')->where('organization_id', $org)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if (Carbon::parse($row->expires_at)->isPast()) {
                return ['status' => 'unable_to_determine', 'error_code' => 'document_expired'];
            }
            if ($row->error_code === 'malware_detected') {
                return ['status' => 'unable_to_determine', 'error_code' => 'malware_detected'];
            }
            if ($row->owner_user_id !== null) {
                $this->access->role($row->owner_user_id, $org);
            }
            if ($row->message_id !== null) {
                $message = DB::table('email_messages')->where('id', $row->message_id)->first();
                abort_unless($message, 404);
                app(EmailAuthorization::class)->connection($org, $message->connection_id, 'read');
            }
            if ($row->status === 'clean') {
                return $this->extracted($row);
            }
            $bytes = $this->bytes($row);
            $scan = $this->scanner->scan($bytes);
            if ($scan !== 'clean') {
                $code = $scan === 'infected' ? 'malware_detected' : 'scanner_unavailable';
                DB::table('private_documents')->where('id', $id)->update(['status' => 'quarantined', 'error_code' => $code, 'updated_at' => now()]);
                $this->audit->audit($org, null, null, 'document.scan.blocked');

                return ['status' => 'unable_to_determine', 'error_code' => $code];
            }
            $parent = storage_path('framework/integration-parsing');
            if (! is_dir($parent)) {
                mkdir($parent, 0700, true);
            }
            $dir = $parent.DIRECTORY_SEPARATOR.Str::uuid();
            mkdir($dir, 0711);
            $path = $dir.DIRECTORY_SEPARATOR.'source.'.($row->mime === 'application/pdf' ? 'pdf' : 'docx');
            try {
                $file = fopen($path, 'xb');
                if (! $file) {
                    throw new \RuntimeException('Private parser storage unavailable.');
                }
                try {
                    $written = fwrite($file, $bytes);
                    abort_unless($written === strlen($bytes), 503);
                } finally {
                    fclose($file);
                }
                chmod($path, 0444);
                $result = $this->extractor->extract($path, $row->mime);
            } finally {
                if (is_file($path)) {
                    chmod($path, 0600);
                    unlink($path);
                }
                if (is_dir($dir)) {
                    for ($attempt = 0; $attempt < 5 && is_dir($dir); $attempt++) {
                        clearstatcache(true, $dir);
                        if (@rmdir($dir)) {
                            break;
                        }
                        usleep(20000);
                    }
                    abort_if(is_dir($dir), 503, 'Private parser cleanup unavailable.');
                }
            }
            if ($result['status'] !== 'extracted') {
                DB::table('private_documents')->where('id', $id)->update(['status' => 'quarantined', 'error_code' => $result['error_code'], 'updated_at' => now()]);

                return ['status' => 'unable_to_determine', 'error_code' => $result['error_code']];
            }
            $encrypted = $this->vault->encrypt($result, 'document-extracted:'.$id);
            DB::table('private_documents')->where('id', $id)->update(['status' => 'clean', 'error_code' => null, 'extracted_ciphertext' => $encrypted['ciphertext'], 'extracted_key_version' => $encrypted['key_version'], 'updated_at' => now()]);
            $this->audit->audit($org, null, null, 'document.inspected');

            return $result;
        });
    }

    public function extracted(object $row): array
    {
        abort_unless($row->status === 'clean' && $row->extracted_ciphertext !== null && Carbon::parse($row->expires_at)->isFuture(), 403);

        return $this->vault->decrypt((object) ['ciphertext' => $row->extracted_ciphertext, 'key_version' => $row->extracted_key_version], 'document-extracted:'.$row->id);
    }

    public function download(User $user, int $org, string $id): array
    {
        return DB::transaction(function () use ($user, $org, $id) {
            $row = $this->authorize($user, $org, $id);
            abort_unless($row->status === 'clean' && Carbon::parse($row->expires_at)->isFuture(), 403);

            return ['bytes' => $this->bytes($row), 'name' => $row->name, 'mime' => $row->mime];
        });
    }

    public function authorize(User $user, int $org, string $id): object
    {
        $this->access->role($user->id, $org);
        $row = DB::table('private_documents')->where('organization_id', $org)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row, 404);
        if ($row->workflow_instance_id !== null) {
            $instance = WorkflowInstance::findOrFail($row->workflow_instance_id);
            abort_unless(app(WorkflowInstanceVisibility::class)->canView($user, $instance), 404);
        } elseif ((int) $row->owner_user_id !== $user->id) {
            $evidence = DB::table('workflow_document_evidence')->where('organization_id', $org)->where('document_id', $id)->pluck('workflow_instance_id');
            foreach ($evidence as $instanceId) {
                $instance = WorkflowInstance::find($instanceId);
                if ($instance && app(WorkflowInstanceVisibility::class)->canView($user, $instance)) {
                    return $row;
                }
            }
            $message = $row->message_id ? DB::table('email_messages')->where('id', $row->message_id)->first() : null;
            abort_unless($message, 404);
            app(EmailAuthorization::class)->manage($user->id, $org, $message->connection_id);
        }

        return $row;
    }

    private function bytes(object $row): string
    {
        $ciphertext = Storage::disk('integration_documents')->get($row->storage_key);
        $payload = $this->vault->decrypt((object) ['ciphertext' => $ciphertext, 'key_version' => $row->key_version], 'document:'.$row->id);
        $bytes = base64_decode($payload['bytes'], true);
        abort_unless(is_string($bytes) && strlen($bytes) === $row->size && hash_equals($row->content_hash, hash('sha256', $bytes)), 503);

        return $bytes;
    }

    public function expire(): void
    {
        DB::table('private_documents')->where('expires_at', '<=', now())->where('status', '!=', 'expired')->orderBy('id')->chunkById(50, function ($rows) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row) {
                    $locked = DB::table('private_documents')->where('id', $row->id)->lockForUpdate()->first();
                    if ($locked->status === 'expired' || Carbon::parse($locked->expires_at)->isFuture()) {
                        return;
                    }
                    Storage::disk('integration_documents')->delete($locked->storage_key);
                    DB::table('private_documents')->where('id', $locked->id)->update(['status' => 'expired', 'extracted_ciphertext' => null, 'extracted_key_version' => null, 'updated_at' => now()]);
                    $this->audit->audit($locked->organization_id, null, null, 'document.expired');
                });
            }
        }, 'id');
    }

    public function rotate(string $id): void
    {
        DB::transaction(function () use ($id) {
            $row = DB::table('private_documents')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->status === 'expired') {
                return;
            }
            $patch = [];
            $old = null;
            $new = null;
            try {
                if ($row->key_version !== config('integrations.active_key')) {
                    $bytes = $this->bytes($row);
                    $encrypted = $this->vault->encrypt(['bytes' => base64_encode($bytes)], 'document:'.$id);
                    $new = dirname($row->storage_key).'/'.$id.'.'.Str::random(16).'.enc';
                    Storage::disk('integration_documents')->put($new, $encrypted['ciphertext']);
                    $patch = ['storage_key' => $new, 'key_version' => $encrypted['key_version']];
                    $old = $row->storage_key;
                }
                if ($row->extracted_ciphertext !== null && $row->extracted_key_version !== config('integrations.active_key')) {
                    $plain = $this->vault->decrypt((object) ['ciphertext' => $row->extracted_ciphertext, 'key_version' => $row->extracted_key_version], 'document-extracted:'.$id);
                    $encrypted = $this->vault->encrypt($plain, 'document-extracted:'.$id);
                    $patch['extracted_ciphertext'] = $encrypted['ciphertext'];
                    $patch['extracted_key_version'] = $encrypted['key_version'];
                }
                if ($patch !== []) {
                    DB::table('private_documents')->where('id', $id)->update([...$patch, 'updated_at' => now()]);
                    $this->audit->audit($row->organization_id, null, null, 'document.key_rotated');
                    if ($old !== null) {
                        DB::afterCommit(fn () => Storage::disk('integration_documents')->delete($old));
                    }
                }
            } catch (\Throwable $e) {
                if ($new !== null) {
                    Storage::disk('integration_documents')->delete($new);
                }throw $e;
            }
        });
    }

    public function purgeOrphans(): void
    {
        foreach (Storage::disk('integration_documents')->allFiles() as $key) {
            if (! preg_match('#^[a-f0-9-]{36}/[a-f0-9-]{36}\.[A-Za-z0-9]{16}\.enc$#D', $key)) {
                continue;
            }
            if (Storage::disk('integration_documents')->lastModified($key) > now()->subDays(1)->timestamp) {
                continue;
            }
            if (! DB::table('private_documents')->where('storage_key', $key)->exists()) {
                Storage::disk('integration_documents')->delete($key);
            }
        }
    }
}
