import { test } from "node:test";
import assert from "node:assert/strict";
import { join } from "node:path";
import { assertStaging, assertOwnedVolume, assertRuntimeSecrets, missingVolume } from "./staging-guards.mjs";
const base = join(process.cwd(), "backend/storage/app/phase12-staging");
const good = {
  PUBLIC_ORIGIN: "https://localhost:18443",
  FLOWMV_HTTPS_PORT: "18443",
  APP_ENV: "staging",
  DB_HOST: "postgres",
  DB_DATABASE: "flowmv_phase12_staging",
  DB_USERNAME: "flowmv_phase12_runtime",
  DB_OWNER_USER: "flowmv_phase12_migrator",
  FLOWMV_ENV_FILE: "backend/.env.staging",
  FLOWMV_CERT_DIR: join(base, "certs").replaceAll("\\", "/"),
  DB_PASSWORD: "a".repeat(64),
  DB_OWNER_PASSWORD: "b".repeat(64),
  STAGING_INSTALLATION: "c".repeat(64),
  AI_SERVICE_KEY: "d".repeat(64),
};
test("rejects hosted, showcase and wrong ownership targets before setup", () => {
  assert.doesNotThrow(() => assertStaging(good, base));
  for (const change of [
    { DB_HOST: "hosted.example" },
    { DB_DATABASE: "flowmv_phase10_demo" },
    { APP_ENV: "production" },
    { DB_USERNAME: "postgres" },
    { FLOWMV_CERT_DIR: "/outside" },
    { DB_PASSWORD: "injected'" },
  ])
    assert.throws(() => assertStaging({ ...good, ...change }, base));
});
test("refuses an existing unowned volume", () => {
  assert.throws(() => assertOwnedVolume({ Labels: {} }));
  assert.doesNotThrow(() =>
    assertOwnedVolume({
      Labels: { "com.flowmv.purpose": "phase12-isolated-staging" },
    }),
  );
});

test('runtime cannot inherit migrator credentials or expose vault data to Go',()=>{
 assert.throws(()=>assertRuntimeSecrets(['DB_OWNER_PASSWORD=synthetic'],'api'));
 assert.throws(()=>assertRuntimeSecrets(['INTEGRATION_KEYS=synthetic'],'ai-go'));
 assert.doesNotThrow(()=>assertRuntimeSecrets(['DB_USERNAME=flowmv_phase12_runtime'],'api'));
});

test('inventory errors are not treated as proof that a volume is absent',()=>{
 assert.equal(missingVolume({status:1,stderr:'no such volume'}),true);
 assert.equal(missingVolume({status:1,stderr:'permission denied'}),false);
 assert.equal(missingVolume({status:1,stderr:'daemon unavailable'}),false);
});
