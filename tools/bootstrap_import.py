from __future__ import annotations

import base64
import tarfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BOOTSTRAP = ROOT / "bootstrap"
PART_GLOB = "blackup-v3-source.b64part*"


def main() -> None:
    parts = sorted(BOOTSTRAP.glob(PART_GLOB))
    if not parts:
        raise SystemExit("No BLACKUP V3 bootstrap parts found")

    encoded = "".join(part.read_text(encoding="ascii") for part in parts)
    archive = base64.b64decode(encoded, validate=True)
    archive_path = BOOTSTRAP / "blackup-v3-source.tar.xz"
    archive_path.write_bytes(archive)

    with tarfile.open(archive_path, mode="r:xz") as bundle:
        bundle.extractall(ROOT, filter="data")

    archive_path.unlink(missing_ok=True)
    for part in parts:
        part.unlink(missing_ok=True)
    (BOOTSTRAP / "READY").unlink(missing_ok=True)
    try:
        BOOTSTRAP.rmdir()
    except OSError:
        pass


if __name__ == "__main__":
    main()
