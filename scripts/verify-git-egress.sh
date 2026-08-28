#!/usr/bin/env bash
# P2-1a: DNS/egress gate from Coolify VM (no deploy required).
set -euo pipefail

HOSTS="${VERIFY_GIT_HOSTS:-github.com hamgit.ir}"

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

pass() {
  echo "OK: $*"
}

for host in $HOSTS; do
  getent hosts "$host" >/dev/null || fail "cannot resolve $host"
  pass "resolve $host"
done

git ls-remote https://github.com/coollabsio/coolify.git HEAD >/dev/null \
  || fail "git ls-remote github.com failed"

pass "P2-1a git egress"
