#!/usr/bin/env python3
"""Verify canonical Lua-suite registration without a Lightroom runtime.

The child harness receives mock ``python3`` and ``lua`` executables.  This proves
which Lua files the canonical runner invokes without claiming that Lua or
Lightroom actually ran.
"""
from pathlib import Path
import os
import shutil
import subprocess
import sys
import tempfile

PLUGIN_DIR = Path(__file__).resolve().parents[1]
RUN_SCRIPT = PLUGIN_DIR / "tests" / "run.sh"
EXPECTED_LUA_TESTS = [
    "tests/regression.lua",
    "tests/api_session_regression.lua",
    "tests/manager_upload_regression.lua",
]


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def main() -> int:
    bash = shutil.which("bash")
    assert bash, "bash is required to execute the canonical harness"

    with tempfile.TemporaryDirectory(prefix="lrplugin-harness-") as temp_dir:
        fake_bin = Path(temp_dir) / "bin"
        fake_bin.mkdir()
        lua_log = Path(temp_dir) / "lua-invocations.log"
        lua_log.touch()

        # The nested run only needs to reach and record the Lua dispatch.  A
        # no-op Python executable prevents this contract test from recursing
        # into the normal static checks a second time.
        write_executable(fake_bin / "python3", "#!/bin/sh\nexit 0\n")
        write_executable(
            fake_bin / "lua",
            "#!/bin/sh\n"
            "printf '%s\\n' \"$*\" >> \"$LRPLUGIN_HARNESS_LUA_LOG\"\n",
        )

        env = os.environ.copy()
        env["PATH"] = os.pathsep.join((str(fake_bin), env.get("PATH", "")))
        env["LRPLUGIN_HARNESS_LUA_LOG"] = str(lua_log)
        result = subprocess.run(
            [bash, str(RUN_SCRIPT)],
            cwd=PLUGIN_DIR,
            env=env,
            capture_output=True,
            text=True,
        )
        assert result.returncode == 0, (
            "canonical harness failed with mock runtimes\n"
            f"stdout:\n{result.stdout}\n"
            f"stderr:\n{result.stderr}"
        )

        actual = lua_log.read_text(encoding="utf-8").splitlines()
        assert actual == EXPECTED_LUA_TESTS, (
            "canonical Lua suite differs from the registered regression set: "
            f"expected {EXPECTED_LUA_TESTS!r}, got {actual!r}"
        )

    print(
        "Canonical harness registration regression passed "
        "(manager upload test invocation verified with a mock Lua executable; "
        "no live Lightroom runtime)"
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (AssertionError, OSError) as exc:
        print(
            f"Canonical harness registration regression FAILED: {exc}",
            file=sys.stderr,
        )
        raise SystemExit(1)
