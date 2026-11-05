<?php

namespace App\Console\Commands;

use App\Jobs\FetchEmailMessage;
use App\Jobs\ProcessEmailAttachment;
use App\Jobs\ProcessEmailDelivery;
use App\Jobs\SyncEmailMailbox;
use App\Services\Integrations\DocumentPipeline;
use App\Services\Integrations\EmailDelivery;
use App\Services\Integrations\EmailWorkflow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('integrations:recover-email')]
#[Description('Recover email synchronization and bounded idempotent fetch work')]
class RecoverEmailWork extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! config('email_integrations.enabled')) {
            return self::SUCCESS;
        }
        app(DocumentPipeline::class)->expire();
        app(DocumentPipeline::class)->purgeOrphans();
        app(EmailDelivery::class)->recover();
        foreach (DB::table('email_attachment_fetches')->where('status', 'pending')->where('available_at', '<=', now())->orderBy('available_at')->limit(100)->pluck('id') as $id) {
            ProcessEmailAttachment::dispatch($id);
        }
        foreach (DB::table('email_sync_state as s')->join('connections as c', 'c.id', '=', 's.connection_id')->where('c.status', 'enabled')->where('s.available_at', '<=', now())->whereNotNull('s.connection_id')->orderBy('s.available_at')->limit(100)->pluck('s.connection_id') as $id) {
            SyncEmailMailbox::dispatch($id);
        }
        DB::table('email_fetch_queue')->where('status', 'processing')->where('lease_expires_at', '<=', now())->update(['status' => 'pending', 'available_at' => now(), 'lease_expires_at' => null, 'error_code' => 'fetch_worker_recovered', 'updated_at' => now()]);
        foreach (DB::table('email_fetch_queue')->where('status', 'pending')->where('available_at', '<=', now())->orderBy('available_at')->limit(100)->pluck('id') as $id) {
            FetchEmailMessage::dispatch($id);
        }
        foreach (DB::table('email_action_outbox')->where('status', 'pending')->where('available_at', '<=', now())->orderBy('available_at')->limit(100)->pluck('id') as $id) {
            ProcessEmailDelivery::dispatch($id);
        }
        foreach (DB::table('email_messages')->where('status', 'pending')->where('expires_at', '>', now())->orderBy('received_at')->limit(100)->pluck('id') as $id) {
            app(EmailWorkflow::class)->ingest($id);
        }

        return self::SUCCESS;
    }
}
