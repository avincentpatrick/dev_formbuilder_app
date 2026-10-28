#!/usr/bin/env node
/**
 * Mints the two long-lived keys supabase-js sends to PostgREST on the testing box: an HS256 JWT
 * whose `role` claim is `anon` (NEXT_PUBLIC_SUPABASE_ANON_KEY) or `service_role`
 * (SUPABASE_SERVICE_ROLE_KEY), signed with the `jwt-secret` in PostgREST's config.
 *
 *     $env:BHW_JWT_SECRET = (Get-Content C:\meridian\bhw\postgrest\jwt-secret.txt -Raw).Trim()
 *     node db/mint-jwt.mjs --role=anon
 *
 * The secret comes from the environment only — never an argument, which a shell would keep in its
 * history — and must be at least 32 characters (PostgREST refuses shorter). The token is printed
 * and nothing else, so it can be redirected straight into the box's .env. Node's own crypto; no
 * dependency.
 */
import { createHmac } from "node:crypto";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";

export const ROLES = ["anon", "service_role"];
const YEAR_SECONDS = 365 * 24 * 60 * 60;

function base64url(value) {
  return Buffer.from(value).toString("base64url");
}

export function signJwt(payload, secret) {
  const unsigned = `${base64url(JSON.stringify({ alg: "HS256", typ: "JWT" }))}.${base64url(JSON.stringify(payload))}`;
  const signature = createHmac("sha256", secret).update(unsigned).digest("base64url");
  return `${unsigned}.${signature}`;
}

export function mintRoleToken(role, secret, { years = 10, now = Date.now() } = {}) {
  if (!ROLES.includes(role)) throw new Error(`role must be one of ${ROLES.join(", ")}`);
  if (typeof secret !== "string" || secret.length < 32) {
    throw new Error("the secret must be at least 32 characters (PostgREST refuses shorter)");
  }
  const iat = Math.floor(now / 1000);
  return signJwt({ role, iss: "bhw-connect", iat, exp: iat + years * YEAR_SECONDS }, secret);
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  try {
    const roleArg = process.argv.slice(2).find((arg) => arg.startsWith("--role="));
    if (!roleArg || process.argv.length !== 3)
      throw new Error("usage: node db/mint-jwt.mjs --role=anon|service_role");
    process.stdout.write(
      `${mintRoleToken(roleArg.slice("--role=".length), process.env.BHW_JWT_SECRET)}\n`,
    );
  } catch (error) {
    process.stderr.write(`mint-jwt: ${error.message}\n`);
    process.exit(2);
  }
}
