<?php

namespace App\Services\Integrations;

use App\Support\DemoEnvironment;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;

class SmsQaTransport
{
    public const ACCOUNT = 'AC00000000000000000000000000000000';

    public const TOKEN = '00000000000000000000000000000000';

    public function assertLocal(): void
    {
        if (app()->environment('demo')) {
            app(DemoEnvironment::class)->assertOwned();

            return;
        }
        abort_unless(app()->environment('phase9d') && config('sms.simulated') && config('database.default') === 'pgsql' && in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost', '::1'], true) && config('database.connections.pgsql.database') === 'flowmv_phase9d_qa' && empty(config('database.connections.pgsql.url')), 503);
        $actual = DB::selectOne('select current_database() as name, host(inet_server_addr()) as address');
        abort_unless($actual->name === 'flowmv_phase9d_qa' && in_array($actual->address, ['127.0.0.1', '::1'], true), 503);
    }

    public function attach(SmsApi $api): void
    {
        $this->assertLocal();
        $api->http->preventStrayRequests();
        $api->http->fake(function ($r) {
            abort_unless($r->header('Authorization') === ['Basic '.base64_encode(self::ACCOUNT.':'.self::TOKEN)], 503);
            $base = 'https://api.twilio.com/2010-04-01/Accounts/'.self::ACCOUNT;
            if ($r->method() === 'GET' && $r->url() === $base.'.json') {
                return Factory::response(['sid' => self::ACCOUNT, 'status' => 'active']);
            }
            if ($r->method() === 'GET' && str_starts_with($r->url(), $base.'/IncomingPhoneNumbers.json?') && $r['PhoneNumber'] === '+15005550006') {
                return Factory::response(['incoming_phone_numbers' => [['account_sid' => self::ACCOUNT, 'phone_number' => '+15005550006', 'capabilities' => ['sms' => true], 'sid' => 'PN'.str_repeat('0', 32)]]]);
            }
            if ($r->method() === 'GET' && $r->url() === 'https://lookups.twilio.com/v2/PhoneNumbers/%2B15005550009') {
                return Factory::response(['valid' => true, 'phone_number' => '+15005550009', 'country_code' => 'US']);
            }
            if ($r->method() === 'POST' && $r->url() === $base.'/Messages.json' && $r['From'] === '+15005550006' && $r['To'] === '+15005550009') {
                return Factory::response(['account_sid' => self::ACCOUNT, 'sid' => 'SM'.bin2hex(random_bytes(16)), 'status' => 'queued'], 201);
            }
            if ($r->method() === 'GET' && preg_match('~^'.preg_quote($base, '~').'/Messages/(SM[0-9a-f]{32})\.json$~D', $r->url(), $match)) {
                return Factory::response(['account_sid' => self::ACCOUNT, 'sid' => $match[1], 'status' => 'delivered']);
            }
            abort(503);
        });
    }
}
