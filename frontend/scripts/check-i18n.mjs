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
import { readFileSync, existsSync, readdirSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";
import { createRequire } from "node:module";

const require = createRequire(import.meta.url);
const ts = require("typescript");

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, "..");

/**
 * Collect TypeScript source files without following generated directories.
 * Lingui extraction covers the same `src` tree, so the scope guard uses the
 * same input set instead of maintaining a second, drift-prone file list.
 */
function collectTypeScriptFiles(directory) {
  const files = [];
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const entryPath = resolve(directory, entry.name);
    if (entry.isDirectory()) {
      files.push(...collectTypeScriptFiles(entryPath));
    } else if (/\.(ts|tsx)$/.test(entry.name)) {
      files.push(entryPath);
    }
  }
  return files;
}

function isFunctionLike(node) {
  return ts.isFunctionDeclaration(node)
    || ts.isFunctionExpression(node)
    || ts.isArrowFunction(node)
    || ts.isMethodDeclaration(node)
    || ts.isGetAccessorDeclaration(node)
    || ts.isSetAccessorDeclaration(node)
    || ts.isConstructorDeclaration(node);
}

function unwrapExpression(node) {
  let expression = node;
  if (!expression) {
    return expression;
  }
  while (
    ts.isParenthesizedExpression(expression)
    || ts.isAsExpression(expression)
    || ts.isTypeAssertionExpression(expression)
    || ts.isSatisfiesExpression(expression)
    || ts.isNonNullExpression(expression)
  ) {
    expression = expression.expression;
  }
  return expression;
}

function isLinguiMacroTag(node, macroBindings) {
  return ts.isTaggedTemplateExpression(node)
    && ts.isIdentifier(node.tag)
    && macroBindings.has(node.tag.text);
}

function collectLinguiMacroBindings(sourceFile) {
  const bindings = new Set();
  for (const statement of sourceFile.statements) {
    if (!ts.isImportDeclaration(statement) || !ts.isStringLiteral(statement.moduleSpecifier)) {
      continue;
    }
    if (statement.moduleSpecifier.text !== "@lingui/core/macro" || statement.importClause?.isTypeOnly) {
      continue;
    }

    const namedBindings = statement.importClause?.namedBindings;
    if (!namedBindings || !ts.isNamedImports(namedBindings)) {
      continue;
    }
    for (const specifier of namedBindings.elements) {
      const importedName = specifier.propertyName?.text ?? specifier.name.text;
      if (!specifier.isTypeOnly && importedName === "t") {
        bindings.add(specifier.name.text);
      }
    }
  }
  return bindings;
}

function collectModuleScopeFunctionBindings(sourceFile) {
  const bindings = new Map();
  for (const statement of sourceFile.statements) {
    if (ts.isFunctionDeclaration(statement) && statement.name) {
      bindings.set(statement.name.text, statement);
      continue;
    }
    if (!ts.isVariableStatement(statement)) {
      continue;
    }

    for (const declaration of statement.declarationList.declarations) {
      if (
        ts.isIdentifier(declaration.name)
        && declaration.initializer
        && isFunctionLike(declaration.initializer)
      ) {
        bindings.set(declaration.name.text, declaration.initializer);
      }
    }
  }
  return bindings;
}

function functionContainsLinguiMacro(functionNode, macroBindings) {
  let containsMacro = false;

  function visit(node) {
    if (containsMacro) {
      return;
    }
    if (isLinguiMacroTag(node, macroBindings)) {
      containsMacro = true;
      return;
    }
    if (node !== functionNode && isFunctionLike(node)) {
      return;
    }
    ts.forEachChild(node, visit);
  }

  visit(functionNode);
  return containsMacro;
}

function getImmediatelyInvokedFunction(node) {
  if (!ts.isCallExpression(node)) {
    return undefined;
  }
  const callee = unwrapExpression(node.expression);
  return isFunctionLike(callee) ? callee : undefined;
}

/**
 * Return Lingui `t` usage that can execute while a module is being evaluated.
 * Factory function definitions are allowed, but invoking a macro-bearing
 * factory at module scope is not. Directly invoked function expressions are
 * treated as module-scope execution as well.
 */
export function findModuleScopeLinguiMacros(source, filePath = "fixture.tsx") {
  const sourceFile = ts.createSourceFile(
    filePath,
    source,
    ts.ScriptTarget.Latest,
    true,
    filePath.endsWith(".tsx") ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
  );
  const macroBindings = collectLinguiMacroBindings(sourceFile);
  const moduleFunctionBindings = collectModuleScopeFunctionBindings(sourceFile);
  const immediatelyInvokedFunctions = new Set();
  const violations = [];

  function addViolation(node) {
    const position = sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile));
    violations.push({
      filePath,
      line: position.line + 1,
      column: position.character + 1,
      text: node.getText(sourceFile),
    });
  }

  function visit(node, functionDepth) {
    if (functionDepth === 0 && ts.isCallExpression(node)) {
      const invokedFunction = getImmediatelyInvokedFunction(node);
      if (invokedFunction) {
        immediatelyInvokedFunctions.add(invokedFunction);
      }

      const callee = unwrapExpression(node.expression);
      if (ts.isIdentifier(callee)) {
        const moduleFunction = moduleFunctionBindings.get(callee.text);
        if (moduleFunction && functionContainsLinguiMacro(moduleFunction, macroBindings)) {
          addViolation(node);
        }
      }
    }

    if (functionDepth === 0 && isLinguiMacroTag(node, macroBindings)) {
      addViolation(node);
    }

    const entersDeferredFunction = isFunctionLike(node) && !immediatelyInvokedFunctions.has(node);
    ts.forEachChild(
      node,
      child => visit(child, functionDepth + (entersDeferredFunction ? 1 : 0)),
    );
  }

  visit(sourceFile, 0);
  return violations;
}

export function findModuleScopeLinguiMacrosInTree(sourceRoot = resolve(root, "src")) {
  return collectTypeScriptFiles(sourceRoot).flatMap(filePath => {
    const source = readFileSync(filePath, "utf8");
    return findModuleScopeLinguiMacros(source, filePath);
  });
}

const isMainModule = Boolean(
  process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url),
);

const PO_PATH = resolve(root, "locale/de/messages.po");
const COMPILED_PATH = resolve(root, "locale/de/messages.js");

function fail(message) {
  console.error(`\n❌ i18n check failed: ${message}`);
  console.error("Run `pnpm lingui:extract && pnpm lingui:compile` and commit the result.\n");
  process.exit(1);
}

function run() {
  console.log("🔍 Checking Lingui macro scopes...");
  const scopeViolations = findModuleScopeLinguiMacrosInTree();
  if (scopeViolations.length > 0) {
    console.error("\n❌ Module-scope Lingui `t` usages found:");
    for (const violation of scopeViolations) {
      console.error(`   - ${violation.filePath}:${violation.line}:${violation.column} ${violation.text}`);
    }
    fail("move each `t` into a function/render body and invoke schema/label factories there");
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
}

if (isMainModule) {
  run();
}
