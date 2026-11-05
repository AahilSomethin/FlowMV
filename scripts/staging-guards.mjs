import { join } from "node:path";
export function assertStaging(env, privateRoot) {
  if (
    env.PUBLIC_ORIGIN !== "https://localhost:18443" ||
    env.FLOWMV_HTTPS_PORT !== "18443" ||
    env.APP_ENV !== "staging" ||
    env.DB_HOST !== "postgres" ||
    env.DB_DATABASE !== "flowmv_phase12_staging" ||
    env.DB_USERNAME !== "flowmv_phase12_runtime" ||
    env.DB_OWNER_USER !== "flowmv_phase12_migrator" ||
    env.FLOWMV_ENV_FILE !== "backend/.env.staging" ||
    env.FLOWMV_CERT_DIR !== join(privateRoot, "certs").replaceAll("\\", "/")
  )
    throw Error("Owned staging configuration refused.");
  for (const name of [
    "DB_PASSWORD",
    "DB_OWNER_PASSWORD",
    "STAGING_INSTALLATION",
    "AI_SERVICE_KEY",
  ])
    if (!/^[a-f0-9]{64}$/.test(env[name] ?? ""))
      throw Error("Malformed staging secret metadata");
}
export function assertOwnedVolume(volume) {
  if (
    volume &&
    volume.Labels?.["com.flowmv.purpose"] !== "phase12-isolated-staging"
  )
    throw Error("Unowned volume refused");
}

export function assertRuntimeSecrets(entries, service) {
 for (const entry of entries) {
  const key=entry.slice(0,entry.indexOf('='));
  if(['DB_OWNER_PASSWORD','DB_OWNER_USER','STAGING_INSTALLATION'].includes(key) && entry.slice(entry.indexOf('=')+1))throw Error('Migrator/bootstrap secret exposed to runtime');
  if(service==='ai-go' && (key.startsWith('DB_')||['APP_KEY','INTEGRATION_KEYS'].includes(key)))throw Error('Domain secret exposed to Go');
 }
}

export function missingVolume(error) {
 return error?.status === 1 && /no such volume/i.test(String(error.stderr ?? ''));
}
