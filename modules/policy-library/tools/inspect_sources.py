"""Read-only source inspection; all evidence is written outside source directories."""

import argparse
import hashlib
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "var" / "python"))
import pymupdf


def sha256(path):
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def inspect_pdf(path):
    with pymupdf.open(path) as pdf:
        return {
            "path": str(path),
            "sha256": sha256(path),
            "page_count": len(pdf),
            "metadata": pdf.metadata,
            "outline": pdf.get_toc(),
            "pages": [
                {
                    "physical_page": i + 1,
                    "width": page.rect.width,
                    "height": page.rect.height,
                    "text": page.get_text(sort=True),
                    "blocks": [list(b) for b in page.get_text("blocks", sort=True)],
                }
                for i, page in enumerate(pdf)
            ],
        }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("pdf", type=Path)
    parser.add_argument("output", type=Path)
    args = parser.parse_args()
    args.output.parent.mkdir(parents=True, exist_ok=True)
    data = inspect_pdf(args.pdf)
    args.output.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({"pages": data["page_count"], "sha256": data["sha256"]}))


if __name__ == "__main__":
    main()
