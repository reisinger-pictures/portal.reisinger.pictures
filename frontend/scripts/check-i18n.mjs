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
 * sinks). Any such finding fails the check: the strings must be wrapped in a
 * Lingui macro (`t`…` or `<Trans>`).
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
  // `scheme://…`, a leading `/` absolute path, or `scheme:payload` — but NOT a
  // bare `Label:` (the scheme branch used to match any `word:` prefix, which
  // swallowed real labels like `Flatrate:`/`Rechnung:` that merely end in a
  // colon). `:[^\s]` requires actual payload after the colon, `:\/\/` keeps
  // `https://…` working even though its payload starts with a slash.
  if (/^(?:\/|[a-z][a-z0-9+.-]*:\/\/|[a-z][a-z0-9+.-]*:[^\s])/i.test(trimmed)) return true;
  if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) return true;
  return /^[a-z0-9-]+(?:\.[a-z0-9-]+)+$/i.test(trimmed);
}

/** Matches HTML entities so `&nbsp;`, `&mdash;`, `&#8230;` are not read as letters. */
const HTML_ENTITY_REFERENCE = /&(?:#[0-9]+|#x[0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*);/g;

/**
 * A letter in the Unicode sense — digits, punctuation and whitespace do not
 * count.
 *
 * NOTE (NOT dead code): `recordString` below calls this on the node-level path.
 * The sentence-level path added by D-7 no longer does — it judges runs through
 * `isProseText` (a letter test is part of that taxonomy). This function is
 * therefore only reached for literals/attributes/helper arguments; it stays in
 * use and should not be removed with the sentence path.
 */
function containsLetter(text) {
  return /\p{L}/u.test(text.replace(HTML_ENTITY_REFERENCE, " "));
}

// ---------------------------------------------------------------------------
// Sentence-level taxonomy (D-7).
//
// A JSX text run that React splits into several adjacent text nodes —
// `Jahre (geb. {x})` becomes `Jahre (geb. ` + {x} + `)` — is ONE logical unit,
// not two findings. So the scan groups adjacent `JsxText` / `JsxExpression`
// children first, then asks whether the group is prose. The taxonomy is
// deliberately explicit because the failure direction matters: a rule that
// under-reports real copy is worse than a noisy one, since the noise is visible
// and the miss is not.
//
// Excluded as non-prose (each regex/label says which way it errs):
//   - values with no letter at all (numbers, currency, `%`, `+`, `(`, `)`) —
//     exact by construction
//   - technical machine values (`MAILCHIMP`, `DE`) — under-reports, so an
//     uppercase literal that IS user-facing copy is silently skipped
//   - unit fragments (`Min.`, `Stk.`, `Bilder/Std.`, `Inkl. Bilder`) —
//     under-reports; a short two-word label made only of unit-ish words would
//     be skipped too
//   - fully dynamic texts (`{count}` alone) — no literal to translate
//   - lone words below `PROSE_LONE_TOKEN_MIN_LENGTH` (`Preis`, `Name`, `Firma`)
//     — under-reports by design; longer lone words (`Galerien`) stay reported
// ---------------------------------------------------------------------------

/**
 * Closed-class German function words excluded from the prose-word count, so a
 * sentence is dominated by its content rather than by its articles. These are
 * the only words that may appear in a `flow` group without contributing to the
 * two-word threshold.
 */
const PROSE_STOPWORDS = new Set([
  "der", "die", "das", "und", "oder", "im", "am",
]);

/**
 * Known non-copy words. A text made only of these (plus punctuation/numbers)
 * is a fragment, not user-facing copy. The list errs toward **over**-reporting:
 * a genuine lone-word label such as `Galerien` or `Name` is not included and
 * therefore still flagged.
 */
const SINGLE_NON_COPY_WORDS = new Set([
  "and", "classifying", "stk", "min", "max", "inkl", "ca", "bzw", "etc",
  "vs", "nr", "std", "bild", "web", "print", "original", "zip", "abcdefghijklmnopqrstuvwxyz",
]);

/**
 * A standalone machine value — an uppercase token (`MAILCHIMP`, `DE`, `ZIP`)
 * or a nested identifier (`reportage-paket`, `calc_outdoor_images_per_hour`).
 * `looksLikeTechnicalValue` already covers e-mails/hostnames/URLs/paths; this
 * covers the letters-only machine values it lets through.
 *
 * The uppercase half is tested against the RAW text (case matters — `MAILCHIMP`
 * is a machine value, `Mailchimp` could be copy); the identifier half against
 * the lowercased token.
 *
 * Direction: mostly **under**-reports, so a shouty sentence written entirely in
 * capitals (`FEHLER`) is skipped. That is accepted: the repo's UI copy is
 * sentence case, and the alternative (flagging every uppercase token) trades
 * this rare miss for a stream of `MAILCHIMP`/`DE` noise.
 */
const MACHINE_VALUE_UPPERCASE = /^[A-Z0-9_]+$/u;
const MACHINE_VALUE_IDENTIFIER = /^[a-z0-9]+(?:[_-][a-z0-9]+)+$/u;

/**
 * A unit fragment or a ratio (`Min.`, `Stk.`, `Bilder/Std.`, `Bilder pro
 * Stunde`, `Outdoor-Bilder/Std.`, `Inkl. Bilder`): every letter token is either
 * a known unit word/abbreviation or a single letter, and there are at most
 * `UNIT_FRAGMENT_MAX_PROSE_WORDS` letter tokens in all. Evaluated on the RAW
 * text rather than on `tokenizeProseText`, because the tokeniser strips `/` and
 * `.` — which are exactly the characters that make `Bilder/Std.` one unit
 * fragment instead of two prose words.
 *
 * Direction: **under**-reports by design. A short German two-word label that
 * happens to consist of unit-ish words (`Bier Wirt`) would be skipped; the
 * trade is deliberate, because the counter-case (every `Bilder/Std.` in a
 * pricing table reported as prose) is the noise this rule exists to remove.
 */
const UNIT_FRAGMENT_WORDS = new Set([
  "bild", "bilder", "stk", "std", "std.", "min", "min.", "inkl", "inkl.",
  "stunde", "stunden", "pro", "max", "ca", "bzw", "etc", "nr", "vs", "and",
]);
const UNIT_FRAGMENT_MAX_PROSE_WORDS = 3;
const UNIT_FRAGMENT_LETTER_TOKEN = /^\p{L}{1,2}\.?$/u;

/** True when every letter token is a unit word/abbreviation; see `UNIT_FRAGMENT_WORDS`. */
function isUnitFragmentText(text) {
  const letterTokens = text
    .replace(HTML_ENTITY_REFERENCE, " ")
    .toLowerCase()
    .replace(NON_LETTER_RUN, " ")
    .trim()
    .split(" ")
    .filter(Boolean);
  if (letterTokens.length === 0 || letterTokens.length > UNIT_FRAGMENT_MAX_PROSE_WORDS) {
    return false;
  }
  return letterTokens.every(
    token => UNIT_FRAGMENT_WORDS.has(token) || UNIT_FRAGMENT_LETTER_TOKEN.test(token),
  );
}

/**
 * A single prose-ish word flags a finding only from this length on. Len 6 keeps
 * short German nouns out (`Preis`, `Name`, `Firma` are all below it and are the
 * deliberate under-report); longer labels (`Portrait`, `Speichern`, `Galerien`)
 * stay reported and are treated as copy.
 */
const PROSE_LONE_TOKEN_MIN_LENGTH = 6;

/** Whitespace-run normaliser shared by the grouping join and `record`. */
const WHITESPACE_RUN = /\s+/g;

/** A run of characters that has no letter (digits, currency, punctuation). */
const NON_LETTER_RUN = /[^\p{L}]+/u;

/**
 * The letter-carrying tokens of a text, entities neutralised and lowercased.
 * Whitespace is dropped along with the rest of `NON_LETTER_RUN`, so a word
 * broken by a newline (`Grund\n  honorar`) still reads as one token.
 */
function tokenizeProseText(text) {
  return text
    .replace(HTML_ENTITY_REFERENCE, " ")
    .toLowerCase()
    .replace(NON_LETTER_RUN, " ")
    .trim()
    .split(" ")
    .filter(Boolean);
}

/**
 * True when a text is prose (user-facing copy) and therefore belongs behind a
 * Lingui macro. Two directions, both named in the caller: a `classifying` group
 * (at least one literal sibling) or single-word `lone-token` groups may still
 * under-report known non-copy words; every other prose hit is exact.
 *
 * A multi-word group is prose when at least two of its words are not stopwords;
 * a hyphenated identifier (`reportage-paket`) splits on the hyphen and
 * therefore counts as two words — it is reported, which is the safe direction.
 * A single-word group is prose when the word is neither a known non-copy word
 * nor shorter than `PROSE_LONE_TOKEN_MIN_LENGTH`.
 */
function isProseText(text) {
  if (!/\p{L}/u.test(text.replace(HTML_ENTITY_REFERENCE, " "))) {
    return { prose: false };
  }
  const raw = text.replace(HTML_ENTITY_REFERENCE, " ").trim();
  const tokens = tokenizeProseText(text);
  if (isUnitFragmentText(text)) {
    return { prose: false };
  }
  if (tokens.length === 1) {
    const [only] = tokens;
    // `raw` is checked for the uppercase case: `MAILCHIMP` is a machine value,
    // `Mailchimp` is not (and stays reported as a lone word).
    if (
      SINGLE_NON_COPY_WORDS.has(only)
      || MACHINE_VALUE_UPPERCASE.test(raw)
      || MACHINE_VALUE_IDENTIFIER.test(only)
    ) {
      return { prose: false };
    }
    if (only.length < PROSE_LONE_TOKEN_MIN_LENGTH) {
      return { prose: false };
    }
    return { prose: true, mode: "lone-token" };
  }
  const proseWords = tokens.filter(
    token => !PROSE_STOPWORDS.has(token) && !MACHINE_VALUE_IDENTIFIER.test(token),
  ).length;
  if (proseWords >= 2) {
    return { prose: true, mode: "flow" };
  }
  return { prose: false };
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
 * JSX text is scanned at SENTENCE level (D-7): the adjacent `JsxText` /
 * `JsxExpression` children of one parent are grouped into a single logical
 * run before `isProseText` decides, so `Jahre (geb. {x})` is one finding, not
 * two. A group is reported under its first child's anchor with the group's
 * `staticText` joined by a single space (`Jahre (geb. )`).
 *
 * Deliberately not reported: pure whitespace/punctuation/entity text,
 * machine values (`MAILCHIMP`, `DE`, `pdf`), fully dynamic texts (`{count}`),
 * unit fragments (`Bilder/Std.`, `Min.`), lone words known not to be copy
 * (`stk`, `web`, `print`) and lone words below
 * `PROSE_LONE_TOKEN_MIN_LENGTH`, `data-*`/`className`/`href`/id attributes
 * (outside `USER_VISIBLE_ATTRIBUTES`), ARIA token values and id references,
 * colour literals, URLs/paths/hostnames/e-mails, and everything in test files
 * (filtered by `findUnlocalizedStringsInTree`). See the taxonomy comment above
 * `PROSE_STOPWORDS` for which way each exclusion errs.
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

  function record(node, category, text, extras) {
    const position = sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile));
    const normalized = text.replace(WHITESPACE_RUN, " ").trim();
    violations.push({
      filePath,
      line: position.line + 1,
      column: position.character + 1,
      category,
      text: normalized.length > 120 ? `${normalized.slice(0, 117)}…` : normalized,
      ...extras,
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

  /**
   * Report logical JSX text runs. A run is the longest sequence of adjacent
   * `JsxText` / `JsxExpression` children of one parent; a JSX element or
   * fragment is a hard boundary. The run is reported once, anchored at its
   * first child, and its literal segments are joined by a single space, so
   * `Reportage-Paket (+{m}% Aufschlag)` is ONE finding (`Reportage-Paket (+ %
   * Aufschlag)`), not two. Dynamic children contribute only a space, never
   * their source text — there is nothing literal in them to translate.
   *
   * The run still errs toward **over**-reporting, but no longer on bare
   * fragments: when a run mixes a literal with an expression the run is
   * reported even if the merged text is not prose — provided a literal segment
   * is itself prose by the taxonomy (`{groups} Gruppen, {galleries} Galerien`,
   * `{n} Galerien`). A short non-copy fragment (`vom`, `User`, `Ab`) is not
   * resurrected merely because an expression sits next to it.
   */
  function recordJsxTextRuns(parent) {
    const children = parent.children;
    let index = 0;
    while (index < children.length) {
      const member = children[index];
      if (!ts.isJsxText(member) && !ts.isJsxExpression(member)) {
        index++;
        continue;
      }

      const run = [];
      while (
        index < children.length
        && (ts.isJsxText(children[index]) || ts.isJsxExpression(children[index]))
      ) {
        run.push(children[index]);
        index++;
      }

      // A run of expressions only carries no literal to translate.
      const firstLiteral = run.findIndex(ts.isJsxText);
      if (firstLiteral !== -1) {
        recordJsxTextRun(run.slice(firstLiteral));
      }
    }
  }

  /** Report one run; see `recordJsxTextRuns` for the boundary rules. */
  function recordJsxTextRun(run) {
    const staticText = run
      .map(child => (ts.isJsxText(child) ? child.text : " "))
      .join(" ")
      .replace(WHITESPACE_RUN, " ")
      .trim();
    if (!staticText) {
      return;
    }
    // A run that *is* a technical value — the real case is a bare URL rendered
    // as its own JSX text node (`Impressum.tsx`'s external-link paragraph) — is
    // not copy, exactly as `recordString` already excludes it for literals and
    // attributes. Without this the sentence-level path reported the URL even
    // though the node-level path never did, because `looksLikeTechnicalValue`
    // was only wired into `recordString`.
    if (looksLikeTechnicalValue(staticText)) {
      return;
    }

    const hasLiteral = run.some(child => ts.isJsxText(child));
    const isNodeLevelEquivalent = run.length === 1 && hasLiteral;
    // The over-reporting exemption (report a literal merely because it sits
    // next to an expression) reports a run that is not prose on its own so
    // that merging never loses a pre-existing node-level finding. It requires
    // the literal itself to be prose by the same taxonomy: a bare punctuation
    // fragment (`:`, `/`, `·`) is not copy, and neither is a short
    // function-word/unit fragment (`vom`, `User`, `Ab`) — resurrecting those
    // merely because an expression sits next to them is the noise D-7's merge
    // was allowed to keep, not a finding it must keep. A genuinely prose
    // literal beside an expression (`{n} Galerien`) still reports.
    const literalIsProse = run
      .filter(child => ts.isJsxText(child))
      .some(child => isProseText(child.text).prose);
    const literalSiblingOfExpression = hasLiteral && run.length > 1 && literalIsProse;
    const { prose, mode } = isProseText(staticText);
    if (!prose && !literalSiblingOfExpression) {
      return;
    }

    record(run[0], "jsx-text", staticText, {
      classification: mode ?? (hasLiteral ? "flow" : undefined),
      nodeLevelEquivalent: isNodeLevelEquivalent,
    });
  }

  function visit(node, insideMacro, insideElementChildren) {
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
      if (!childInsideMacro && ts.isJsxElement(node)) {
        recordJsxTextRuns(node);
      }
      // Children of a `JsxElement` are consumed by `recordJsxTextRuns`; the
      // flag keeps the standalone `JsxText` branch from reporting them twice.
      ts.forEachChild(node, child => visit(child, childInsideMacro, ts.isJsxElement(node)));
      return;
    }

    if (ts.isJsxText(node)) {
      // Reached only via a non-`JsxElement` parent (e.g. a JSX fragment); there
      // is no child list to group against, so report the run on its own.
      if (!insideMacro && !insideElementChildren) {
        recordJsxTextRun([node]);
      }
      return;
    }

    if (!insideMacro && ts.isCallExpression(node)) {
      recordHelperArguments(node);
    }

    ts.forEachChild(node, child => visit(child, insideMacro, false));
  }

  visit(sourceFile, false, false);
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
 * Unlocalized-string findings are always fatal: a raw literal that is
 * user-visible copy must be wrapped in a Lingui macro (`t`…`) or a `<Trans>`
 * block. A pre-existing backlog is not a reason to let the build pass.
 */
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

  fail(
    "user-visible strings must be wrapped in a Lingui macro (`t`…` or `<Trans>`); "
    + "re-run `node scripts/check-i18n.mjs` after fixing to see the remaining findings",
  );
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
