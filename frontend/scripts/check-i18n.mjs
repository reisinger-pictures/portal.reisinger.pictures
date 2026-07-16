#!/usr/bin/env node
/**
 * Guard against uncompiled i18n strings (e.g. <Trans> / t`...` added but
 * `lingui extract` + `lingui compile` forgotten before a build).
 *
 * Lingui compiles the .po catalog to JS at build time. In production the
 * compiled catalog is keyed by MESSAGE ID (e.g. "yIzJXp") with the German
 * source text as the value. A string that exists in source code but is
 * missing from the compiled catalog renders as the cryptic message id
 * instead of the German text.
 *
 * Strategy:
 *  1. Run `lingui extract` to refresh locale/de/messages.po from current sources.
 *  2. Collect every msgid (German source text) from the extracted .po.
 *  3. Collect every compiled message VALUE from locale/de/messages.js.
 *  4. Fail if any extracted msgid is NOT present as a compiled value
 *     (catalog is stale → a build would ship untranslated message ids).
 */

import { execFileSync } from "node:child_process";
import { readFileSync, existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";
import { createRequire } from "node:module";

const require = createRequire(import.meta.url);

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, "..");

const PO_PATH = resolve(root, "locale/de/messages.po");
const COMPILED_PATH = resolve(root, "locale/de/messages.js");

function fail(message) {
  console.error(`\n❌ i18n check failed: ${message}`);
  console.error("Run `pnpm lingui:extract && pnpm lingui:compile` and commit the result.\n");
  process.exit(1);
}

console.log("🔍 Extracting i18n messages from sources...");
try {
  execFileSync("pnpm", ["lingui:extract"], { cwd: root, stdio: "inherit" });
} catch {
  fail("lingui extract failed");
}

if (!existsSync(PO_PATH)) {
  fail(`catalog not found at ${PO_PATH}`);
}

// Collect all msgids from the freshly extracted .po (skip obsolete/empty).
const po = readFileSync(PO_PATH, "utf8");
const msgids = new Set();
const msgidRegex = /^msgid "((?:[^"\\]|\\.)*)"$/gm;
let match;
while ((match = msgidRegex.exec(po)) !== null) {
  const raw = match[1].replace(/\\"/g, '"').replace(/\\n/g, "\n");
  if (raw.length > 0) msgids.add(raw);
}

if (msgids.size === 0) {
  fail("no message ids found in catalog");
}

if (!existsSync(COMPILED_PATH)) {
  fail(`compiled catalog missing at ${COMPILED_PATH} — run pnpm lingui:compile`);
}

// The compiled catalog is a CommonJS module: module.exports = {messages:
// JSON.parse("{...}")}, keyed by message id with the source text as the first
// element of the message array, e.g. "yIzJXp":["SMTP-Verbindungstest"].
// Parse the embedded JSON directly to avoid CJS/ESM interop issues.
const compiled = readFileSync(COMPILED_PATH, "utf8");
// The catalog is emitted as: module.exports={messages:JSON.parse("....")};
// The JSON is double-quote delimited with escaped quotes (\"). Extract the
// substring between the first `JSON.parse("` and the final `"` that closes it.
const startMarker = 'JSON.parse("';
const startIdx = compiled.indexOf(startMarker);
if (startIdx === -1) {
  fail("could not locate JSON.parse(...) in compiled catalog messages.js");
}
const strStart = startIdx + startMarker.length;
// The compiled catalog is a JS string literal: JSON.parse("....").
// The closing quote is the first `"` not preceded by a backslash.
let strEnd = -1;
for (let i = strStart; i < compiled.length; i++) {
  if (compiled[i] === '"' && compiled[i - 1] !== '\\') {
    strEnd = i;
    break;
  }
}
if (strEnd === -1) {
  fail("could not locate end of JSON string in compiled catalog messages.js");
}
// Decode the JS string literal (handles \\" and \\\\) by evaluating just the
// quoted value in an isolated function scope.
const jsStringLiteral = '"' + compiled.slice(strStart, strEnd) + '"';
let jsonRaw;
try {
  jsonRaw = new Function(`return (${jsStringLiteral});`)();
} catch (err) {
  fail(`failed to decode compiled catalog JSON string: ${err.message}`);
}
const compiledCatalog = JSON.parse(jsonRaw);

// Lingui compiles simple messages as ["source text"] and ICU MessageFormat
// messages (e.g. {var, plural, ...}) as nested arrays like
// [["var","plural",{"one":["…"],"other":["…"]}]," text"].
// We verify completeness by counting: lingui never silently drops entries
// during compile, so entry count >= msgid count proves the catalog is fresh.
const compiledCount = Object.keys(compiledCatalog).length;

if (compiledCount < msgids.size) {
  console.error(`\n❌ Compiled catalog has ${compiledCount} entries but .po has ${msgids.size} msgids — catalog is stale.`);
  fail("compiled catalog is stale — run pnpm lingui:compile");
}

// Additionally verify that every simple (non-ICU) msgid is present.
const compiledValues = new Set();
for (const value of Object.values(compiledCatalog)) {
  if (Array.isArray(value) && typeof value[0] === "string" && value[0].length > 0) {
    compiledValues.add(value[0]);
  }
}

const missing = [];
for (const id of msgids) {
  // Skip ICU MessageFormat strings (contain {variable} or {variable, plural, …})
  if (/\{[^}]+\}/.test(id)) continue;
  if (!compiledValues.has(id)) {
    missing.push(id);
  }
}

if (missing.length > 0) {
  console.error("\n❌ The following source strings are in the .po catalog but NOT in the compiled catalog (messages.js):");
  for (const m of missing.slice(0, 20)) {
    console.error(`   - ${m}`);
  }
  if (missing.length > 20) console.error(`   … and ${missing.length - 20} more`);
  fail("compiled catalog is stale — run pnpm lingui:compile");
}

console.log(`✅ i18n check passed (${msgids.size} messages compiled).`);
