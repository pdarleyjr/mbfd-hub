"""Re-extract a verified staged corpus into a separate compressed immutable corpus."""

import argparse
import json
import shutil
from pathlib import Path

from import_sources import digest, extract_document


def walk_sections(sections):
    for section in sections:
        yield section
        yield from walk_sections(section.get("children", []))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--qpdf", type=Path, required=True)
    parser.add_argument("--linearize", action="store_true")
    parser.add_argument("--verify-render", action="store_true")
    args = parser.parse_args()
    root, output = args.root.resolve(), args.output.resolve()
    if root == output:
        parser.error("Output must be a separate directory; the input corpus stays immutable")
    manifest = json.loads((root / "import-manifest.json").read_text(encoding="utf-8"))
    (output / "assets").mkdir(parents=True, exist_ok=True)
    for source in manifest["sources"]:
        path = root / source["source_path"]
        if digest(path) != source["sha256"]:
            raise ValueError("Original source archive hash changed")
        destination = output / source["source_path"]
        destination.parent.mkdir(parents=True, exist_ok=True)
        if not destination.exists():
            shutil.copyfile(path, destination)
        if digest(destination) != source["sha256"]:
            raise ValueError("Copied source archive hash differs")
    documents = [d for m in manifest["manuals"] for s in walk_sections(m["sections"]) for d in s["documents"]]
    for index, document in enumerate(documents, 1):
        result = extract_document(
            args.qpdf, root / document["source_path"], output,
            document["pages"][0]["physical_page"], document["pages"][-1]["physical_page"],
            document["title"], document["slug"], document["pages"], document["source_path"],
            document["version_label"], document["revision_date"], document["metadata"],
            args.verify_render, args.linearize,
        )
        result["metadata"]["source_filename"] = document["metadata"]["source_filename"]
        document.update(result)
        if index % 50 == 0 or index == len(documents):
            print(f"Verified compressed assets: {index}/{len(documents)}", flush=True)
    for source in manifest["sources"]:
        if digest(output / source["source_path"]) != source["sha256"]:
            raise ValueError("Immutable source archive changed during optimization")
    manifest["validation"]["extraction_configuration"] = {
        "object_streams": "generate", "remove_unreferenced_resources": False,
        "linearized": args.linearize, "serving_pages_retyped_or_rasterized": False,
    }
    for filename in ["moms-page-map.json", "sog-selected-versions.json", "sog-discovery-audit.json"]:
        if (root / filename).exists():
            shutil.copyfile(root / filename, output / filename)
    pending = output / "import-manifest.pending.json"
    pending.write_text(json.dumps(manifest, ensure_ascii=False, indent=2), encoding="utf-8")
    pending.replace(output / "import-manifest.json")
    print(json.dumps({"manifest": str(output / "import-manifest.json"), "sha256": digest(output / "import-manifest.json"), "documents": len(documents)}), flush=True)


if __name__ == "__main__":
    main()
