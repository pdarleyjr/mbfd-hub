"""Stage an explicitly hash-bound coordinated SOG delivery without splitting pages."""

import hashlib
import json
import shutil
from collections import Counter, defaultdict
from pathlib import Path
from urllib.parse import unquote

from PIL import Image, ImageDraw, ImageOps

from import_sources import archive_source, digest, pymupdf, run_qpdf, slug, sog_printed_label


R2_DELIVERY_SHA256 = "4b6f079bf3e392f45e2cf85e2246c268a606e138c10351610583b2677ebd8314"
CURRENT_ASSET_URL = "https://files.mbfdhub.com/current-sog/"


def bound_json(path, expected_hash):
    if digest(path) != expected_hash:
        raise ValueError(f"Frozen delivery hash mismatch: {path.name}")
    return json.loads(path.read_text(encoding="utf-8"))


def contained_path(root, relative):
    path = (root / relative).resolve()
    if not path.is_relative_to(root.resolve()) or not path.is_file():
        raise ValueError(f"Delivery file is missing or outside its source root: {relative}")
    return path


def read_delivery(path, expected_hash):
    delivery = bound_json(path, expected_hash)
    binding = delivery["source_bindings"]
    publication_relative = Path(binding["publication_manifest"]["path"])
    if path.parent.name != publication_relative.parent.name:
        raise ValueError("Delivery manifest must remain in its portable source hierarchy")
    root = path.parent.parent.parent.resolve()
    publication = bound_json(contained_path(root, publication_relative), binding["publication_manifest"]["sha256"])
    registry = bound_json(contained_path(root, binding["document_registry"]["path"]), binding["document_registry"]["sha256"])
    aliases = bound_json(contained_path(root, binding["subject_alias_map"]["path"]), binding["subject_alias_map"]["sha256"])
    assets = delivery["sog_assets"] + delivery["separate_review_controls"]
    by_id = {asset["id"]: asset for asset in assets}
    published = {asset["id"]: asset for asset in publication}
    identities = {entry["id"]: entry for entry in delivery["primary_ids"]}
    registered = {entry["id"]: entry for entry in registry}
    counts = delivery["counts"]
    if len(by_id) != len(assets) or set(by_id) != set(published):
        raise ValueError("Delivery assets do not match the frozen publication manifest")
    if len(identities) != len(registry) or set(identities) != set(registered):
        raise ValueError("Delivery identities do not match the frozen document registry")
    if (len(delivery["sog_assets"]), len(delivery["separate_review_controls"]), len(identities), len(delivery["historical_subject_aliases"])) != (
        counts["sog_assets"], counts["separate_review_controls"], counts["primary_ids"], counts["subject_specific_aliases"]
    ):
        raise ValueError("Delivery count declarations do not match their actual entries")
    # The independent alias binding is authoritative; duplicate legacy policy numbers
    # remain separate subjects identified by source_record_id.
    alias_records = aliases if isinstance(aliases, list) else aliases.get("aliases", aliases.get("records", []))
    if {item["source_record_id"] for item in alias_records} != {item["source_record_id"] for item in delivery["historical_subject_aliases"]}:
        raise ValueError("Delivery subjects do not match the frozen alias map")
    original_aliases = {item["source_record_id"]: item for item in alias_records}
    for alias in delivery["historical_subject_aliases"]:
        for field, value in original_aliases[alias["source_record_id"]].items():
            if alias.get(field) != value:
                raise ValueError(f"Subject-specific alias binding differs: {alias['source_record_id']} {field}")
        if {target["id"] for target in alias["resolved_current_targets"]} != set(alias["current_ids"]):
            raise ValueError(f"Subject-specific alias drops a current target: {alias['source_record_id']}")
        for target in alias["resolved_current_targets"]:
            identity = identities[target["id"]]
            for field in ("title", "parent", "owner_function", "owning_asset_id", "anchor", "physical_pdf_page"):
                if target[field] != identity[field]:
                    raise ValueError(f"Subject-specific alias target differs: {alias['source_record_id']} {field}")
    for asset in assets:
        canonical = published[asset["id"]]
        for field in ("artifact_pdf", "artifact_docx", "pdf_sha256", "docx_sha256", "pdf_pages", "edition", "parent"):
            if asset[field] != canonical.get(field):
                raise ValueError(f"Publication binding differs: {asset['id']} {field}")
        for kind in ("pdf", "docx"):
            if digest(contained_path(root, asset[f"artifact_{kind}"])) != asset[f"{kind}_sha256"]:
                raise ValueError(f"Canonical source hash mismatch: {asset['id']} {kind}")
    for identity in identities.values():
        current = registered[identity["id"]]
        for field in ("title", "parent", "anchor", "artifact_pdf", "edition", "approval_event", "effective_date"):
            if identity[field] != current[field]:
                raise ValueError(f"Registry binding differs: {identity['id']} {field}")
        if identity["physical_pdf_page"] != current["pdf_page"] or identity["artifact_pdf"] != by_id[identity["owning_asset_id"]]["artifact_pdf"]:
            raise ValueError(f"Identity entrypoint does not match its registered owning asset: {identity['id']}")
        if identity["parent"] and identity["parent"] not in identities:
            raise ValueError(f"Unknown identity parent: {identity['id']}")
    return root, delivery, by_id, registered


def canonical_peer_links(source, asset, asset_paths, assets):
    links = []
    with pymupdf.open(source) as pdf:
        for page in pdf:
            for link in page.get_links():
                if link["kind"] not in (pymupdf.LINK_GOTO, pymupdf.LINK_GOTOR):
                    raise ValueError(f"Unsupported active PDF link: {asset['id']} page {page.number + 1}")
                if link["kind"] != pymupdf.LINK_GOTOR:
                    continue
                target_path = (source.parent / unquote(link["file"]).replace("\\", "/")).resolve()
                target_id = asset_paths.get(target_path)
                if target_id is None or not isinstance(link.get("page"), int) or link["page"] < 0:
                    raise ValueError(f"Unsupported or unknown canonical peer action: {asset['id']} page {page.number + 1}")
                target_page = link["page"] + 1
                if target_page > assets[target_id]["pdf_pages"]:
                    raise ValueError(f"Peer action leaves its current target PDF: {target_id} page {target_page}")
                links.append({
                    "source_page": page.number + 1, "rect": list(link["from"]),
                    "target_asset_id": target_id, "target_slug": "asset-" + slug(target_id),
                    "target_page": target_page, "target_source_sha256": assets[target_id]["pdf_sha256"],
                    "original_file": link["file"], "annotation_xref": link["xref"],
                    "uri": CURRENT_ASSET_URL + target_id + f"?page={target_page}",
                })
    return links


def page_resources(pdf, page):
    return {
        "contents": [hashlib.sha256(pdf.xref_stream(xref)).hexdigest() for xref in page.get_contents()],
        "fonts": sorted((font[3:6], hashlib.sha256(pdf.extract_font(font[0])[3]).hexdigest()) for font in page.get_fonts()),
        "images": sorted((image[2:9], hashlib.sha256(pdf.xref_stream(image[0])).hexdigest(), hashlib.sha256(pdf.xref_stream(image[1])).hexdigest() if image[1] else None) for image in page.get_images()),
    }


def local_links(page):
    return [{key: tuple(value) if key in ("from", "to") else value for key, value in link.items() if key not in ("xref", "id")}
            for link in page.get_links() if link["kind"] != pymupdf.LINK_GOTOR and link["kind"] != pymupdf.LINK_URI]


def verify_derivative(source, served, peer_links, verify_render, contact_directory=None, asset_id=None):
    proof = []
    expected_uris = Counter((link["source_page"], tuple(link["rect"]), link["uri"]) for link in peer_links)
    actual_uris = Counter()
    with pymupdf.open(source) as canonical, pymupdf.open(served) as derivative:
        if len(canonical) != len(derivative) or canonical.get_toc() != derivative.get_toc() or canonical.resolve_names() != derivative.resolve_names():
            raise ValueError("Served whole-PDF pages, bookmarks or named destinations changed")
        for index, actual in enumerate(derivative):
            expected = canonical[index]
            geometry = lambda page: (page.rect, page.mediabox, page.cropbox, page.artbox, page.trimbox, page.bleedbox, page.rotation)
            if geometry(actual) != geometry(expected) or actual.get_text() != expected.get_text():
                raise ValueError(f"Served page text or geometry changed: {source.name} page {index + 1}")
            resources = page_resources(canonical, expected)
            if resources != page_resources(derivative, actual) or local_links(expected) != local_links(actual):
                raise ValueError(f"Served content/font/image streams or local links changed: {source.name} page {index + 1}")
            for link in actual.get_links():
                if link["kind"] == pymupdf.LINK_GOTOR:
                    raise ValueError("Served PDF retains a portable filesystem peer action")
                if link["kind"] == pymupdf.LINK_URI:
                    actual_uris[(index + 1, tuple(link["from"]), link["uri"])] += 1
            page_proof = {"physical_page": index + 1, "content_resources_sha256": hashlib.sha256(json.dumps(resources, sort_keys=True).encode()).hexdigest(), "text_sha256": hashlib.sha256(expected.get_text().encode()).hexdigest()}
            if verify_render:
                original = expected.get_pixmap(matrix=pymupdf.Matrix(200 / 72, 200 / 72), alpha=False)
                rendered = actual.get_pixmap(matrix=pymupdf.Matrix(200 / 72, 200 / 72), alpha=False)
                if (original.width, original.height, original.samples) != (rendered.width, rendered.height, rendered.samples):
                    raise ValueError(f"Served page pixels changed: {source.name} page {index + 1}")
                page_proof["canonical_render_sha256"] = hashlib.sha256(original.samples).hexdigest()
                page_proof["served_render_sha256"] = hashlib.sha256(rendered.samples).hexdigest()
                page_proof["render_dpi"] = 200
                if contact_directory:
                    contact_directory.mkdir(parents=True, exist_ok=True)
                    sheet_index, slot = divmod(index, 24)
                    if slot == 0:
                        sheet = Image.new("RGB", (1440, 1360), "#e5e7eb")
                        drawing = ImageDraw.Draw(sheet)
                    x, y = (slot % 6) * 240, (slot // 6) * 340
                    drawing.text((x + 8, y + 5), f"{asset_id} | p{index + 1}", fill="black")
                    thumbnail = ImageOps.contain(Image.frombytes("RGB", (rendered.width, rendered.height), rendered.samples), (224, 308))
                    sheet.paste(thumbnail, (x + 8, y + 24))
                    if slot == 23 or index + 1 == len(derivative):
                        sheet.save(contact_directory / f"{slug(asset_id)}-{sheet_index + 1:02}.jpg", quality=90)
            proof.append(page_proof)
    if actual_uris != expected_uris:
        raise ValueError("Served peer URI actions do not match the complete canonical peer map")
    return proof


def stage_asset(args, output, sources, root, asset, entries, aliases, peer_links):
    source = contained_path(root, asset["artifact_pdf"])
    source_path, source_hash = archive_source(source, output, sources)
    temporary = output / "assets" / "delivery-serving.pdf"
    if peer_links:
        with pymupdf.open(source) as pdf:
            for link in peer_links:
                # Change the annotation action only. All page/content/resource objects,
                # local GoTo actions, bookmarks and named destinations remain intact.
                pdf.xref_set_key(link["annotation_xref"], "A", f"<< /S /URI /URI ({link['uri']}) >>")
            pdf.save(temporary, garbage=0, deflate=False, no_new_id=True)
    else:
        shutil.copyfile(source, temporary)
    if args.linearize:
        linearized = output / "assets" / "delivery-linearized.pdf"
        run_qpdf(args.qpdf, ["--linearize", "--deterministic-id", temporary, linearized])
        temporary.unlink()
        linearized.replace(temporary)
    proof = verify_derivative(source, temporary, peer_links, args.verify_render, output / "qa" / "delivery-contacts", asset["id"])
    serving_hash = digest(temporary)
    serving_path = f"assets/{serving_hash}.pdf"
    destination = output / serving_path
    if destination.exists():
        if digest(destination) != serving_hash:
            raise ValueError("Immutable served asset hash conflict")
        temporary.unlink()
    else:
        temporary.replace(destination)
    validation = run_qpdf(args.qpdf, ["--check", destination], allow_warnings=True)
    pages = []
    with pymupdf.open(source) as pdf:
        if len(pdf) != asset["pdf_pages"]:
            raise ValueError(f"Canonical source page count differs: {asset['id']}")
        names = pdf.resolve_names()
        for entry in entries:
            if entry["anchor"] not in names or names[entry["anchor"]]["page"] + 1 != entry["physical_page"]:
                raise ValueError(f"Primary entrypoint does not match the actual canonical destination: {entry['id']}")
        for page in pdf:
            page_entries = [entry for entry in entries if entry["physical_page"] == page.number + 1]
            pages.append({"physical_page": page.number + 1, "printed_label": sog_printed_label(page, page.get_text()), "title": "; ".join(entry["id"] + " — " + entry["title"] for entry in page_entries) or asset["title"], "text": page.get_text()})
    admin_only = asset["asset_type"] == "review_control"
    node_metadata = {"asset_id": asset["id"], "review_edition": args.delivery_review_edition, "parent_identity_id": asset["parent"], "admin_only": admin_only}
    metadata = {
        **node_metadata, "canonical_sha256": source_hash, "source_sha256": source_hash,
        "artifact_pdf": asset["artifact_pdf"], "asset_type": asset["asset_type"], "issue_status": asset["issue_status"],
        "approval_event": asset["approval_event"], "effective_date": asset["effective_date"],
        "physical_start_page": 1, "physical_end_page": asset["pdf_pages"],
        "primary_entries": entries, "subject_aliases": aliases, "peer_links": peer_links,
        "validation": {"whole_canonical_page_coverage": True, "decoded_content_font_image_stream_identity": True, "text_geometry_local_links_named_destinations_identity": True, "render_comparison": "pass" if args.verify_render else "not run", "peer_action_count": len(peer_links), "qpdf_check": validation},
    }
    return {"title": asset["title"], "slug": "asset-" + slug(asset["id"]), "source_path": source_path, "asset_path": serving_path, "sha256": serving_hash, "page_count": asset["pdf_pages"], "version_label": asset["edition"], "revision_date": None, "node_metadata": node_metadata, "metadata": metadata, "pages": pages}, {"asset_id": asset["id"], "canonical_sha256": source_hash, "served_sha256": serving_hash, "peer_action_count": len(peer_links), "pages": proof}


def import_delivery(args, output, sources, audits):
    root, delivery, assets, registry = read_delivery(args.delivery_manifest.resolve(), args.delivery_sha256)
    args.delivery_review_edition = delivery["review_edition"]
    asset_paths = {contained_path(root, asset["artifact_pdf"]): asset_id for asset_id, asset in assets.items()}
    peer_maps = {asset_id: canonical_peer_links(path, assets[asset_id], asset_paths, assets) for path, asset_id in asset_paths.items()}
    pair_counts = Counter((asset_id, link["target_asset_id"], link["original_file"]) for asset_id, links in peer_maps.items() for link in links)
    expected_pairs = {(item["source_asset_id"], item["target_asset_id"], item["literal_portable_relative_pdf_path"]): item["action_count"] for item in delivery["portable_pdf_peer_path_resolution"]}
    if dict(pair_counts) != expected_pairs or sum(pair_counts.values()) != delivery["counts"]["verified_portable_remote_pdf_actions"]:
        raise ValueError("Actual complete peer actions differ from the frozen delivery map")
    metadata = {"review_edition": delivery["review_edition"], "delivery_manifest_sha256": args.delivery_sha256, "source_bindings": delivery["source_bindings"], "counts": delivery["counts"]}
    sog_manual = {"slug": "sogs", "name": "Standard Operating Guidelines", "type": "sog", "version_label": delivery["review_edition"], "metadata": metadata, "sections": []}
    control_manual = {"slug": "sog-review-controls", "name": "SOG Review Controls", "type": "other", "version_label": delivery["review_edition"], "is_active": False, "metadata": {**metadata, "admin_only": True}, "sections": [{"title": "Review Controls", "slug": "review-controls", "metadata": {"admin_only": True}, "children": [], "documents": []}]}
    sections = {}
    for asset in delivery["sog_assets"]:
        if asset["asset_type"] == "main_section":
            sections[asset["section"]] = {"title": asset["title"], "slug": "section-" + asset["section"], "children": [], "documents": []}
    sog_manual["sections"] = [sections[key] for key in sorted(sections)]
    proofs = []
    for asset in delivery["sog_assets"] + delivery["separate_review_controls"]:
        entries = []
        for item in delivery["primary_ids"]:
            if item["owning_asset_id"] != asset["id"]:
                continue
            clauses = registry[item["id"]].get("clauses", [])
            semantic_pages = sorted({item["physical_pdf_page"], *(clause["pdf_page"] for clause in clauses)})
            if any(not isinstance(page, int) or page < 1 or page > asset["pdf_pages"] for page in semantic_pages):
                raise ValueError(f"Semantic page binding leaves its owning asset: {item['id']}")
            entries.append({"id": item["id"], "title": item["title"], "parent": item["parent"], "owner_function": item["owner_function"], "document_class": item["document_class"], "anchor": item["anchor"], "physical_page": item["physical_pdf_page"], "slug": slug(item["id"]), "semantic_pages": semantic_pages, "semantic_text": "\n".join(clause["title"] for clause in clauses)})
        aliases = [item for item in delivery["historical_subject_aliases"] if any(target["owning_asset_id"] == asset["id"] for target in item["resolved_current_targets"])]
        document, proof = stage_asset(args, output, sources, root, asset, entries, aliases, peer_maps[asset["id"]])
        section = control_manual["sections"][0] if asset["asset_type"] == "review_control" else sections[asset["section"]]
        section["documents"].append(document)
        proofs.append(proof)
        print(f"Frozen SOG delivery: {asset['id']} {asset['pdf_pages']} pages, {len(entries)} identities, {len(peer_maps[asset['id']])} current peer routes", flush=True)
    if sum(document["page_count"] for section in sog_manual["sections"] for document in section["documents"]) != delivery["counts"]["sog_source_physical_pages"]:
        raise ValueError("Complete canonical SOG source page coverage differs")
    if sum(document["page_count"] for document in control_manual["sections"][0]["documents"]) != delivery["counts"]["review_control_physical_pages"]:
        raise ValueError("Complete review-control source page coverage differs")
    for asset in assets.values():
        if digest(contained_path(root, asset["artifact_pdf"])) != asset["pdf_sha256"]:
            raise ValueError("Canonical source changed during staging")
    proof = {**metadata, "canonical_assets": proofs, "sog_assets": len(delivery["sog_assets"]), "review_controls": len(delivery["separate_review_controls"]), "primary_ids": len(delivery["primary_ids"]), "subject_specific_aliases": len(delivery["historical_subject_aliases"]), "peer_action_count": sum(pair_counts.values()), "peer_path_pairs": len(pair_counts), "original_source_hashes_unchanged": True}
    (output / "sog-delivery-proof.json").write_text(json.dumps(proof, ensure_ascii=False, indent=2), encoding="utf-8")
    audits.append({key: value for key, value in proof.items() if key != "canonical_assets"})
    return [sog_manual, control_manual]
