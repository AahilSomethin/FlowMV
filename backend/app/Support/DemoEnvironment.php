<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Server-side ownership proof; client flags can never enable demo transports. */
class DemoEnvironment
{
    public function assertOwned(): void
    {
        $profile = config('demo.profile');
        $host = config('database.connections.pgsql.host');
        $port = (string) config('database.connections.pgsql.port');
        $token = config('demo.installation_token');
        abort_unless(app()->environment('demo') && config('database.default') === 'pgsql' && config('database.connections.pgsql.database') === 'flowmv_phase10_demo' && config('database.connections.pgsql.username') === 'flowmv_demo_owner' && empty(config('database.connections.pgsql.url')) && is_string($token) && preg_match('/^[0-9a-f]{64}$/D', $token), 503, 'Demo environment ownership is unavailable.');
        abort_unless(($profile === 'native' && in_array($host, ['127.0.0.1', '::1'], true) && $port === '55490') || ($profile === 'container' && $host === 'postgres' && $port === '5432'), 503, 'Demo target refused.');
        $row = DB::selectOne("select current_database() as name, current_user as role, host(inet_server_addr()) as address, pg_get_userbyid(datdba) as owner, shobj_description(oid,'pg_database') as marker from pg_database where datname=current_database()");
        $address = $profile === 'native' ? in_array($row->address, ['127.0.0.1', '::1'], true) : $row->address === gethostbyname('postgres');
        abort_unless($row->name === 'flowmv_phase10_demo' && $row->role === 'flowmv_demo_owner' && $row->owner === 'flowmv_demo_owner' && $address && is_string($row->marker) && hash_equals('flowmv-demo:v1:'.hash('sha256', $token), $row->marker), 503, 'Demo installation marker does not match.');
        abort_unless(! config('ai.enabled') || config('ai.simulated'), 503, 'Demo AI transport must be simulated.');
        abort_unless(config('integrations.telegram_simulated') && config('slack.simulated') && config('sms.simulated') && config('email_integrations.simulated') && config('mail.default') === 'array', 503, 'All demo delivery transports must be simulated.');
    }
}
