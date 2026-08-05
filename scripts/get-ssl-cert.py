#!/usr/bin/env python3
"""
Get an SSL certificate for manual upload to Coolify (Application → SSL).

Default: REAL Let's Encrypt certificate via certbot (DNS challenge).
You will be asked to add a TXT record at your DNS provider, then the
certificate + private key are printed for copy-paste into Coolify.

Usage:
  python3 scripts/get-ssl-cert.py example.com                 # LE cert, apex only
  python3 scripts/get-ssl-cert.py example.com --www           # LE cert, apex + www
  python3 scripts/get-ssl-cert.py example.com -w -s cms       # apex + www + cms.example.com
  python3 scripts/get-ssl-cert.py example.com -s cms -s api   # multiple subdomains
  python3 scripts/get-ssl-cert.py example.com -s cms.example.com  # full FQDN also works
  python3 scripts/get-ssl-cert.py example.com --email me@x.y  # set ACME email
  python3 scripts/get-ssl-cert.py example.com --self-signed   # old behavior (testing only)

Requires (for Let's Encrypt): certbot
  macOS:  brew install certbot
  Linux:  sudo apt install certbot

Let's Encrypt certs are valid ~90 days — re-run this script to renew,
then paste the new cert into Coolify again.
"""

from __future__ import annotations

import argparse
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path


def build_domain_list(domain: str, include_www: bool, subdomains: list[str]) -> list[str]:
    domains = [domain]
    if include_www:
        domains.append(f"www.{domain}")

    for sub in subdomains:
        sub = sub.strip().lower().removeprefix("https://").removeprefix("http://").split("/")[0]
        if not sub:
            continue
        if "." in sub:
            domains.append(sub)
        else:
            domains.append(f"{sub}.{domain}")

    seen: set[str] = set()
    unique: list[str] = []
    for item in domains:
        if item not in seen:
            seen.add(item)
            unique.append(item)

    return unique


def certbot_domain_args(domains: list[str]) -> list[str]:
    args: list[str] = []
    for name in domains:
        args.extend(["-d", name])
    return args


def print_result(cert_file: Path, key_file: Path, out_dir: Path) -> None:
    out_dir.mkdir(parents=True, exist_ok=True)
    saved_cert = out_dir / "fullchain.pem"
    saved_key = out_dir / "privkey.pem"
    saved_cert.write_text(cert_file.read_text())
    saved_key.write_text(key_file.read_text())
    saved_key.chmod(0o600)

    print(f"\nSaved:\n  {saved_cert}\n  {saved_key}\n")
    print("=" * 60)
    print("CERTIFICATE (paste into Coolify → SSL → Certificate)")
    print("=" * 60)
    print(saved_cert.read_text())
    print("=" * 60)
    print("PRIVATE KEY (paste into Coolify → SSL → Private key)")
    print("=" * 60)
    print(saved_key.read_text())
    print("=" * 60)
    print("\nCoolify: Application → Configuration → SSL → paste both → Save → Redeploy (no rebuild).")


def get_letsencrypt(domain: str, domains: list[str], email: str | None, out_dir: Path) -> None:
    certbot = shutil.which("certbot")
    if certbot is None:
        sys.exit(
            "ERROR: certbot not found.\n"
            "  macOS:  brew install certbot\n"
            "  Linux:  sudo apt install certbot\n"
            "Or use --self-signed for a testing certificate."
        )

    with tempfile.TemporaryDirectory() as tmp:
        work = Path(tmp)
        cmd = [
            certbot,
            "certonly",
            "--manual",
            "--preferred-challenges", "dns",
            "--agree-tos",
            "--no-eff-email",
            # keep everything in user space — no sudo needed
            "--config-dir", str(work / "config"),
            "--work-dir", str(work / "work"),
            "--logs-dir", str(work / "logs"),
            *certbot_domain_args(domains),
        ]
        cmd += ["--email", email] if email else ["--register-unsafely-without-email"]

        print("=" * 60)
        print("Let's Encrypt (DNS challenge)")
        print("Domains in this certificate:")
        for name in domains:
            print(f"  - {name}")
        print()
        print("certbot will show TXT record(s) — add each at your DNS provider,")
        print("wait 1-2 minutes for propagation, then press Enter in certbot.")
        print("Check propagation examples:")
        for name in domains:
            print(f"  dig +short TXT _acme-challenge.{name}")
        print("=" * 60)

        result = subprocess.run(cmd)
        if result.returncode != 0:
            sys.exit("ERROR: certbot failed (see output above).")

        live = work / "config" / "live" / domain
        print_result(live / "fullchain.pem", live / "privkey.pem", out_dir)


def get_self_signed(domains: list[str], days: int, out_dir: Path) -> None:
    if shutil.which("openssl") is None:
        sys.exit("ERROR: openssl not found.")

    primary = domains[0]
    san = ",".join(f"DNS:{name}" for name in domains)

    with tempfile.TemporaryDirectory() as tmp:
        tmp_path = Path(tmp)
        cnf = tmp_path / "openssl.cnf"
        cnf.write_text(
            f"""[req]
distinguished_name = req_distinguished_name
x509_extensions = v3_req
prompt = no

[req_distinguished_name]
CN = {primary}

[v3_req]
subjectAltName = {san}
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
"""
        )
        key_file = tmp_path / "privkey.pem"
        cert_file = tmp_path / "fullchain.pem"
        subprocess.run(
            [
                "openssl", "req", "-x509", "-nodes",
                "-newkey", "rsa:2048",
                "-days", str(days),
                "-keyout", str(key_file),
                "-out", str(cert_file),
                "-config", str(cnf),
                "-extensions", "v3_req",
            ],
            check=True,
        )
        print("\nWARNING: self-signed certificate — browsers will show a warning.")
        print_result(cert_file, key_file, out_dir)


def main() -> None:
    parser = argparse.ArgumentParser(description="Get SSL cert for Coolify manual upload")
    parser.add_argument("domain", help="Domain name, e.g. m-shahabadi.com")
    parser.add_argument("--www", "-w", action="store_true", help="Also include www.<domain>")
    parser.add_argument(
        "--subdomain",
        "-s",
        action="append",
        default=[],
        metavar="SUB",
        help="Extra subdomain(s). Example: -s cms → cms.<domain>. Repeatable. Full FQDN also works.",
    )
    parser.add_argument("--email", default=None, help="Email for Let's Encrypt notifications")
    parser.add_argument("--self-signed", action="store_true", help="Self-signed cert instead of Let's Encrypt (testing only)")
    parser.add_argument("--days", type=int, default=365, help="Validity days (self-signed only, default: 365)")
    parser.add_argument("--out", type=Path, default=None, help="Output dir (default: ./certs/<domain>)")
    args = parser.parse_args()

    domain = args.domain.strip().lower().removeprefix("https://").removeprefix("http://").split("/")[0]
    if not domain or " " in domain or "." not in domain:
        sys.exit("ERROR: invalid domain")

    domains = build_domain_list(domain, args.www, args.subdomain)
    out_dir = args.out or Path("certs") / domain

    if args.self_signed:
        get_self_signed(domains, args.days, out_dir)
    else:
        get_letsencrypt(domain, domains, args.email, out_dir)


if __name__ == "__main__":
    main()
