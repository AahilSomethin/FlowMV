<?php

namespace App\Console\Commands;

use App\Services\Integrations\ConnectionManager;
use App\Services\Integrations\CredentialVault;
use App\Services\Integrations\DocumentPipeline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RotateIntegrationKeys extends Command
{
    protected $signature = 'integrations:rotate-keys';

    protected $description = 'Re-encrypt credentials with the active separately managed integration key';

    public function handle(CredentialVault $vault, ConnectionManager $manager): int
    {
        DB::table('credential_secrets')->chunkById(100, function ($rows) use ($vault, $manager) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row, $vault, $manager) {
                    $connection = DB::table('connections')->where('id', $row->connection_id)->lockForUpdate()->first();
                    $locked = DB::table('credential_secrets')->where('connection_id', $row->connection_id)->lockForUpdate()->first();
                    if (! $locked || $locked->ciphertext === null || $locked->key_version === config('integrations.active_key')) {
                        return;
                    }
                    DB::table('credential_secrets')->where('connection_id', $row->connection_id)->update([...$vault->encrypt($vault->decrypt($locked, $row->connection_id), $row->connection_id), 'updated_at' => now()]);
                    $manager->audit($connection->organization_id, $connection->id, null, 'credential.key_rotated');
                });
            }
        }, 'connection_id');
        foreach (['telegram_inbound_events' => 'event', 'integration_action_outbox' => 'action', 'slack_inbound_events' => 'event', 'slack_action_outbox' => 'action'] as $table => $kind) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            DB::table($table)->chunkById(100, function ($rows) use ($table, $kind, $vault, $manager) {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, $table, $kind, $vault, $manager) {
                        $locked = DB::table($table)->where('id', $row->id)->lockForUpdate()->first();
                        $connection = DB::table('connections')->where('id', $row->connection_id)->first();
                        if (! $locked || $locked->ciphertext === null || $locked->key_version === config('integrations.active_key')) {
                            return;
                        }
                        $context = $row->connection_id.':'.$kind.':'.$row->id;
                        DB::table($table)->where('id', $row->id)->update($vault->encrypt($vault->decrypt($locked, $context), $context));
                        $manager->audit($connection->organization_id, $connection->id, null, $connection->provider_id.'.payload.key_rotated');
                    });
                }
            }, 'id');
        }
        foreach (['ai_processing_runs' => ['id', 'ai-result:'], 'sms_policies' => ['organization_id', 'sms-policy:'], 'sms_senders' => ['id', 'sms-sender:'], 'sms_recipients' => ['id', 'sms-recipient:'], 'sms_action_outbox' => ['id', 'sms-action:'], 'telegram_approval_deliveries' => ['id', 'telegram-approval:'], 'telegram_decision_callbacks' => ['id', 'telegram-callback:'], 'slack_oauth_attempts' => ['state_hash', 'slack-oauth:'], 'email_oauth_attempts' => ['state_hash', 'email-oauth:'], 'email_sync_state' => ['connection_id', 'email-sync:'], 'email_fetch_queue' => ['id', 'email-fetch:'], 'email_attachment_fetches' => ['id', 'email-attachment:'], 'email_messages' => ['id', 'email-message:'], 'email_intakes' => ['id', 'email-intake:'], 'email_action_outbox' => ['id', 'email-action:']] as $table => [$primary,$prefix]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            DB::table($table)->orderBy($primary)->chunkById(50, function ($rows) use ($table, $primary, $prefix, $vault, $manager) {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, $table, $primary, $prefix, $vault, $manager) {
                        $locked = DB::table($table)->where($primary, $row->$primary)->lockForUpdate()->first();
                        if (! $locked || $locked->ciphertext === null || $locked->key_version === config('integrations.active_key')) {
                            return;
                        }
                        $context = $prefix.$row->$primary;
                        DB::table($table)->where($primary, $row->$primary)->update($vault->encrypt($vault->decrypt($locked, $context), $context));
                        if ($table === 'ai_processing_runs') {
                            $manager->audit($row->organization_id, $row->connection_id, null, 'ai.result.key_rotated');
                        }
                    });
                }
            }, $primary);
        }
        if (Schema::hasTable('private_documents')) {
            DB::table('private_documents')->orderBy('id')->chunkById(50, function ($rows) {
                foreach ($rows as $row) {
                    app(DocumentPipeline::class)->rotate($row->id);
                }
            }, 'id');
        }
        $this->info('Credential and payload re-encryption completed. Retain old keys for backups and outstanding OAuth requests.');

        return self::SUCCESS;
    }
}
