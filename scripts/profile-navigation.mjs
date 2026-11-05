import { readFileSync, writeFileSync, mkdirSync } from "node:fs";
import { resolve, join } from "node:path";
import { gzipSync } from "node:zlib";
const root = resolve(import.meta.dirname, ".."),
  phase = process.argv[2];
if (!["before", "after", "after-final"].includes(phase)) throw Error("Choose before or after");
const accounts = JSON.parse(
  readFileSync(
    join(root, "backend/storage/app/phase10-demo/native/accounts.json"),
    "utf8",
  ),
);
const account = accounts.accounts.find(
    (a) => a.email === "mindco.admin1@example.com",
  ),
  jar = new Map();
async function api(path, method = "GET", data) {
  const r = await fetch("http://localhost:8010" + path, {
    method,
    headers: {
      Accept: "application/json",
      Origin: "http://localhost:3010",
      Referer: "http://localhost:3010/",
      Cookie: [...jar].map(([k, v]) => k + "=" + v).join("; "),
      ...(method === "GET"
        ? {}
        : {
            "Content-Type": "application/json",
            "X-XSRF-TOKEN": decodeURIComponent(jar.get("XSRF-TOKEN") ?? ""),
          }),
    },
    body: data ? JSON.stringify(data) : undefined,
    signal: AbortSignal.timeout(30000),
  });
  for (const c of r.headers.getSetCookie()) {
    const p = c.split(";")[0],
      i = p.indexOf("=");
    jar.set(p.slice(0, i), p.slice(i + 1));
  }
  return { status: r.status, body: await r.json().catch(() => null) };
}
await api("/sanctum/csrf-cookie");
const login = await api("/api/v1/auth/login", "POST", {
  email: account.email,
  password: account.password,
});
if (login.status !== 200) throw Error("Owned demo login unavailable");
const prefix = "/api/v1/organizations/" + accounts.organization,
  guard = await api(prefix + "/demo-status");
if (guard.body?.data?.enabled !== true) throw Error("Owned demo guard refused");
const instances = await api(prefix + "/instances"),
  workflows = await api(prefix + "/workflows");
const instance = instances.body?.data?.items?.[0]?.uuid,
  workflow = workflows.body?.data?.items?.[0]?.uuid;
if (!instance || !workflow)
  throw Error("Authorized sample metadata unavailable");
const routes = [
  "/login",
  "/dashboard",
  "/tasks",
  "/requests",
  "/requests/" + instance,
  "/workflows",
  "/workflows/" + workflow + "/edit",
  "/connections",
  "/analytics",
  "/analytics/audit",
  "/team",
  "/settings",
];
const results = [];
for (const path of routes) {
  const times = [];
  let html = "";
  for (let i = 0; i < 4; i++) {
    const start = performance.now();
    const response = await fetch("http://localhost:3010" + path, {
      headers: { Cookie: [...jar].map(([k, v]) => k + "=" + v).join("; ") },
      signal: AbortSignal.timeout(30000),
    });
    html = await response.text();
    if (response.status !== 200)
      throw Error("Route unavailable: " + response.status);
    times.push(performance.now() - start);
  }
  const scripts = [
    ...new Set(
      [...html.matchAll(/<script[^>]+src="([^"]+)"/g)]
        .map((m) => m[1])
        .filter((u) => u.startsWith("/_next/")),
    ),
  ];
  let bytes = 0,
    gzip = 0;
  for (const src of scripts) {
    const response = await fetch("http://localhost:3010" + src, {
      signal: AbortSignal.timeout(30000),
    });
    const body = Buffer.from(await response.arrayBuffer());
    bytes += body.length;
    gzip += gzipSync(body).length;
  }
  const warm = times.slice(1).sort((a, b) => a - b);
  results.push({
    route: path.replace(/[0-9a-f-]{36}/g, ":id"),
    cold_process_request_ms: +times[0].toFixed(2),
    warm_request_median_ms: +warm[1].toFixed(2),
    html_bytes: Buffer.byteLength(html),
    initial_script_count: scripts.length,
    initial_javascript_bytes: bytes,
    estimated_gzip_javascript_bytes: gzip,
    modeled_120ms_rtt_256KiBps_serial_ms: +(
      (scripts.length + 1) * 120 +
      ((gzip + Buffer.byteLength(html)) / 262144) * 1000
    ).toFixed(2),
  });
}
await api("/api/v1/auth/logout", "POST", {});
const output = {
  phase,
  target: "owned native demo",
  samples_per_route: 4,
  concurrency: 1,
  results,
  limitations: [
    "HTML request timings are not browser route commits or hydration CPU. Warm requests use warm server state, not a browser cache.",
    "Initial script bytes exclude dynamically imported code and API data. Gzip and constrained-network values are estimates, not wire or emulated-browser measurements.",
    "No p95 with three warm samples. Browser timing API is unavailable through the current read-only browser surface.",
    "Node follows the normal authenticated session; no rate limits bypassed and no private response bodies stored.",
  ],
};
mkdirSync(join(root, "backend/storage/app/phase12-audit"), { recursive: true });
writeFileSync(
  join(root, "backend/storage/app/phase12-audit/navigation-" + phase + ".json"),
  JSON.stringify(output, null, 2),
  { flag: "wx" },
);
console.log(JSON.stringify(output));
