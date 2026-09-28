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
 *
 * A separate AST pass additionally reports user-visible strings that no Lingui
 * macro wraps (raw JSX text/attributes, literals handed to the toast/confirm
 * sinks). It is warn-only by default so the pre-existing backlog does not turn
 * the build red; `CHECK_I18N_UNLOCALIZED_STRICT=1` promotes it to a failure.
 */

import { execFileSync } from "node:child_process";
import { readFileSync, existsSync, readdirSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, relative, resolve } from "node:path";
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

/**
 * Cheap pre-filter for the tree walk. `collectLinguiMacroBindings` derives
 * `macroBindings` *exclusively* from import declarations whose module specifier
 * is `@lingui/core/macro`. Without such an import the binding set is empty, so
 * `isLinguiMacroTag` never matches and neither the direct-tag branch nor the
 * macro-bearing-factory branch (`functionContainsLinguiMacro`) can ever report
 * a violation — the file provably contributes `[]`.
 *
 * Parsing ~2/3 of the `src` tree to reach that conclusion is pure waste, and
 * this guard runs both in `pnpm build` (prebuild) and in
 * `scripts/check-i18n.test.mjs`. The pattern stays deliberately permissive
 * (optional backslash-escaped slashes, tolerated whitespace) so a false
 * positive can only cost a parse, never a missed violation.
 */
const LINGUI_MACRO_MODULE_REFERENCE = /lingui\s*(?:\\?\/)\s*core\s*(?:\\?\/)\s*macro/;

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

/**
 * JSX macro components whose children are extraction units of their own.
 * `collectLinguiMacroBindings` only models the `t` tagged-template macro from
 * `@lingui/core/macro`; a source string can also be legitimately wrapped by a
 * JSX macro from `@lingui/react/macro` (`<Trans>`, `<Plural>`, `<Select>`, …),
 * which is not a tagged template and is therefore invisible to
 * `isLinguiMacroTag`. `<Trans>` is the dominant wrapper in this codebase, so
 * the unlocalized-string scan needs this second, imported-name-based notion of
 * "wrapped" alongside the tagged-template one.
 */
const LINGUI_JSX_MACRO_MODULES = new Set(["@lingui/react/macro", "@lingui/macro"]);

function collectLinguiMacroComponentBindings(sourceFile) {
  const bindings = new Set();
  for (const statement of sourceFile.statements) {
    if (!ts.isImportDeclaration(statement) || !ts.isStringLiteral(statement.moduleSpecifier)) {
      continue;
    }
    if (!LINGUI_JSX_MACRO_MODULES.has(statement.moduleSpecifier.text) || statement.importClause?.isTypeOnly) {
      continue;
    }

    const namedBindings = statement.importClause?.namedBindings;
    if (!namedBindings || !ts.isNamedImports(namedBindings)) {
      continue;
    }
    for (const specifier of namedBindings.elements) {
      if (!specifier.isTypeOnly) {
        bindings.add(specifier.name.text);
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
  // `setParentNodes: false` — the traversal below only uses `forEachChild` and
  // the explicit-sourcefile forms `getStart(sourceFile)` / `getText(sourceFile)`,
  // none of which read `node.parent`. Linking parents for every node in the tree
  // is measurable overhead on a 300+ file scan, so it stays off.
  const sourceFile = ts.createSourceFile(
    filePath,
    source,
    ts.ScriptTarget.Latest,
    false,
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
  const violations = [];
  for (const filePath of collectTypeScriptFiles(sourceRoot)) {
    const source = readFileSync(filePath, "utf8");
    // See LINGUI_MACRO_MODULE_REFERENCE: no reference to the macro module means
    // the file cannot contain a module-scope macro, so it is not parsed at all.
    if (!LINGUI_MACRO_MODULE_REFERENCE.test(source)) {
      continue;
    }
    violations.push(...findModuleScopeLinguiMacros(source, filePath));
  }
  return violations;
}

/**
 * A literal can be non-translatable for reasons other than being wrapped:
 * colour literals, URLs, absolute paths, bare hostnames and e-mail addresses
 * are machine values that happen to contain letters.
 */
function looksLikeTechnicalValue(value) {
  const trimmed = value.trim();
  if (/^#[0-9a-fA-F]{3,8}$/.test(trimmed)) return true;
  if (/^(?:[a-z][a-z0-9+.-]*:|\/)/i.test(trimmed)) return true;
  if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) return true;
  return /^[a-z0-9-]+(?:\.[a-z0-9-]+)+$/i.test(trimmed);
}

/** Matches HTML entities so `&nbsp;`, `&mdash;`, `&#8230;` are not read as letters. */
const HTML_ENTITY_REFERENCE = /&(?:#[0-9]+|#x[0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*);/g;

/** A letter in the Unicode sense — digits, punctuation and whitespace do not count. */
function containsLetter(text) {
  return /\p{L}/u.test(text.replace(HTML_ENTITY_REFERENCE, " "));
}

/**
 * Attributes that carry human-readable text (as opposed to tokens, ids or
 * machine values) and are translatable when written as a literal.
 */
const USER_VISIBLE_ATTRIBUTES = new Set([
  "placeholder",
  "title",
  "alt",
  "label",
  "aria-label",
  "aria-description",
  "aria-placeholder",
  "aria-roledescription",
  "aria-valuetext",
]);

/** ARIA attributes that reference element ids — never prose. */
const ARIA_IDREF_ATTRIBUTES = new Set([
  "aria-activedescendant",
  "aria-controls",
  "aria-describedby",
  "aria-details",
  "aria-errormessage",
  "aria-flowto",
  "aria-labelledby",
  "aria-owns",
]);

/**
 * Closed WAI-ARIA token sets. A literal equal to one of these
 * (`aria-hidden="true"`, `aria-live="polite"`, `aria-autocomplete="list"`) is a
 * machine value and must not be reported. This keeps the blanket `aria-*`
 * coverage useful instead of drowning it in `true`/`polite` noise.
 */
const ARIA_ENUMERATED_VALUES = new Set([
  "true", "false", "mixed", "undefined", "other", "none",
  "inline", "list", "both", "page", "step", "location", "date", "time",
  "polite", "assertive", "off",
  "menu", "listbox", "tree", "grid", "dialog", "popup",
  "horizontal", "vertical", "ascending", "descending",
  "copy", "execute", "link", "move", "grammar", "spelling",
  "additions", "removals", "text", "all",
]);

/**
 * Host helpers that render a string straight into the UI — this codebase's
 * `t`-like sinks. Their message argument is normally a macro result
 * (`showToast('error', t`…`)`), so a bare literal is an untranslatable string.
 * `showToast(type, text)` skips its first, machine-readable argument.
 */
const TOAST_HELPER_NAME = "showToast";
const CONFIRM_HELPER_NAME = "confirm";
const CONFIRM_STRING_OPTIONS = new Set(["title", "message", "confirmText", "cancelText"]);

function getJsxAttributeName(name) {
  if (ts.isIdentifier(name)) return name.text;
  if (ts.isJsxNamespacedName(name)) return `${name.namespace.text}:${name.name.text}`;
  return undefined;
}

/** A string literal or a substitution-free template literal, else undefined. */
function getStaticString(node) {
  const expression = unwrapExpression(node);
  if (!expression) return undefined;
  if (ts.isStringLiteral(expression)) return expression.text;
  if (ts.isNoSubstitutionTemplateLiteral(expression)) return expression.text;
  return undefined;
}

/** Extract a static string from a JSX attribute initializer, if there is one. */
function getStringAttributeValue(initializer) {
  if (!initializer) return undefined;
  if (ts.isStringLiteral(initializer)) return initializer.text;
  if (ts.isJsxExpression(initializer) && initializer.expression) {
    return getStaticString(initializer.expression);
  }
  return undefined;
}

function isUserVisibleAttribute(name, value) {
  if (name === undefined) return false;
  if (USER_VISIBLE_ATTRIBUTES.has(name)) return true;
  if (name.startsWith("aria-") && !ARIA_IDREF_ATTRIBUTES.has(name)) {
    return !ARIA_ENUMERATED_VALUES.has(value.trim().toLowerCase());
  }
  return false;
}

/**
 * Find user-visible strings that no Lingui macro wraps: JSX text nodes and
 * string-literal JSX attributes, plus literals handed to the toast/confirm
 * sinks. A string is "wrapped" when it sits inside `<Trans>`/`<Plural>` (or
 * any other imported `@lingui/react/macro` component) or is produced by the
 * `t` tagged template — both notions are collected at the top of this function.
 *
 * Deliberately not reported: pure whitespace/punctuation/entity text,
 * `data-*`/`className`/`href`/id attributes (outside `USER_VISIBLE_ATTRIBUTES`),
 * ARIA token values and id references, colour literals, URLs/paths/hostnames/
 * e-mails, and everything in test files (filtered by
 * `findUnlocalizedStringsInTree`).
 */
export function findUnlocalizedStrings(source, filePath = "fixture.tsx") {
  const sourceFile = ts.createSourceFile(
    filePath,
    source,
    ts.ScriptTarget.Latest,
    false,
    filePath.endsWith(".tsx") ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
  );
  const macroComponentBindings = collectLinguiMacroComponentBindings(sourceFile);
  const violations = [];

  function record(node, category, text) {
    const position = sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile));
    const normalized = text.replace(/\s+/g, " ").trim();
    violations.push({
      filePath,
      line: position.line + 1,
      column: position.character + 1,
      category,
      text: normalized.length > 120 ? `${normalized.slice(0, 117)}…` : normalized,
    });
  }

  function recordString(node, category, value, label) {
    if (value === undefined || !containsLetter(value) || looksLikeTechnicalValue(value)) {
      return;
    }
    record(node, category, label ?? value);
  }

  function recordHelperArguments(call) {
    const callee = unwrapExpression(call.expression);
    if (!ts.isIdentifier(callee)) return;

    if (callee.text === TOAST_HELPER_NAME) {
      for (let index = 1; index < call.arguments.length; index++) {
        const value = getStaticString(call.arguments[index]);
        recordString(
          call.arguments[index],
          "helper-argument",
          value,
          `${TOAST_HELPER_NAME}(…, ${JSON.stringify(value)})`,
        );
      }
      return;
    }

    if (callee.text === CONFIRM_HELPER_NAME) {
      for (const argument of call.arguments) {
        const options = unwrapExpression(argument);
        if (!options || !ts.isObjectLiteralExpression(options)) continue;
        for (const property of options.properties) {
          if (!ts.isPropertyAssignment(property)) continue;
          const key = ts.isIdentifier(property.name) || ts.isStringLiteral(property.name)
            ? property.name.text
            : undefined;
          if (key === undefined || !CONFIRM_STRING_OPTIONS.has(key)) continue;
          const value = getStaticString(property.initializer);
          recordString(
            property,
            "helper-argument",
            value,
            `${CONFIRM_HELPER_NAME}({${key}: ${JSON.stringify(value)}})`,
          );
        }
      }
    }
  }

  function visit(node, insideMacro) {
    if (ts.isJsxElement(node) || ts.isJsxSelfClosingElement(node)) {
      const openingElement = ts.isJsxElement(node) ? node.openingElement : node;
      if (!insideMacro) {
        for (const property of openingElement.attributes.properties) {
          if (!ts.isJsxAttribute(property)) continue;
          const attributeName = getJsxAttributeName(property.name);
          const value = getStringAttributeValue(property.initializer);
          if (value === undefined || !isUserVisibleAttribute(attributeName, value)) continue;
          recordString(property, "jsx-attribute", value, `${attributeName}="${value}"`);
        }
      }
      const tagName = ts.isIdentifier(openingElement.tagName) ? openingElement.tagName.text : undefined;
      const childInsideMacro = insideMacro
        || (tagName !== undefined && macroComponentBindings.has(tagName));
      ts.forEachChild(node, child => visit(child, childInsideMacro));
      return;
    }

    if (ts.isJsxText(node)) {
      if (!insideMacro) {
        recordString(node, "jsx-text", node.text);
      }
      return;
    }

    if (!insideMacro && ts.isCallExpression(node)) {
      recordHelperArguments(node);
    }

    ts.forEachChild(node, child => visit(child, insideMacro));
  }

  visit(sourceFile, false);
  return violations;
}

/** Test files hold fixtures and assertions, not shipped UI copy. */
function isTestFile(filePath) {
  return /(?:^|[\\/])__tests__[\\/]/.test(filePath)
    || /\.(?:test|spec)\.(?:ts|tsx)$/.test(filePath);
}

export function findUnlocalizedStringsInTree(sourceRoot = resolve(root, "src")) {
  const violations = [];
  for (const filePath of collectTypeScriptFiles(sourceRoot)) {
    if (isTestFile(filePath)) {
      continue;
    }
    violations.push(...findUnlocalizedStrings(readFileSync(filePath, "utf8"), filePath));
  }
  return violations;
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

/**
 * New unlocalized-string rule, rolled out warn-only: the first scan against
 * `src` is expected to find pre-existing German literals, and a gate that fails
 * on that backlog would simply stop being run. Set
 * `CHECK_I18N_UNLOCALIZED_STRICT=1` to promote it to a hard failure once the
 * backlog is empty — no code change required.
 */
const UNLOCALIZED_STRINGS_ARE_FATAL = process.env.CHECK_I18N_UNLOCALIZED_STRICT === "1";
const UNLOCALIZED_REPORT_LIMIT = 25;

function reportUnlocalizedStrings() {
  const findings = findUnlocalizedStringsInTree();
  if (findings.length === 0) {
    console.log("✅ No user-visible strings outside a Lingui macro.");
    return;
  }

  const byCategory = new Map();
  for (const finding of findings) {
    byCategory.set(finding.category, (byCategory.get(finding.category) ?? 0) + 1);
  }
  const summary = [...byCategory.entries()]
    .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
    .map(([category, count]) => `${category}=${count}`)
    .join(", ");

  console.warn(`\n⚠️  ${findings.length} user-visible string(s) outside a Lingui macro (${summary}):`);
  for (const finding of findings.slice(0, UNLOCALIZED_REPORT_LIMIT)) {
    console.warn(
      `   - ${relative(root, finding.filePath)}:${finding.line}:${finding.column} [${finding.category}] ${finding.text}`,
    );
  }
  if (findings.length > UNLOCALIZED_REPORT_LIMIT) {
    console.warn(`   … and ${findings.length - UNLOCALIZED_REPORT_LIMIT} more`);
  }

  if (UNLOCALIZED_STRINGS_ARE_FATAL) {
    fail("user-visible strings must be wrapped in a Lingui macro (t`…` or <Trans>)");
  }
  console.warn("   (warn-only; set CHECK_I18N_UNLOCALIZED_STRICT=1 to make this a failure)\n");
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

  reportUnlocalizedStrings();

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
