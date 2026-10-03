#!/usr/bin/env bash
# Print the provisioned ADMIN_PASSWORD for a pilot deployment.
# Usage:
#   ./scripts/print-admin-password.sh taconazo.prod.localhost
#   ./scripts/print-admin-password.sh 48926f5b-2d1f-4d73-aed1-c47deb6682c4
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TARGET="${1:-}"
CP_URL="${CONTROL_PLANE_URL:-http://127.0.0.1:8099}"
PLATFORM_ENV="${PLATFORM_ENV:-dev}"
INGRESS_HTTP_PORT="${INGRESS_HTTP_PORT:-8088}"

if [ -z "$TARGET" ]; then
  echo "Usage: $0 <hostname|deployment_id>" >&2
  echo "Example: $0 taconazo.prod.localhost" >&2
  exit 1
fi

if [ -f "${ROOT}/.env" ]; then
  APP_SECRET_KEY="$(grep -E '^APP_SECRET_KEY=' "${ROOT}/.env" | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
fi
APP_SECRET_KEY="${APP_SECRET_KEY:-0123456789abcdef0123456789abcdef}"

export ROOT TARGET CP_URL APP_SECRET_KEY PLATFORM_ENV INGRESS_HTTP_PORT

node <<'NODE'
const crypto = require("crypto");
const fs = require("fs");
const http = require("http");
const path = require("path");

const target = process.env.TARGET;
const cp = process.env.CP_URL;
const secretKey = process.env.APP_SECRET_KEY;
const platformEnv = process.env.PLATFORM_ENV || "dev";
const ingressPort = process.env.INGRESS_HTTP_PORT || "8088";
const secretsRoot = path.join(process.env.ROOT, "data", platformEnv, "secrets");

function get(url) {
  return new Promise((resolve, reject) => {
    http.get(url, (res) => {
      const chunks = [];
      res.on("data", (c) => chunks.push(c));
      res.on("end", () => {
        const text = Buffer.concat(chunks).toString();
        if (res.statusCode >= 400) {
          reject(new Error(`${url} -> HTTP ${res.statusCode} ${text.slice(0, 200)}`));
          return;
        }
        resolve(JSON.parse(text));
      });
    }).on("error", reject);
  });
}

function decryptAdmin(depId) {
  const file = path.join(secretsRoot, depId, "ADMIN_PASSWORD.json");
  if (!fs.existsSync(file)) {
    throw new Error(`Missing ${file}`);
  }
  const payload = JSON.parse(fs.readFileSync(file, "utf8"));
  const key = crypto.createHash("sha256").update(secretKey).digest().subarray(0, 32);
  const decipher = crypto.createDecipheriv(
    "aes-256-gcm",
    key,
    Buffer.from(payload.iv, "base64"),
  );
  decipher.setAuthTag(Buffer.from(payload.tag, "base64"));
  return Buffer.concat([
    decipher.update(Buffer.from(payload.ciphertext, "base64")),
    decipher.final(),
  ]).toString();
}

(async () => {
  let depId = target;
  let hostname = "";
  let email = "";

  const uuidRe =
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
  if (!uuidRe.test(target)) {
    const tenants = (await get(`${cp}/tenants`)).tenants || [];
    let found = null;
    for (const t of tenants) {
      const detail = await get(`${cp}/tenants/${t.id}`);
      for (const d of detail.deployments || []) {
        if (d.hostname === target) {
          found = d;
          break;
        }
      }
      if (found) break;
    }
    if (!found) {
      throw new Error(`No deployment with hostname ${target}`);
    }
    depId = found.id;
    hostname = found.hostname;
  }

  const full = (await get(`${cp}/deployments/${depId}`)).deployment;
  hostname = hostname || full.hostname;
  const password = decryptAdmin(depId);

  const bundle = full.seed_bundle || "";
  const seedDir =
    bundle === "mexico-spanish" || bundle === "pizza-rin" ? "pizza-rin" : bundle || "taconazo";
  try {
    const manifestPath = path.join(process.env.ROOT, "seeds", seedDir, "manifest.json");
    if (fs.existsSync(manifestPath)) {
      email = JSON.parse(fs.readFileSync(manifestPath, "utf8")).admin?.email || "";
    }
  } catch (_) {}

  console.log(`Hostname:  ${hostname}`);
  console.log(`Admin URL: http://${hostname}:${ingressPort}/admin`);
  if (email) console.log(`Email:     ${email}`);
  console.log(`Password:  ${password}`);
})().catch((err) => {
  console.error(err.message || err);
  process.exit(1);
});
NODE
