<?php

namespace Tests\Feature;

use App\Services\AI\AiClient;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AiClientTest extends TestCase
{
    public function test_transport_signs_exact_bytes_and_avoids_global_http_events(): void
    {
        config(['ai.service_key' => str_repeat('a', 64), 'ai.service_url' => 'http://127.0.0.1:8020']);
        Event::fake([RequestSending::class]);
        $client = new AiClient;
        $audience = str_repeat('b', 64);
        $client->http->preventStrayRequests();
        $client->http->fake(function ($request) use ($audience) {
            if (str_ends_with($request->url(), '/readyz')) {
                return Factory::response(['contract' => 'flowmv.ai.v1', 'audience' => $audience]);
            }
            $time = $request->header('X-FlowMV-Time')[0];
            $nonce = $request->header('X-FlowMV-Nonce')[0];
            $expected = hash_hmac('sha256', "POST\n/v1/summarize\n$time\n$nonce\n$audience\n".hash('sha256', $request->body()), hex2bin(str_repeat('a', 64)));
            $this->assertSame([$expected], $request->header('X-FlowMV-Signature'));
            $this->assertSame(64, strlen($nonce));

            return Factory::response(['request_id' => 'synthetic']);
        });
        $this->assertSame(['request_id' => 'synthetic'], $client->call('summarize', ['text' => 'Synthetic input.']));
        $client->http->assertSentCount(2);
        Event::assertNotDispatched(RequestSending::class);
    }

    public function test_metadata_network_is_rejected_before_transport(): void
    {
        config(['ai.service_key' => str_repeat('a', 64), 'ai.service_url' => 'http://169.254.169.254']);
        $client = new AiClient;
        $client->http->preventStrayRequests();
        $this->expectException(HttpException::class);
        $client->call('summarize', []);
    }
}
