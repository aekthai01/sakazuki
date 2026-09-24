#!/usr/bin/env python3
"""Fail CI when obvious live credentials are committed to the repository.

The scanner intentionally prints only type/path/line, never the matched value.
It protects the public repository while still allowing deployment credentials to
live in GitHub Actions Secrets and be referenced as ${{ secrets.NAME }}.
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

FTP_ASSIGNMENT_RE = re.compile(
    rb"(?mi)^\s*(?:export\s+)?(FTP_USERNAME|FTP_PASSWORD)\s*[:=]\s*(.*?)\s*$"
)

# Exact synthetic fixture used to verify validation/auth behavior. Assemble it
# from two pieces so this scanner does not detect its own allowlist source.
NEARBY_TEST_FIXTURE = b"nb_" + b"live_1234567890abcdef"
ALLOWED_TEST_FIXTURES: dict[str, set[bytes]] = {
    "tests/slip_nearby_test.php": {NEARBY_TEST_FIXTURE},
}

FORBIDDEN_RUNTIME_FILES = {
    "private/database.php",
    "private/cheatgame.php",
    "private/xchetos.php",
}

SECRET_FILE_SUFFIXES = (".pem", ".key", ".p12", ".pfx")
ARCHIVE_OR_DUMP_SUFFIXES = (
    ".tar",
    ".tar.gz",
    ".tgz",
    ".tar.xz",
    ".zip",
    ".7z",
    ".rar",
    ".bak",
    ".dump",
    ".sql.gz",
)


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


def sensitive_path_reason(path: Path) -> str | None:
    path_string = path.as_posix()
    name = path.name.lower()

    if path_string in FORBIDDEN_RUNTIME_FILES:
        return "private runtime credential file is tracked/present"

    if name == ".env" or (
        name.startswith(".env.")
        and name not in {".env.example", ".env.sample", ".env.template"}
    ):
        return "environment file may contain runtime secrets"

    if name.endswith(SECRET_FILE_SUFFIXES):
        return "private key or credential container must not be committed"

    if name.endswith(ARCHIVE_OR_DUMP_SUFFIXES):
        return "archive/backup/dump files are forbidden in the public repository"

    return None


def ftp_assignment_is_safe(rhs: bytes, variable: bytes) -> bool:
    value = rhs.strip().strip(b"\"'").strip()
    if value == b"":
        return True

    # GitHub Actions Secrets are the intended location for live FTP credentials.
    if b"${{ secrets." in value:
        return True

    # Shell/environment indirection is also safe because the literal secret is
    # not present in the repository.
    env_refs = {
        b"$" + variable,
        b"${" + variable + b"}",
    }
    if value in env_refs:
        return True

    upper = value.upper()
    placeholder_markers = (
        b"YOUR_FTP_",
        b"CHANGE_ME",
        b"REPLACE_ME",
        b"PLACEHOLDER",
        b"EXAMPLE",
    )
    if any(marker in upper for marker in placeholder_markers):
        return True

    return False


def main() -> int:
    findings: list[tuple[str, int, str]] = []

    files = tracked_and_untracked_files()
    for path in files:
        reason = sensitive_path_reason(path)
        if reason is not None:
            findings.append((path.as_posix(), 1, reason))

    for path in files:
        if not path.is_file():
            continue
        try:
            data = path.read_bytes()
        except OSError:
            continue
        if b"\0" in data[:4096]:
            continue

        path_string = path.as_posix()
        for label, pattern in PATTERNS.items():
            match = pattern.search(data)
            if not match:
                continue
            matched_value = match.group(0)
            if matched_value in ALLOWED_TEST_FIXTURES.get(path_string, set()):
                continue
            findings.append((path_string, line_number(data, match.start()), label))

        for match in FTP_ASSIGNMENT_RE.finditer(data):
            variable = match.group(1)
            rhs = match.group(2)
            if ftp_assignment_is_safe(rhs, variable):
                continue
            label = "hard-coded FTP username" if variable == b"FTP_USERNAME" else "hard-coded FTP password"
            findings.append((path_string, line_number(data, match.start()), label))

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

    print("Secret guard passed. GitHub Secrets references are allowed; matched credential values were never printed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
