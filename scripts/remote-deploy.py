#!/usr/bin/env python3
"""Non-interactive deploy to VM via SSH password (pexpect)."""

from __future__ import annotations

import os
import sys
from pathlib import Path

import pexpect

ROOT = Path(__file__).resolve().parents[1]
ENV_FILE = ROOT / ".deploy-keys" / "deploy.env"
HOST = os.environ.get("COOLIFY_SSH_HOST", "134.255.200.131")
PORT = os.environ.get("COOLIFY_SSH_PORT", "2221")
USER = os.environ.get("COOLIFY_SSH_USER", "coolify")
REGISTRY = os.environ.get("REGISTRY_URL", "ghcr-mirror.liara.ir")
TAG = os.environ.get("COOLIFY_TAG", "4.1.2")

TARBALL = Path("/tmp/coolify-source-custom.tar.gz")
RUN_SCRIPT = ROOT / "scripts" / "run-on-vm.sh"
SSH_KEY = ROOT / ".deploy-keys" / "coolify_vm"
SSH_PUB = ROOT / ".deploy-keys" / "coolify_vm.pub"


def load_password() -> str:
    if os.environ.get("COOLIFY_SSH_PASSWORD"):
        return os.environ["COOLIFY_SSH_PASSWORD"]
    if ENV_FILE.is_file():
        for line in ENV_FILE.read_text().splitlines():
            line = line.strip()
            if line.startswith("COOLIFY_SSH_PASSWORD="):
                return line.split("=", 1)[1].strip().strip('"').strip("'")
    print(
        f"ERROR: Set COOLIFY_SSH_PASSWORD or create {ENV_FILE} (see deploy.env.example)",
        file=sys.stderr,
    )
    sys.exit(1)


PASSWORD = load_password()


def run_pexpect(cmd: str, timeout: int = 600) -> None:
    child = pexpect.spawn(cmd, encoding="utf-8", timeout=timeout)
    child.logfile = sys.stdout
    idx = child.expect(["password:", pexpect.EOF, pexpect.TIMEOUT])
    if idx == 0:
        child.sendline(PASSWORD)
        child.expect(pexpect.EOF, timeout=timeout)
    elif idx == 2:
        print("ERROR: timeout", file=sys.stderr)
        sys.exit(1)

    if child.exitstatus not in (0, None):
        sys.exit(child.exitstatus or 1)


def can_use_ssh_key() -> bool:
    if not SSH_KEY.is_file():
        return False

    child = pexpect.spawn(
        f"ssh -i {SSH_KEY} -o BatchMode=yes -o StrictHostKeyChecking=accept-new "
        f"-p {PORT} {USER}@{HOST} true",
        encoding="utf-8",
        timeout=15,
    )
    child.expect(pexpect.EOF, timeout=15)

    return child.exitstatus == 0


def main() -> None:
    if not can_use_ssh_key() and SSH_PUB.is_file():
        pub = SSH_PUB.read_text().strip()
        print("==> Install deploy SSH key (if missing)")
        run_pexpect(
            f"ssh -o StrictHostKeyChecking=accept-new -p {PORT} {USER}@{HOST} "
            f"'mkdir -p ~/.ssh && chmod 700 ~/.ssh && "
            f"grep -qF \"{pub.split()[1]}\" ~/.ssh/authorized_keys 2>/dev/null || "
            f"echo \"{pub}\" >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys'",
            timeout=60,
        )

    print("==> Pack source")
    os.system(
        f"tar czf {TARBALL} --exclude=node_modules --exclude=vendor --exclude=.git "
        f"-C {ROOT.parent} {ROOT.name}"
    )

    ssh_prefix = (
        f"ssh -o StrictHostKeyChecking=accept-new -p {PORT} "
        + (f"-i {SSH_KEY} " if SSH_KEY.is_file() else "")
        + f"{USER}@{HOST}"
    )
    scp_prefix = (
        f"scp -o StrictHostKeyChecking=accept-new -P {PORT} "
        + (f"-i {SSH_KEY} " if SSH_KEY.is_file() else "")
    )

    def scp(local: Path, remote: str) -> None:
        if SSH_KEY.is_file():
            child = pexpect.spawn(
                f"{scp_prefix} {local} {USER}@{HOST}:{remote}",
                encoding="utf-8",
                timeout=300,
            )
            child.logfile = sys.stdout
            child.expect(pexpect.EOF, timeout=300)
            if child.exitstatus not in (0, None):
                sys.exit(child.exitstatus or 1)
        else:
            run_pexpect(f"{scp_prefix} {local} {USER}@{HOST}:{remote}", timeout=300)

    print("==> Upload tarball")
    scp(TARBALL, "/tmp/coolify-source-custom.tar.gz")

    print("==> Upload run-on-vm.sh")
    scp(RUN_SCRIPT, "/tmp/run-on-vm.sh")

    env_exports = " ".join(
        f"export {k}={v!r};"
        for k, v in {
            "REGISTRY_URL": REGISTRY,
            "COOLIFY_TAG": TAG,
            "COOLIFY_SSH_PASSWORD": PASSWORD,
            "SKIP_PULL": os.environ.get("SKIP_PULL", "1"),
        }.items()
    )
    remote = (
        f"cd /tmp && tar xzf coolify-source-custom.tar.gz && chmod +x run-on-vm.sh && "
        f"{env_exports} SOURCE_DIR=/tmp/coolify-4.x bash /tmp/run-on-vm.sh"
    )

    print("==> Run deploy on VM")
    if SSH_KEY.is_file():
        child = pexpect.spawn(f"{ssh_prefix} {remote!r}", encoding="utf-8", timeout=900)
        child.logfile = sys.stdout
        child.expect(pexpect.EOF, timeout=900)
        if child.exitstatus not in (0, None):
            sys.exit(child.exitstatus or 1)
    else:
        run_pexpect(f"ssh -o StrictHostKeyChecking=accept-new -p {PORT} {USER}@{HOST} {remote!r}", timeout=900)


if __name__ == "__main__":
    main()
