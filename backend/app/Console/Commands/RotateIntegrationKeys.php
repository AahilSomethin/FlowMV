<?php

namespace App\Console\Commands;

use App\Services\Integrations\ConnectionManager;
use App\Services\Integrations\CredentialVault;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
                    if (! $locked || $locked->key_version === config('integrations.active_key')) {
                        return;
                    }
                    DB::table('credential_secrets')->where('connection_id', $row->connection_id)->update([...$vault->encrypt($vault->decrypt($locked, $row->connection_id), $row->connection_id), 'updated_at' => now()]);
                    $manager->audit($connection->organization_id, $connection->id, null, 'credential.key_rotated');
                });
            }
        }, 'connection_id');
        $this->info('Credential re-encryption completed. Retain old keys for backups and outstanding OAuth requests.');

        return self::SUCCESS;
    }
}
