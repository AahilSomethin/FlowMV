import https from "node:https";
import { readFileSync, writeFileSync, existsSync } from "node:fs";
import { resolve, join } from "node:path";
import { randomBytes, randomUUID } from "node:crypto";
import { execFileSync } from "node:child_process";
import { assertStaging } from "./staging-guards.mjs";
const root = resolve(import.meta.dirname, ".."),
  privateRoot = join(root, "backend/storage/app/phase12-staging");
const env = Object.fromEntries(
  readFileSync(join(root, "backend/.env.staging"), "utf8")
    .split(/\r?\n/)
    .filter(Boolean)
    .map((line) => {
      const i = line.indexOf("=");
      return [line.slice(0, i), line.slice(i + 1).replace(/^'|'$/g, "")];
    }),
);
assertStaging(env, privateRoot);
execFileSync("node", ["scripts/staging.mjs", "verify"], {
  cwd: root,
  stdio: "inherit",
  windowsHide: true,
});
const fixture = join(privateRoot, "smoke-account.json"),
  jar = new Map(),
  agent = new https.Agent({
    ca: readFileSync(join(privateRoot, "certs/web.crt")),
  });
async function request(path, method = "GET", data, key) {
  return new Promise((resolve, reject) => {
    const req = https.request(
      new URL(path, env.PUBLIC_ORIGIN),
      {
        agent,
        method,
        headers: {
          Accept: "application/json",
          Origin: env.PUBLIC_ORIGIN,
          Referer: env.PUBLIC_ORIGIN + "/",
          Cookie: [...jar].map(([k, v]) => k + "=" + v).join("; "),
          ...(method === "GET"
            ? {}
            : {
                "Content-Type": "application/json",
                "X-XSRF-TOKEN": decodeURIComponent(jar.get("XSRF-TOKEN") ?? ""),
              }),
          ...(key ? { "Idempotency-Key": key } : {}),
        },
      },
      (res) => {
        const parts = [];
        for (const c of res.headers["set-cookie"] ?? []) {
          const p = c.split(";")[0],
            i = p.indexOf("=");
          jar.set(p.slice(0, i), p.slice(i + 1));
        }
        res.on("data", (p) => parts.push(p));
        res.on("end", () => {
          let body;
          try {
            body = JSON.parse(Buffer.concat(parts).toString());
          } catch {}
          resolve({
            status: res.statusCode,
            headers: res.headers,
            body: body?.data ?? body,
          });
        });
      },
    );
    req.on("error", reject);
    req.setTimeout(30000, () => req.destroy(Error("Staging request deadline")));
    if (data) req.write(JSON.stringify(data));
    req.end();
  });
}
const ok = (r, code = 200) => {
  if (r.status !== code)
    throw Error("Staging smoke failed with status " + r.status);
  return r.body;
};
const anonymous = await request("/api/v1/me");
if (anonymous.status !== 401)
  throw Error("Anonymous private access was not denied");
ok(await request("/login"));
ok(await request("/sanctum/csrf-cookie"), 204);
let state;
if (existsSync(fixture)) {
  state = JSON.parse(readFileSync(fixture, "utf8"));
  ok(
    await request("/api/v1/auth/login", "POST", {
      email: state.email,
      password: state.password,
    }),
  );
} else {
  state = {
    email: "phase12.synthetic@example.com",
    password: randomBytes(24).toString("base64url"),
    instanceKey: randomUUID(),
  };
  ok(
    await request("/api/v1/auth/register", "POST", {
      name: "Isolated Staging Reviewer",
      email: state.email,
      password: state.password,
      password_confirmation: state.password,
    }),
    201,
  );
  writeFileSync(fixture, JSON.stringify(state), { flag: "wx", mode: 0o600 });
}
const save = () =>
  writeFileSync(fixture, JSON.stringify(state), { mode: 0o600 });
if (!state.organization) {
  state.organization = ok(
    await request("/api/v1/organizations", "POST", {
      name: "Phase 12 Isolated Staging",
    }),
    201,
  ).uuid;
  save();
}
ok(await request("/dashboard"));
const prefix = "/api/v1/organizations/" + state.organization;
if (!state.workflow) {
  state.workflow = ok(
    await request(prefix + "/workflows", "POST", {
      name: "Staging runtime smoke",
    }),
    201,
  ).uuid;
  save();
}
let workflow = ok(await request(prefix + "/workflows/" + state.workflow));
if (workflow.status === "draft") {
  const start = randomUUID(),
    end = randomUUID();
  const graph = {
    nodes: [
      {
        id: start,
        type: "start",
        position: { x: 0, y: 0 },
        data: { label: "Start", description: "" },
      },
      {
        id: end,
        type: "end",
        position: { x: 200, y: 0 },
        data: { label: "Completed", description: "", result: "completed" },
      },
    ],
    edges: [
      {
        id: randomUUID(),
        source: start,
        target: end,
        source_handle: "out",
        target_handle: "in",
      },
    ],
  };
  workflow = ok(
    await request(prefix + "/workflows/" + state.workflow + "/draft", "PUT", {
      revision: workflow.draft_revision,
      graph_definition: graph,
      form_definition: { fields: [] },
    }),
  );
  workflow = ok(
    await request(
      prefix + "/workflows/" + state.workflow + "/publish",
      "POST",
      { revision: workflow.draft_revision },
    ),
  );
}
if (!state.instance) {
  state.instance = ok(
    await request(
      prefix + "/workflows/" + state.workflow + "/instances",
      "POST",
      { data: {} },
      state.instanceKey,
    ),
    201,
  ).uuid;
  save();
}
const result = await request(prefix + "/instances/" + state.instance);
ok(result);
if (result.body.status !== "completed")
  throw Error("Runtime workflow did not complete");
if (!result.headers["cache-control"]?.includes("no-store"))
  throw Error("Private response was cacheable");
ok(await request("/api/v1/auth/logout", "POST", {}), 204);
if ((await request("/api/v1/me")).status !== 401)
  throw Error("Logout did not expire session");
console.log(
  JSON.stringify({
    target: "owned isolated staging",
    trusted_https: true,
    login_page: true,
    anonymous_denied: true,
    runtime_registration: true,
    runtime_publication: true,
    runtime_execution: "completed",
    private_no_store: true,
    logout_enforced: true,
    provider_calls: 0,
  }),
);
