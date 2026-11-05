<?php

/** Runs before Laravel boot, only inside the dedicated Compose initializer. */
$token = getenv('FLOWMV_DEMO_INSTALLATION_TOKEN');
if (getenv('APP_ENV') !== 'demo' || getenv('FLOWMV_DEMO_PROFILE') !== 'container' || getenv('DB_HOST') !== 'postgres' || getenv('DB_DATABASE') !== 'flowmv_phase10_demo' || getenv('DB_USERNAME') !== 'flowmv_demo_owner' || ! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token)) {
    throw new RuntimeException('Container demo bootstrap refused.');
}
$pdo = new PDO('pgsql:host=postgres;port=5432;dbname=flowmv_phase10_demo', 'flowmv_demo_owner', getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$row = $pdo->query("select current_database() as name, current_user as role, pg_get_userbyid(datdba) as owner, host(inet_server_addr()) as address, shobj_description(oid,'pg_database') as marker from pg_database where datname=current_database()")->fetch(PDO::FETCH_ASSOC);
$expected = 'flowmv-demo:v1:'.hash('sha256', $token);
if ($row['name'] !== 'flowmv_phase10_demo' || $row['owner'] !== 'flowmv_demo_owner' || $row['role'] !== 'flowmv_demo_owner' || $row['address'] !== gethostbyname('postgres')) {
    throw new RuntimeException('Container database ownership refused.');
}
if ($row['marker'] === null && (int) $pdo->query("select count(*) from pg_tables where schemaname='public'")->fetchColumn() === 0) {
    $pdo->exec('COMMENT ON DATABASE flowmv_phase10_demo IS '.$pdo->quote($expected));
} elseif (! is_string($row['marker']) || ! hash_equals($expected, $row['marker'])) {
    throw new RuntimeException('Container database marker mismatch. No changes made.');
}
echo "Container database ownership verified.\n";
