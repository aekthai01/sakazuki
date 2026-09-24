#!/usr/bin/env python3
"""Fail CI when obvious live credentials are committed to the repository.

The scanner intentionally prints only type/path/line, never the matched value.
It is conservative: provider-specific live-token prefixes, private keys, common
cloud access keys, credential-bearing URLs, and the database example contract.
"""
from __future__ import annotations

import re
import subprocess
from pathlib import Path

PATTERNS: dict[str, re.Pattern[bytes]] = {
    "xNearby live API key": re.compile(rb"nb_live_[A-Za-z0-9_-]{8,}"),
    "GitHub access token": re.compile(rb"(?:ghp_|github_pat_)[A-Za-z0-9_]{16,}"),
    "AWS access key": re.compile(rb"AKIA[0-9A-Z]{16}"),
    "Stripe live secret": re.compile(rb"sk_live_[A-Za-z0-9]{12,}"),
    "Slack bot token": re.compile(rb"xoxb-[A-Za-z0-9-]{16,}"),
    "private key block": re.compile(rb"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----"),
    "credential-bearing URL": re.compile(rb"(?:mysql|mariadb|postgres(?:ql)?|ftp|sftp)://[^\s/:@]+:[^\s/@]+@", re.I),
}


def tracked_and_untracked_files() -> list[Path]:
    raw = subprocess.check_output(
        ["git", "ls-files", "-co", "--exclude-standard", "-z"]
    )
    result: list[Path] = []
    for item in raw.split(b"\0"):
        if not item:
            continue
        try:
            result.append(Path(item.decode("utf-8")))
        except UnicodeDecodeError:
            continue
    return result


def line_number(data: bytes, offset: int) -> int:
    return data.count(b"\n", 0, offset) + 1


def main() -> int:
    findings: list[tuple[str, int, str]] = []

    forbidden_private = [Path("private/database.php"), Path("private/cheatgame.php")]
    for path in forbidden_private:
        if path.exists():
            findings.append((str(path), 1, "private runtime credential file is tracked/present"))

    for path in tracked_and_untracked_files():
        if not path.is_file():
            continue
        try:
            data = path.read_bytes()
        except OSError:
            continue
        if b"\0" in data[:4096]:
            continue
        for label, pattern in PATTERNS.items():
            match = pattern.search(data)
            if match:
                findings.append((str(path), line_number(data, match.start()), label))

    db_example = Path("private/database.php.example")
    if not db_example.is_file():
        findings.append((str(db_example), 1, "database example is missing"))
    else:
        text = db_example.read_text(encoding="utf-8", errors="replace")
        password = re.search(r"'pass'\s*=>\s*'([^']*)'", text)
        user = re.search(r"'user'\s*=>\s*'([^']*)'", text)
        name = re.search(r"'name'\s*=>\s*'([^']*)'", text)
        expected = {
            "password": (password.group(1) if password else "", "YOUR_DATABASE_PASSWORD"),
            "user": (user.group(1) if user else "", "YOUR_DATABASE_USER"),
            "name": (name.group(1) if name else "", "YOUR_DATABASE_NAME"),
        }
        for label, (actual, placeholder) in expected.items():
            if actual != placeholder:
                findings.append((str(db_example), 1, f"database example {label} must be a placeholder"))

    if findings:
        seen: set[tuple[str, int, str]] = set()
        for finding in findings:
            if finding in seen:
                continue
            seen.add(finding)
            path, line, label = finding
            print(f"Potential secret: {label} at {path}:{line}")
        print("Secret guard failed. Credential values were intentionally not printed.")
        return 1

    print("Secret guard passed. No matched credential values were printed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
