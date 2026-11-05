<?php

try {

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('staging') || config('database.connections.pgsql.host') !== 'postgres' || config('database.connections.pgsql.database') !== 'flowmv_phase12_staging') {
    throw new RuntimeException('Owned staging probe refused');
}
$identity = Illuminate\Support\Facades\DB::selectOne("select current_database() as database, current_user as role, shobj_description(oid,'pg_database') as marker from pg_database where datname=current_database()");
if ($identity->database !== 'flowmv_phase12_staging' || $identity->role !== 'flowmv_phase12_runtime' || ! preg_match('/^flowmv-stage:[a-f0-9]{64}$/D', $identity->marker ?? '')) {
    throw new RuntimeException('Staging database marker refused');
}
$result = app(App\Services\AI\AiClient::class)->call('extract/deterministic', [
    'contract' => 'flowmv.ai.v1', 'request_id' => 'staging-probe-'.bin2hex(random_bytes(8)),
    'organization' => 'staging', 'actor' => 'staging', 'connection' => 'staging', 'workflow' => 'staging',
    'operation' => 'extract/deterministic', 'provider' => '', 'model' => '',
    'credential' => '', 'text' => 'Employee ID: DEMO-PROBE',
    'fields' => [['id' => 'employee_id', 'label' => 'Employee ID', 'type' => 'identifier', 'required' => true]],
    'categories' => [], 'routes' => [], 'template' => 'workplace-v1', 'max_output_tokens' => 512,
]);
if (($result['values']['employee_id'] ?? null) !== 'DEMO-PROBE') {
    throw new RuntimeException('Signed TLS extraction did not match');
}
echo json_encode(['signed_private_tls' => true, 'certificate_verification' => true, 'deterministic_parity' => true, 'provider_calls' => 0], JSON_THROW_ON_ERROR).PHP_EOL;

} catch (Throwable) {
    fwrite(STDERR, "Staging signed TLS probe failed.\n");
    exit(1);
}
