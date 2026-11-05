<?php

namespace Tests\Feature;

use App\Support\DemoEnvironment;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DemoEnvironmentTest extends TestCase
{
    private function demoConfig(): void
    {
        $this->app->detectEnvironment(fn () => 'demo');
        config(['demo.profile' => 'native', 'demo.installation_token' => str_repeat('a', 64), 'database.default' => 'pgsql', 'database.connections.pgsql.database' => 'flowmv_phase10_demo', 'database.connections.pgsql.username' => 'flowmv_demo_owner', 'database.connections.pgsql.url' => '', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 55490, 'integrations.telegram_simulated' => true, 'slack.simulated' => true, 'sms.simulated' => true, 'email_integrations.simulated' => true, 'mail.default' => 'array']);
    }

    public function test_hosted_or_existing_qa_target_is_refused_before_connecting(): void
    {
        $this->demoConfig();
        config(['database.connections.pgsql.host' => 'db.example.supabase.co', 'database.connections.pgsql.database' => 'flowmv_phase9d_qa']);
        DB::shouldReceive('selectOne')->never();
        $this->expectException(HttpException::class);
        app(DemoEnvironment::class)->assertOwned();
    }

    public function test_native_target_requires_database_owner_address_and_secret_marker(): void
    {
        $this->demoConfig();
        $row = (object) ['name' => 'flowmv_phase10_demo', 'role' => 'flowmv_demo_owner', 'owner' => 'flowmv_demo_owner', 'address' => '127.0.0.1', 'marker' => 'flowmv-demo:v1:'.hash('sha256', str_repeat('a', 64))];
        DB::shouldReceive('selectOne')->twice()->andReturn($row, (object) [...get_object_vars($row), 'marker' => 'wrong']);
        app(DemoEnvironment::class)->assertOwned();
        $this->expectException(HttpException::class);
        app(DemoEnvironment::class)->assertOwned();
    }

    public function test_demo_refuses_any_real_mail_transport(): void
    {
        $this->demoConfig();
        config(['mail.default' => 'smtp']);
        DB::shouldReceive('selectOne')->once()->andReturn((object) ['name' => 'flowmv_phase10_demo', 'role' => 'flowmv_demo_owner', 'owner' => 'flowmv_demo_owner', 'address' => '127.0.0.1', 'marker' => 'flowmv-demo:v1:'.hash('sha256', str_repeat('a', 64))]);
        $this->expectException(HttpException::class);
        app(DemoEnvironment::class)->assertOwned();
    }
}
