#!/usr/bin/env python3
"""Disable (or inspect) Coolify auto-update on the engine VM — least-disruptive.

Auto-update has two gates:
  * env AUTOUPDATE in /data/coolify/source/.env  -> re-asserted into DB on every
    container init (app/Console/Commands/Init.php)
  * DB instance_settings.is_auto_update_enabled  -> the runtime gate the scheduler
    reads each minute (app/Console/Kernel.php scheduleUpdates)

Flipping the DB value takes effect within ~1 min with NO container restart.
Customer app/db/service containers are separate and are never touched by this.

Usage:
  python3 scripts/set-coolify-autoupdate.py            # inspect only
  python3 scripts/set-coolify-autoupdate.py --apply     # set both gates to false
"""
from __future__ import annotations

import shlex
import sys
from pathlib import Path

shlex_quote = shlex.quote

try:
    import paramiko
except ImportError:
    sys.exit("paramiko required")

ROOT = Path(__file__).resolve().parents[1]
ENV_FILE = ROOT / ".deploy-keys" / "deploy.env"
KEY = ROOT / ".deploy-keys" / "coolify_vm"
DOTENV = "/data/coolify/source/.env"


def load_env() -> dict[str, str]:
    env = {}
    for line in ENV_FILE.read_text().splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip().strip('"').strip("'")
    return env


def main() -> int:
    apply = "--apply" in sys.argv[1:]
    env = load_env()
    c = paramiko.SSHClient()
    c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    c.connect(
        env.get("COOLIFY_SSH_HOST", "134.255.200.131"),
        int(env.get("COOLIFY_SSH_PORT", "2221")),
        env.get("COOLIFY_SSH_USER", "coolify"),
        key_filename=str(KEY) if KEY.is_file() else None,
        password=None if KEY.is_file() else env.get("COOLIFY_SSH_PASSWORD"),
        timeout=30, allow_agent=False, look_for_keys=False,
    )

    sudo_pw = env.get("COOLIFY_SUDO_PASSWORD") or env.get("COOLIFY_SSH_PASSWORD", "")

    def run(cmd: str, t: int = 90) -> tuple[str, int]:
        _, o, e = c.exec_command(cmd, timeout=t)
        rc = o.channel.recv_exit_status()
        return (o.read().decode(errors="replace").strip()
                or e.read().decode(errors="replace").strip()), rc

    def sudo(cmd: str, t: int = 60) -> tuple[str, int]:
        # stdin is a pipe (printf), so a wrong password makes sudo exit on EOF
        # rather than hang waiting for a retry.
        inner = shlex_quote(cmd)
        full = (f"sudo -n bash -c {inner} 2>/dev/null || "
                f"printf '%s\\n' {shlex_quote(sudo_pw)} | sudo -S -p '' bash -c {inner}")
        return run(full, t)

    host, _ = run("hostname")
    print(f"VM: {host}\n")

    print("--- BEFORE ---")
    envline, _ = sudo(f"grep -i '^AUTOUPDATE' {DOTENV} || echo '(no AUTOUPDATE line)'")
    print("env AUTOUPDATE     :", envline)
    dbq = ('docker exec coolify php artisan tinker --execute='
           '"echo \\App\\Models\\InstanceSettings::first()->is_auto_update_enabled ? '
           '\'true\' : \'false\';"')
    dbval, _ = run(dbq)
    print("DB is_auto_update  :", dbval.strip().splitlines()[-1] if dbval else "?")
    sched, _ = run("docker exec coolify php artisan schedule:list 2>/dev/null | "
                   "grep -iE 'UpdateCoolifyJob|CheckForUpdatesJob' || echo '(none listed)'")
    print("scheduled jobs     :\n  " + sched.replace("\n", "\n  "))

    if not apply:
        print("\n(dry run — pass --apply to set both gates to false)")
        c.close()
        return 0

    print("\n--- APPLYING ---")
    out, rc = sudo(
        f"if grep -q '^AUTOUPDATE=' {DOTENV}; then "
        f"sed -i 's/^AUTOUPDATE=.*/AUTOUPDATE=false/' {DOTENV}; "
        f"else printf 'AUTOUPDATE=false\\n' >> {DOTENV}; fi && "
        f"grep -i '^AUTOUPDATE' {DOTENV}")
    print("env write:", out, "(rc", rc, ")")
    out, rc = run(
        'docker exec coolify php artisan tinker --execute='
        '"\\App\\Models\\InstanceSettings::first()->update([\'is_auto_update_enabled\' => false]); '
        'echo \'done\';"')
    print("DB update:", out.strip().splitlines()[-1] if out else "?", "(rc", rc, ")")

    print("\n--- AFTER ---")
    envline, _ = sudo(f"grep -i '^AUTOUPDATE' {DOTENV} || echo '(still missing)'")
    print("env AUTOUPDATE     :", envline)
    dbval, _ = run(dbq)
    print("DB is_auto_update  :", dbval.strip().splitlines()[-1] if dbval else "?")
    run("docker exec coolify php artisan schedule:clear-cache 2>/dev/null")
    sched, _ = run("docker exec coolify php artisan schedule:list 2>/dev/null | "
                   "grep -iE 'UpdateCoolifyJob|CheckForUpdatesJob' || echo '(none)'")
    print("scheduled jobs     :\n  " + sched.replace("\n", "\n  "))
    health, _ = run("curl -sf -o /dev/null -w '%{http_code}' http://127.0.0.1:8000/api/health")
    print("coolify /api/health:", health)
    print("\nNote: UpdateCoolifyJob should be gone; CheckForUpdatesJob stays (notify-only, harmless).")
    print("No container was restarted. Customer containers untouched.")
    c.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
