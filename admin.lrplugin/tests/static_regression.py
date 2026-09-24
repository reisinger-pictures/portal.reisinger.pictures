#!/usr/bin/env python3
"""Small no-dependency regression harness for admin.lrplugin.

The Lightroom SDK runtime is not available in CI containers.  This checks Lua
 delimiter/string/comment structure and source-level invariants for the four
plugin findings, then checks the malformed group-tree fixture and canonical
suite registration.  When Lua is unavailable, these checks do not execute or
emulate the ManagerCore state machine.  The harness is intentionally
conservative and fails closed when a guard or suite entry is removed.
"""
from pathlib import Path
import re
import sys

PLUGIN_DIR = Path(__file__).resolve().parents[1]


def strip_lua(source: str) -> str:
    """Remove comments and quoted/long-bracket literals for structural checks."""
    out = []
    i = 0
    n = len(source)
    while i < n:
        if source.startswith("--", i):
            long_match = re.match(r"--\[(=*)\[", source[i:])
            if long_match:
                equals = long_match.group(1)
                closing = "]" + equals + "]"
                end = source.find(closing, i + long_match.end())
                if end < 0:
                    raise AssertionError("unterminated Lua long comment")
                out.append("\n" * source[i:end + len(closing)].count("\n"))
                i = end + len(closing)
            else:
                end = source.find("\n", i)
                if end < 0:
                    break
                out.append("\n")
                i = end + 1
            continue

        if source[i] in ("'", '"'):
            quote = source[i]
            out.append(" ")
            i += 1
            while i < n:
                if source[i] == "\\":
                    i += 2
                    continue
                if source[i] == quote:
                    i += 1
                    break
                if source[i] == "\n":
                    raise AssertionError("unterminated Lua quoted string")
                i += 1
            else:
                raise AssertionError("unterminated Lua quoted string")
            continue

        long_match = re.match(r"\[(=*)\[", source[i:])
        if long_match:
            equals = long_match.group(1)
            closing = "]" + equals + "]"
            end = source.find(closing, i + long_match.end())
            if end < 0:
                raise AssertionError("unterminated Lua long string")
            out.append("\n" * source[i:end + len(closing)].count("\n"))
            i = end + len(closing)
            continue

        out.append(source[i])
        i += 1
    return "".join(out)


def check_lua_structure(path: Path) -> None:
    source = path.read_text(encoding="utf-8")
    stripped = strip_lua(source)
    stack = []
    pairs = {")": "(", "]": "[", "}": "{"}
    openers = set(pairs.values())
    for char in stripped:
        if char in openers:
            stack.append(char)
        elif char in pairs:
            assert stack and stack.pop() == pairs[char], f"unbalanced delimiters in {path}"
    assert not stack, f"unclosed delimiter in {path}"


def require(source: str, needle: str, label: str) -> None:
    assert needle in source, f"missing {label}: {needle}"


def main() -> int:
    changed_lua = [
        PLUGIN_DIR / "Api.lua",
        PLUGIN_DIR / "ManagerCore.lua",
        PLUGIN_DIR / "GalleryDialog.lua",
        PLUGIN_DIR / "MetaGalleryDialog.lua",
        PLUGIN_DIR / "InviteDialog.lua",
        PLUGIN_DIR / "RatingStatusDialog.lua",
        PLUGIN_DIR / "Utils.lua",
        PLUGIN_DIR / "json.lua",
        PLUGIN_DIR / "tests" / "regression.lua",
        PLUGIN_DIR / "tests" / "api_session_regression.lua",
        PLUGIN_DIR / "tests" / "manager_upload_regression.lua",
        PLUGIN_DIR / "tests" / "fixtures" / "group_tree.lua",
    ]
    for path in changed_lua:
        check_lua_structure(path)

    manager = (PLUGIN_DIR / "ManagerCore.lua").read_text(encoding="utf-8")
    manager_code = " ".join(strip_lua(manager).split())
    require(manager, "LR_collisionHandling = \"overwrite\"", "fresh collision handling")
    require(manager, "directoryCreated == false", "fresh-directory creation guard")
    require(manager_code, "stalePath = LrFileUtils.exists(path)", "pre-render stale-path guard")
    require(manager_code, "LrFileUtils.delete(path)", "stale-path cleanup")
    require(
        manager_code,
        "local renderOk, pathOrMessage = rendition:waitForRender()",
        "explicit render result",
    )
    require(manager_code, "renderOk ~= true", "unsuccessful-render guard")
    require(manager_code, "or stalePath or not LrFileUtils.exists(renderedPath)", "fresh rendered-file gate")
    require(manager_code, "if renderedPath then", "upload candidate gate")
    require(manager_code, "Api.uploadWithSession", "bounded upload session renewal")
    require(manager_code, "uploadedCount = uploadedCount + 1", "successful-upload state")
    require(manager_code, "cancelled = true", "cancellation state")
    assert manager_code.count("if progress and progress:isCanceled() then") >= 3, (
        "cancellation checks are missing from the ManagerCore upload state machine"
    )
    require(manager_code, "if errorCount == 0 and uploadedCount == photoCount then", "complete-upload guard")
    require(manager_code, "LrFileUtils.delete(renderedPath)", "fresh-path-only cleanup")

    cancellation_branch = manager_code.index("if cancelled then")
    success_guard = manager_code.index(
        "if errorCount == 0 and uploadedCount == photoCount then",
        cancellation_branch,
    )
    assert manager_code.rfind("return", cancellation_branch, success_guard) > cancellation_branch, (
        "cancellation must return before the successful-upload prompt"
    )
    assert manager_code.index("Api.uploadWithSession") > manager_code.index("if renderedPath then"), (
        "upload call must remain behind the rendered-file gate"
    )
    assert "Render durch LR übersprungen" not in manager, "stale-rendition fallback returned"

    api = (PLUGIN_DIR / "Api.lua").read_text(encoding="utf-8")
    require(api, "MAX_AUTH_RETRIES = 1", "bounded session renewal")
    require(api, "pcall(Api.login, session.email)", "protected credential fallback")
    require(api, "function Api.createSession", "session holder")
    require(api, "function Api.refreshSession", "session renewal")
    require(api, "function Api.callWithSession", "non-upload session wrapper")
    require(api, "function Api.uploadWithSession", "upload session wrapper")

    json_source = (PLUGIN_DIR / "json.lua").read_text(encoding="utf-8")
    require(json_source, "json.null = {}", "explicit JSON null sentinel")
    require(json_source, "val == json.null", "explicit JSON null encoder")

    utils = (PLUGIN_DIR / "Utils.lua").read_text(encoding="utf-8")
    require(utils, "excludeIds", "descendant exclusion")
    require(utils, "visited", "cycle guard")
    require(utils, "function Utils.flattenGroupChoices", "group-choice helper")
    require(utils, "function Utils.isGroupParentSelectionValid", "parent selection validation")

    meta = (PLUGIN_DIR / "MetaGalleryDialog.lua").read_text(encoding="utf-8")
    require(meta, "flattenGroupChoices", "cycle-safe parent choices")
    require(meta, "isGroupParentSelectionValid", "client-side descendant validation")
    require(meta, "Ungültiger Unterordner", "German validation UI")
    gallery = (PLUGIN_DIR / "GalleryDialog.lua").read_text(encoding="utf-8")
    require(gallery, "flattenGroupChoices", "cycle-safe gallery group choices")
    require(gallery, "payload.expires_at = expiresAt ~= \"\" and expiresAt or json.null", "explicit expiry null")

    fixture = (PLUGIN_DIR / "tests" / "fixtures" / "group_tree.lua").read_text(encoding="utf-8")
    for group_id in ("root", "child", "grandchild", "sibling"):
        require(fixture, f'id = "{group_id}"', "fixture group")
    require(fixture, "grandchild.children = { root }", "fixture cycle")
    # The Lua regression asserts the exact ordered choices.  Mirror that
    # contract here so the no-runtime path still checks the fixture semantics.
    lua_regression = (PLUGIN_DIR / "tests" / "regression.lua").read_text(encoding="utf-8")
    assert "rootChoices" in lua_regression
    assert 'values(rootChoices) == "sibling"' in lua_regression
    assert 'values(siblingChoices) == "root,child,grandchild"' in lua_regression
    assert "isGroupParentSelectionValid" in lua_regression
    assert "expires_at = json.null" in lua_regression
    api_regression = (PLUGIN_DIR / "tests" / "api_session_regression.lua").read_text(encoding="utf-8")
    assert "callWithSession" in api_regression
    assert "uploadWithSession" in api_regression
    assert "terminalRefreshes" in api_regression
    assert "cookie-token" in api_regression
    manager_regression = (PLUGIN_DIR / "tests" / "manager_upload_regression.lua").read_text(encoding="utf-8")
    require(manager_regression, 'readFile("ManagerCore.lua")', "ManagerCore mock loader")
    require(manager_regression, 'ManagerCore("delivery"', "ManagerCore mock execution")
    require(manager_regression, 'runScenario("cancel")', "cancellation scenario")
    require(manager_regression, 'runScenario("stale")', "stale-rendition scenario")
    require(manager_regression, 'runScenario("success")', "successful-upload scenario")
    require(manager_regression, "staleUploads == 0", "stale-rendition upload prohibition")
    require(manager_regression, "cancelConfirms == 0", "cancellation confirmation prohibition")
    require(manager_regression, "successConfirms == 1", "successful completion prompt")
    require(manager_regression, "no live Lightroom runtime", "mock-runtime disclosure")

    runner = (PLUGIN_DIR / "tests" / "run.sh").read_text(encoding="utf-8")
    require(runner, "python3 -B tests/harness_regression.py", "harness contract-test registration")
    require(runner, "python3 -B tests/static_regression.py", "static fallback registration")
    require(runner, "lua tests/manager_upload_regression.lua", "ManagerCore Lua-suite registration")
    require(runner, "no live Lightroom execution", "no-runtime disclosure")

    print(
        "Static Lightroom source regression checks passed "
        f"({len(changed_lua)} Lua files checked; no live Lightroom runtime)"
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (AssertionError, OSError) as exc:
        print(f"Static Lightroom regression FAILED: {exc}", file=sys.stderr)
        raise SystemExit(1)
