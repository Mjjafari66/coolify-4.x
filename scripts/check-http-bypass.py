#!/usr/bin/env python3
"""Read-only verifier for the weblines HTTP-bypass cron on the Coolify VM.

Confirms sync-weblines-http-bypass.py is deployed, cronned, running, and that
its Traefik dynamic file is live inside coolify-proxy. Also sanity-checks the
custom Coolify image + force_https patch.

Usage:  python3 scripts/check-http-bypass.py
Creds:  .deploy-keys/deploy.env  (COOLIFY_SSH_HOST/PORT/USER/PASSWORD)
"""
from __future__ import annotations

import hashlib
import os
import sys
import time
from pathlib import Path

try:
    import paramiko
except ImportError:
    sys.exit("paramiko required")

ROOT = Path(__file__).resolve().parents[1]
ENV_FILE = ROOT / ".deploy-keys" / "deploy.env"
LOCAL_SYNC = ROOT / "scripts" / "sync-weblines-http-bypass.py"

REMOTE_SYNC = "/home/coolify/sync-weblines-http-bypass.py"
REMOTE_YAML = "/home/coolify/zz-weblines-http-bypass.yaml"
REMOTE_LOG = "/home/coolify/weblines-http-bypass.log"
PROXY_YAML = "/traefik/dynamic/zz-weblines-http-bypass.yaml"

PASS, WARN, FAIL = "PASS", "WARN", "FAIL"


def load_env() -> dict[str, str]:
    env = {}
    for line in ENV_FILE.read_text().splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip().strip('"').strip("'")
    return env


def main() -> int:
    env = load_env()
    c = paramiko.SSHClient()
    c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    host = env.get("COOLIFY_SSH_HOST", "134.255.200.131")
    port = int(env.get("COOLIFY_SSH_PORT", "2221"))
    user = env.get("COOLIFY_SSH_USER", "coolify")
    key_file = ROOT / ".deploy-keys" / "coolify_vm"
    kwargs = dict(timeout=30, allow_agent=False, look_for_keys=False)
    if key_file.is_file():
        c.connect(host, port, user, key_filename=str(key_file), **kwargs)
    else:
        c.connect(host, port, user, password=env["COOLIFY_SSH_PASSWORD"], **kwargs)

    sudo_pw = env.get("COOLIFY_SUDO_PASSWORD") or env.get("COOLIFY_SSH_PASSWORD", "")

    def run(cmd: str, t: int = 60) -> tuple[str, int]:
        _, o, e = c.exec_command(cmd, timeout=t)
        rc = o.channel.recv_exit_status()
        return (o.read().decode(errors="replace").strip()
                or e.read().decode(errors="replace").strip()), rc

    def sudo(cmd: str, t: int = 60) -> tuple[str, int]:
        import shlex as _sh
        q = _sh.quote(cmd)
        return run(f"sudo -n bash -c {q} 2>/dev/null || "
                   f"printf '%s\\n' {_sh.quote(sudo_pw)} | sudo -S -p '' bash -c {q}", t)

    results: list[tuple[str, str, str]] = []

    def check(name: str, status: str, detail: str = "") -> None:
        results.append((status, name, detail))
        print(f"[{status}] {name}" + (f" — {detail}" if detail else ""))

    host, _ = run("hostname; uptime -p")
    print(f"VM: {host.replace(chr(10), ' | ')}\n")

    # 1. script present + matches repo
    out, rc = run(f"test -f {REMOTE_SYNC} && md5sum {REMOTE_SYNC}")
    if rc != 0:
        check("sync script deployed", FAIL, f"{REMOTE_SYNC} missing")
    else:
        remote_md5 = out.split()[0]
        local_md5 = hashlib.md5(LOCAL_SYNC.read_bytes()).hexdigest()
        if remote_md5 == local_md5:
            check("sync script matches repo", PASS, remote_md5[:12])
        else:
            check("sync script matches repo", WARN,
                  f"VM {remote_md5[:12]} != repo {local_md5[:12]} (VM may be older/newer)")

    # 2. crontab entry
    out, rc = run("crontab -l 2>/dev/null")
    cron_line = next((l for l in out.splitlines()
                      if "sync-weblines-http-bypass" in l and not l.strip().startswith("#")), None)
    if cron_line:
        check("cron entry", PASS, cron_line.strip())
    else:
        check("cron entry", FAIL, "no active crontab line for sync-weblines-http-bypass")

    # 3. log freshness
    out, rc = run(f"test -f {REMOTE_LOG} && stat -c '%Y' {REMOTE_LOG}")
    if rc == 0 and out.isdigit():
        age = int(time.time()) - int(out)
        status = PASS if age < 300 else (WARN if age < 3600 else FAIL)
        check("log updated recently", status, f"{age}s ago")
        tail, _ = run(f"tail -n 5 {REMOTE_LOG}")
        if tail:
            print("    log tail:\n      " + tail.replace("\n", "\n      "))
        errs, _ = run(f"tail -n 500 {REMOTE_LOG} | grep -icE 'traceback|error' | head -1")
        errs = (errs.strip().splitlines() or ["0"])[0] or "0"
        check("log errors (last 500 lines)", PASS if errs == "0" else WARN, f"{errs} error line(s)")
    else:
        check("log present", FAIL, f"{REMOTE_LOG} missing")

    # 4. generated yaml on disk
    out, rc = run(f"test -f {REMOTE_YAML} && stat -c '%Y' {REMOTE_YAML} && cat {REMOTE_YAML}")
    if rc == 0:
        lines = out.splitlines()
        routers = [l.strip() for l in lines if l.strip().startswith("weblines-http-")]
        check("bypass yaml generated", PASS, f"{len(routers)} platform router(s)")
        for r in routers[:10]:
            print(f"      - {r.rstrip(':')}")
    else:
        check("bypass yaml generated", FAIL, f"{REMOTE_YAML} missing")

    # 5. yaml live inside coolify-proxy
    out, rc = run(f"docker exec coolify-proxy sh -c 'test -f {PROXY_YAML} && md5sum {PROXY_YAML}' 2>/dev/null")
    if rc == 0:
        proxy_md5 = out.split()[0]
        disk_md5, drc = run(f"md5sum {REMOTE_YAML} 2>/dev/null")
        same = drc == 0 and disk_md5.split()[0] == proxy_md5
        check("yaml published to coolify-proxy", PASS if same else WARN,
              "in sync" if same else "proxy copy differs from disk copy")
    else:
        check("yaml published to coolify-proxy", FAIL, f"not found in coolify-proxy:{PROXY_YAML}")

    # 6. custom image / autoupdate / patch
    out, _ = run("docker inspect coolify --format '{{.Config.Image}}' 2>/dev/null")
    check("coolify custom image", PASS if "custom" in out else WARN, out or "unknown")
    envline, _ = sudo("grep -i '^AUTOUPDATE' /data/coolify/source/.env || echo '(no AUTOUPDATE line)'")
    db_au, _ = run("docker exec coolify php artisan tinker --execute="
                   "\"echo \\App\\Models\\InstanceSettings::first()->is_auto_update_enabled ? 'true' : 'false';\" 2>/dev/null")
    db_au = (db_au.strip().splitlines() or ["?"])[-1]
    env_ok = "AUTOUPDATE=false" in envline
    check("AUTOUPDATE disabled",
          PASS if (env_ok and db_au == "false") else WARN,
          f"env: {envline.strip()} | DB is_auto_update_enabled: {db_au}")
    errline, _ = run(f"tail -n 500 {REMOTE_LOG} | grep -iE 'traceback|error' | tail -3")
    if errline:
        print("    recent error line(s):\n      " + errline.replace("\n", "\n      "))
    out, rc = run("docker exec coolify grep -lc 'is_force_https_enabled: false' "
                  "/var/www/html/bootstrap/helpers/parsers.php 2>/dev/null")
    check("force_https:false patch in container", PASS if rc == 0 else WARN,
          "present" if rc == 0 else "not detected")

    c.close()
    print()
    fails = sum(1 for s, _, _ in results if s == FAIL)
    warns = sum(1 for s, _, _ in results if s == WARN)
    if fails:
        print(f"RESULT: {fails} FAIL, {warns} WARN — bypass cron NOT fully healthy.")
        return 1
    if warns:
        print(f"RESULT: 0 FAIL, {warns} WARN — working, review warnings.")
        return 0
    print("RESULT: all green — HTTP-bypass cron deployed, running, and live in proxy.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
