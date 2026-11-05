import { execFileSync } from "node:child_process";
import { assertStaging, assertOwnedVolume, assertRuntimeSecrets, missingVolume } from "./staging-guards.mjs";
import { randomBytes, createHash } from "node:crypto";
import { existsSync, readFileSync, writeFileSync, mkdirSync } from "node:fs";
import { resolve, join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const file = join(root, "backend/.env.staging"),
  privateRoot = join(root, "backend/storage/app/phase12-staging");
const action = process.argv[2] ?? "prepare",
  project = "flowmv-phase12-staging",
  container = project + "-postgres-1";
if (!["prepare", "up", "verify", "backup-restore", "stop"].includes(action))
  throw Error("Unsupported staging action; no reset is available.");
mkdirSync(privateRoot, { recursive: true });
const secret = () => randomBytes(32).toString("hex");
if (!existsSync(file)) {
  if (action !== "prepare")
    throw Error("Prepare the owned staging profile first.");
  const values = {
    APP_NAME: "FlowMV",
    APP_ENV: "staging",
    APP_DEBUG: "false",
    APP_URL: "https://localhost:18443",
    FRONTEND_URL: "https://localhost:18443",
    PUBLIC_ORIGIN: "https://localhost:18443",
    APP_KEY: "base64:" + randomBytes(32).toString("base64"),
    DB_CONNECTION: "pgsql",
    DB_HOST: "postgres",
    DB_PORT: "5432",
    DB_DATABASE: "flowmv_phase12_staging",
    DB_USERNAME: "flowmv_phase12_runtime",
    DB_PASSWORD: secret(),
    DB_OWNER_USER: "flowmv_phase12_migrator",
    DB_OWNER_PASSWORD: secret(),
    DB_SSLMODE: "disable",
    SESSION_DRIVER: "database",
    SESSION_COOKIE: "flowmv-release",
    SESSION_SECURE_COOKIE: "true",
    SESSION_SAME_SITE: "lax",
    SANCTUM_STATEFUL_DOMAINS: "localhost:18443",
    CACHE_STORE: "database",
    QUEUE_CONNECTION: "database",
    DB_QUEUE_RETRY_AFTER: "180",
    MAIL_MAILER: "array",
    LOG_CHANNEL: "stderr",
    LOG_LEVEL: "warning",
    INTEGRATION_ACTIVE_KEY: "v1",
    INTEGRATION_KEYS: JSON.stringify({
      v1: randomBytes(32).toString("base64"),
    }),
    AI_ENABLED: "false",
    AI_SIMULATED: "false",
    AI_SERVICE_URL: "https://ai-go:8020",
    AI_CA_CERT: "/certs/ai.crt",
    AI_SERVICE_KEY: secret(),
    OPS_HEARTBEAT_ENABLED: "true",
    FLOWMV_ENV_FILE: "backend/.env.staging",
    FLOWMV_CERT_DIR: join(privateRoot, "certs").replaceAll("\\", "/"),
    FLOWMV_HTTPS_PORT: "18443",
    STAGING_INSTALLATION: secret(),
  };
  writeFileSync(
    file,
    Object.entries(values)
      .map(([k, v]) => `${k}='${v}'`)
      .join("\n") + "\n",
    { flag: "wx", mode: 0o600 },
  );
}
const env = Object.fromEntries(
  readFileSync(file, "utf8")
    .split(/\r?\n/)
    .filter(Boolean)
    .map((line) => {
      const i = line.indexOf("=");
      return [line.slice(0, i), line.slice(i + 1).replace(/^'|'$/g, "")];
    }),
);
assertStaging(env, privateRoot);
const runtimeFile = join(root, 'backend/.env.staging.runtime');
const excluded = new Set(['DB_OWNER_PASSWORD', 'DB_OWNER_USER', 'STAGING_INSTALLATION', 'FLOWMV_ENV_FILE', 'FLOWMV_RUNTIME_ENV_FILE', 'FLOWMV_CERT_DIR', 'FLOWMV_HTTPS_PORT']);
writeFileSync(runtimeFile, Object.entries(env).filter(([key]) => !excluded.has(key)).map(([key,value]) => `${key}='${value}'`).join('\n')+'\n', {mode:0o600});

const compose = (args, stdio = "inherit") =>
  execFileSync(
    "docker",
    [
      "compose",
      "-p",
      project,
      "--env-file",
      file,
      "-f",
      "compose.production.yaml",
      ...args,
    ],
    { cwd: root, env: {...process.env,FLOWMV_RUNTIME_ENV_FILE:"backend/.env.staging.runtime",FLOWMV_DB_CA_CERT:join(privateRoot,"certs/ai.crt")}, stdio, windowsHide: true },
  );
const sql = (query, db = env.DB_DATABASE) =>
  execFileSync(
    "docker",
    [
      "exec",
      "-i",
      container,
      "psql",
      "-X",
      "-v",
      "ON_ERROR_STOP=1",
      "-U",
      env.DB_OWNER_USER,
      "-d",
      db,
      "-At",
    ],
    { input: query, encoding: "utf8", windowsHide: true },
  );
function guard() {
  const labels = JSON.parse(
    execFileSync(
      "docker",
      ["inspect", container, "--format", "{{json .Config.Labels}}"],
      { encoding: "utf8", windowsHide: true },
    ),
  );
  if (labels["com.docker.compose.project"] !== project)
    throw Error("Wrong container owner");
  const identity = sql(
    "select current_database()||'|'||current_user||'|'||coalesce(shobj_description(oid,'pg_database'),'') from pg_database where datname=current_database();",
  ).trim();
  if (
    identity !==
    `${env.DB_DATABASE}|${env.DB_OWNER_USER}|flowmv-stage:${createHash("sha256").update(env.STAGING_INSTALLATION).digest("hex")}`
  )
    throw Error("Staging database identity/installation mismatch");
}
if (action === "stop") {
  compose(["--profile", "staging", "stop"]);
  process.exit(0);
}
if (action === "prepare") {
  if (!existsSync(join(env.FLOWMV_CERT_DIR, "web.key")))
    execFileSync(
      "go",
      ["run", join(root, "tools/staging-tls/main.go"), env.FLOWMV_CERT_DIR],
      { cwd: root, stdio: "inherit", windowsHide: true },
    );
  let volume;
  try {
    volume = JSON.parse(
      execFileSync(
        "docker",
        ["volume", "inspect", project + "_staging_postgres"],
        {
          encoding: "utf8",
          stdio: ["ignore", "pipe", "pipe"],
          windowsHide: true,
        },
      ),
    )[0];
  } catch (error) {
    if (!missingVolume(error)) throw Error("Volume ownership inventory unavailable; startup refused");
  }
  assertOwnedVolume(volume);
  compose(["--profile", "staging", "up", "-d", "--wait", "postgres"]);
  const comment = sql(
    "select coalesce(shobj_description(oid,'pg_database'),'') from pg_database where datname=current_database();",
  ).trim();
  if (!comment) {
    if (
      sql(
        "select count(*) from pg_tables where schemaname='public';",
      ).trim() !== "0"
    )
      throw Error("Unmarked nonempty database refused");
    sql(
      `COMMENT ON DATABASE flowmv_phase12_staging IS 'flowmv-stage:${createHash("sha256").update(env.STAGING_INSTALLATION).digest("hex")}';`,
    );
  }
  guard();
  if (
    sql(
      "select count(*) from pg_roles where rolname='flowmv_phase12_runtime';",
    ).trim() === "0"
  )
    sql(
      `CREATE ROLE flowmv_phase12_runtime LOGIN PASSWORD '${env.DB_PASSWORD}' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;`,
    );
  compose([
    "--profile",
    "operations",
    "build",
    "api",
    "worker",
    "scheduler",
    "migrate",
    "web",
    "ai-go",
  ]);
  compose(["--profile", "operations", "run", "--rm", "--no-deps", "migrate"]);
  const grants = [
    "runtime-permissions.sql",
    "integration-runtime-permissions.sql",
  ]
    .map((name) =>
      readFileSync(join(root, "backend/database", name), "utf8").replaceAll(
        ":runtime_role",
        '"flowmv_phase12_runtime"',
      ),
    )
    .join("\n");
  sql(
    grants +
      "\nGRANT CONNECT ON DATABASE flowmv_phase12_staging TO flowmv_phase12_runtime; GRANT SELECT ON migrations TO flowmv_phase12_runtime;",
  );
  console.log(
    "Independent staging migration/role setup completed. No showcase or hosted target was modified.",
  );
}
if (action === "up") {
  guard();
  compose([
    "--profile",
    "staging",
    "up",
    "-d",
    "api",
    "worker",
    "scheduler",
    "ai-go",
    "web",
    "gateway",
  ]);
}
if (action === "verify") {
  guard();
  for (const service of ['api','worker','scheduler','web','ai-go','gateway']) {
    const entries=JSON.parse(execFileSync('docker',['inspect',project+'-'+service+'-1','--format','{{json .Config.Env}}'],{encoding:'utf8',windowsHide:true}));
    assertRuntimeSecrets(entries,service);
  }

  compose([
    "exec",
    "-T",
    "api",
    "php",
    "artisan",
    "operations:check",
    "--runtime-only",
    "--no-interaction",
  ]);
}
if (action === "backup-restore") {
  guard();
  const backup = join(privateRoot, "staging.dump");
  if (existsSync(backup))
    throw Error(
      "Existing backup preserved; choose a new explicitly reviewed rehearsal before repeating",
    );
  compose(["stop", "api", "worker", "scheduler"]);
  try {
    const dump = execFileSync(
      "docker",
      [
        "exec",
        container,
        "pg_dump",
        "-U",
        env.DB_OWNER_USER,
        "-d",
        env.DB_DATABASE,
        "-Fc",
      ],
      { maxBuffer: 64 * 1024 * 1024, windowsHide: true },
    );
    writeFileSync(backup, dump, { flag: "wx", mode: 0o600 });
    if (
      sql(
        "select count(*) from pg_database where datname='flowmv_phase12_restore';",
      ).trim() !== "0"
    )
      throw Error("Existing restore database preserved");
    sql(
      "CREATE DATABASE flowmv_phase12_restore OWNER flowmv_phase12_migrator;",
      "postgres",
    );
    execFileSync(
      "docker",
      [
        "exec",
        "-i",
        container,
        "pg_restore",
        "-U",
        env.DB_OWNER_USER,
        "-d",
        "flowmv_phase12_restore",
        "--no-owner",
        "--exit-on-error",
      ],
      { input: dump, stdio: ["pipe", "ignore", "pipe"], windowsHide: true },
    );
    const signature = (db) =>
      sql(
        "select string_agg(tablename,',' order by tablename) from pg_tables where schemaname='public';",
        db,
      ).trim();
    if (signature(env.DB_DATABASE) !== signature("flowmv_phase12_restore"))
      throw Error("Restored table inventory differs");
    const users = (db) => sql("select count(*) from users;", db).trim();
    if (users(env.DB_DATABASE) !== users("flowmv_phase12_restore"))
      throw Error("Restored user count differs");
    const tables = sql(
      "select tablename from pg_tables where schemaname='public' order by tablename;",
    )
      .trim()
      .split("\n");
    for (const table of tables) {
      const quoted = '"' + table.replaceAll('"', '""') + '"';
      const hash = (db) =>
        sql(
          "select md5(coalesce(string_agg(row_to_json(t)::text,'' order by row_to_json(t)::text),'')) from public." +
            quoted +
            " t;",
          db,
        ).trim();
      if (hash(env.DB_DATABASE) !== hash("flowmv_phase12_restore"))
        throw Error("Restored data checksum differs");
    }
    const evidence = {
      table_data_checksums_equal: true,
      tables_checked: tables.length,
      source: "owned isolated staging",
      restore: "flowmv_phase12_restore",
      sha256: createHash("sha256").update(dump).digest("hex"),
      bytes: dump.length,
      table_inventory_equal: true,
      user_count: Number(users(env.DB_DATABASE)),
      private_document_restore: "not exercised",
      point_in_time_recovery: "not exercised",
    };
    writeFileSync(
      join(privateRoot, "restore-evidence.json"),
      JSON.stringify(evidence, null, 2),
      { flag: "wx" },
    );
    console.log(JSON.stringify(evidence));
  } finally {
    compose(["up", "-d", "--no-deps", "api", "worker", "scheduler"]);
  }
}
