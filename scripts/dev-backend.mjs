import { spawn } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

const backend = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../backend");
const workersSupported = process.platform !== "win32";
const args = ["artisan", "serve", "--host=127.0.0.1", "--port=8000"];

if (workersSupported) {
  args.push("--no-reload");
}

const child = spawn("php", args, {
  cwd: backend,
  stdio: "inherit",
  env: {
    ...process.env,
    ...(workersSupported ? { PHP_CLI_SERVER_WORKERS: process.env.PHP_CLI_SERVER_WORKERS || "4" } : {}),
  },
});

child.on("exit", (code, signal) => {
  if (signal) {
    process.kill(process.pid, signal);
    return;
  }

  process.exit(code ?? 0);
});
