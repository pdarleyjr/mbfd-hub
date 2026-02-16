#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import sqlite3
import sys
import time
from dataclasses import dataclass, asdict, field
from datetime import datetime, timezone
from typing import Dict, List, Set, Tuple
from urllib.parse import urljoin, urlparse, urlunparse, urldefrag

import httpx
from bs4 import BeautifulSoup
from markdownify import markdownify as html2md

DOCS_BASE_DEFAULT = "https://www.chatify.com/docs"
DOCS_PREFIX_DEFAULT = "/docs"

KNOWN_SEEDS = [
    "https://www.chatify.com/docs",
    "https://www.chatify.com/docs/quick-start",
    "https://www.chatify.com/docs/team",
    "https://www.chatify.com/docs/account",
    "https://www.chatify.com/docs/workflow",
    "https://www.chatify.com/docs/managing-questions",
    "https://www.chatify.com/docs/faqs",
    "https://www.chatify.com/docs/integrations",
    "https://www.chatify.com/docs/live-chat",
    "https://www.chatify.com/docs/community-qa",
    "https://www.chatify.com/docs/google-tag-manager",
    "https://www.chatify.com/docs/gdpr",
    "https://www.chatify.com/docs/live-qa",
    "https://www.chatify.com/docs/liveblog",
    "https://www.chatify.com/docs/live-event-guidelines",
]

COMMON_SITEMAPS = [
    "/robots.txt",
    "/sitemap.xml",
    "/sitemap_index.xml",
    "/sitemap-index.xml",
    "/sitemap/sitemap.xml",
    "/sitemap.txt",
]

TRACKING_KEYS = {"gclid", "fbclid"}
def utc_now():
    return datetime.now(timezone.utc).isoformat()

def canonicalize(url: str) -> str:
    url, _ = urldefrag(url)
    p = urlparse(url)
    scheme = "https" if p.scheme in ("http", "https", "") else p.scheme
    netloc = p.netloc.lower()
    if netloc.endswith(":80"): netloc = netloc[:-3]
    if netloc.endswith(":443"): netloc = netloc[:-4]
    path = re.sub(r"/{2,}", "/", p.path or "/")
    if path != "/" and path.endswith("/"):
        path = path[:-1]
    # strip UTM/tracking params but keep functional params
    query = ""
    if p.query:
        parts = []
        for kv in p.query.split("&"):
            k = kv.split("=", 1)[0].lower()
            if k.startswith("utm_") or k in TRACKING_KEYS:
                continue
            parts.append(kv)
        query = "&".join(parts)
    return urlunparse((scheme, netloc, path, "", query, ""))

def stable_id(url: str) -> str:
    return hashlib.sha256(url.encode("utf-8")).hexdigest()[:16]

def is_docs_url(url: str, docs_prefix: str) -> bool:
    p = urlparse(url)
    return p.netloc.endswith("chatify.com") and p.path.startswith(docs_prefix)

def parse_sitemap_xml(xml_text: str) -> List[str]:
    urls: List[str] = []
    try:
        s = BeautifulSoup(xml_text, "xml")
        for loc in s.find_all("loc"):
            u = loc.get_text(strip=True)
            if u:
                urls.append(canonicalize(u))
    except Exception:
        return []
    return urls

def discover_sitemaps_from_robots(robots_text: str, site_root: str) -> List[str]:
    out = []
    for line in robots_text.splitlines():
        if line.lower().startswith("sitemap:"):
            sm = line.split(":", 1)[1].strip()
            if sm:
                out.append(canonicalize(urljoin(site_root, sm)))
    return out

def extract_main(soup: BeautifulSoup) -> BeautifulSoup:
    for sel in ["main", "article", "#content", ".content", ".container", "body"]:
        node = soup.select_one(sel)
        if node:
            return node
    return soup

def strip_noise(node: BeautifulSoup) -> None:
    for tag in node.select("script, style, nav, footer, header, noscript"):
        tag.decompose()

def extract_headings(node: BeautifulSoup) -> Tuple[str, str, List[dict]]:
    h1 = ""
    headings = []
    for lvl in range(1, 7):
        for h in node.find_all(f"h{lvl}"):
            t = h.get_text(" ", strip=True)
            if not t:
                continue
            if lvl == 1 and not h1:
                h1 = t
            headings.append({"level": f"h{lvl}", "text": t})
    title = h1
    return title, h1, headings

def extract_code_blocks(node: BeautifulSoup) -> List[dict]:
    blocks = []
    for pre in node.find_all("pre"):
        code = pre.find("code")
        if code:
            lang = ""
            for c in (code.get("class") or []):
                if c.startswith("language-"):
                    lang = c.replace("language-", "")
            txt = code.get_text("\n", strip=False).rstrip()
        else:
            lang = ""
            txt = pre.get_text("\n", strip=False).rstrip()
        if txt.strip():
            blocks.append({"language": lang, "code": txt})
    return blocks

def extract_images(base_url: str, node: BeautifulSoup) -> List[dict]:
    imgs = []
    for img in node.find_all("img"):
        src = (img.get("src") or "").strip()
        if not src:
            continue
        abs_src = canonicalize(urljoin(base_url, src))
        alt = (img.get("alt") or "").strip()
        imgs.append({"src": abs_src, "alt": alt})
    # dedupe
    seen = set()
    out = []
    for i in imgs:
        k = (i["src"], i["alt"])
        if k in seen:
            continue
        seen.add(k)
        out.append(i)
    return out

def extract_internal_links(base_url: str, soup: BeautifulSoup, docs_prefix: str) -> List[str]:
    links: Set[str] = set()
    for a in soup.find_all("a", href=True):
        href = a["href"].strip()
        if not href or href.startswith(("mailto:", "tel:", "javascript:")):
            continue
        u = canonicalize(urljoin(base_url, href))
        if is_docs_url(u, docs_prefix):
            links.add(u)
    return sorted(links)

def extract_any_docs_urls_from_raw_html(base_url: str, html: str, docs_prefix: str) -> Set[str]:
    """
    Catch URLs that aren't in <a href>, e.g. inside scripts, JSON blobs, data attributes, etc.
    """
    found: Set[str] = set()

    # Absolute URLs
    for m in re.finditer(r"https?://www\.chatify\.com/docs/[^\s\"'<>)]*", html):
        found.add(canonicalize(m.group(0)))

    # Relative /docs/... patterns
    for m in re.finditer(r"(?<![a-zA-Z0-9])(/docs/[a-zA-Z0-9_\-\/]+)", html):
        found.add(canonicalize(urljoin(base_url, m.group(1))))

    # Filter strictly to docs prefix
    return {u for u in found if is_docs_url(u, docs_prefix)}

@dataclass
class Page:
    id: str
    url: str
    fetched_at_utc: str
    status_code: int
    title: str = ""
    h1: str = ""
    headings: List[dict] = field(default_factory=list)
    plain_text: str = ""
    markdown: str = ""
    code_blocks: List[dict] = field(default_factory=list)
    images: List[dict] = field(default_factory=list)
    internal_links: List[str] = field(default_factory=list)
    errors: List[str] = field(default_factory=list)

def fetch(client: httpx.Client, url: str, timeout: float, retries: int, delay: float) -> Tuple[int, str, str]:
    last_err = ""
    for _ in range(retries):
        try:
            r = client.get(url, timeout=timeout, follow_redirects=True)
            if delay:
                time.sleep(delay)
            return r.status_code, r.text, ""
        except Exception as e:
            last_err = f"{type(e).__name__}: {e}"
            if delay:
                time.sleep(delay)
    return 0, "", last_err

def build_fts_index(outdir: str, pages: List[Page]) -> str:
    db_path = os.path.join(outdir, "chatify_docs_search.sqlite")
    if os.path.exists(db_path):
        os.remove(db_path)
    con = sqlite3.connect(db_path)
    cur = con.cursor()
    cur.execute("PRAGMA journal_mode=WAL;")
    cur.execute("CREATE VIRTUAL TABLE docs USING fts5(url, title, headings, content);")
    for p in pages:
        headings_txt = "\n".join([f'{h["level"]}: {h["text"]}' for h in p.headings])
        cur.execute(
            "INSERT INTO docs(url, title, headings, content) VALUES (?, ?, ?, ?)",
            (p.url, p.title, headings_txt, p.markdown or p.plain_text),
        )
    con.commit()
    con.close()
    return db_path

def write_outputs(outdir: str, pages: List[Page], manifest: dict) -> None:
    os.makedirs(outdir, exist_ok=True)
    pages_dir = os.path.join(outdir, "pages")
    os.makedirs(pages_dir, exist_ok=True)

    # JSONL
    jsonl_path = os.path.join(outdir, "chatify_docs_corpus.jsonl")
    with open(jsonl_path, "w", encoding="utf-8") as f:
        for p in pages:
            f.write(json.dumps(asdict(p), ensure_ascii=False) + "\n")

    # JSON array
    json_path = os.path.join(outdir, "chatify_docs_corpus.json")
    with open(json_path, "w", encoding="utf-8") as f:
        json.dump([asdict(p) for p in pages], f, ensure_ascii=False, indent=2)

    # Per-page markdown + combined
    combined_md = []
    toc_lines = ["# Chatify Docs TOC\n"]
    toc_json = []

    for p in pages:
        slug = urlparse(p.url).path.strip("/").replace("/", "__") or "docs_root"
        md_file = os.path.join(pages_dir, f"{slug}.md")
        title = p.title or p.h1 or p.url
        header = f"# {title}\n\nSource: {p.url}\n\n"
        body = (p.markdown or "").strip()
        with open(md_file, "w", encoding="utf-8") as f:
            f.write(header + body + "\n")
        combined_md.append(header + body + "\n\n---\n")

        toc_lines.append(f"- [{title}]({os.path.relpath(md_file, outdir)})\n")
        if p.headings:
            for h in p.headings:
                if h["level"] in ("h2","h3"):
                    indent = "  " if h["level"] == "h2" else "    "
                    toc_lines.append(f"{indent}- {h['text']}\n")

        toc_json.append({"url": p.url, "title": title, "headings": p.headings})

    with open(os.path.join(outdir, "chatify_docs.md"), "w", encoding="utf-8") as f:
        f.write("\n".join(combined_md))
    with open(os.path.join(outdir, "toc.md"), "w", encoding="utf-8") as f:
        f.write("".join(toc_lines))
    with open(os.path.join(outdir, "toc.json"), "w", encoding="utf-8") as f:
        json.dump(toc_json, f, ensure_ascii=False, indent=2)

    with open(os.path.join(outdir, "manifest.json"), "w", encoding="utf-8") as f:
        json.dump(manifest, f, ensure_ascii=False, indent=2)

def crawl(base_url: str, outdir: str, docs_prefix: str, delay: float, timeout: float, retries: int, user_agent: str) -> None:
    base_url = canonicalize(base_url)
    parsed = urlparse(base_url)
    site_root = f"{parsed.scheme}://{parsed.netloc}"

    seeds: Set[str] = set(canonicalize(u) for u in KNOWN_SEEDS)
    seeds.add(base_url)

    # best-effort sitemap discovery
    sitemap_candidates: Set[str] = set()
    sitemap_urls: Set[str] = set()

    headers = {"User-Agent": user_agent}
    with httpx.Client(headers=headers) as client:
        for path in COMMON_SITEMAPS:
            u = canonicalize(urljoin(site_root, path))
            status, text, err = fetch(client, u, timeout, retries, delay)
            if status == 200 and text:
                if path.endswith("robots.txt"):
                    for sm in discover_sitemaps_from_robots(text, site_root):
                        sitemap_candidates.add(sm)
                elif path.endswith(".xml"):
                    for loc in parse_sitemap_xml(text):
                        if is_docs_url(loc, docs_prefix):
                            sitemap_urls.add(loc)

        for sm in sorted(sitemap_candidates):
            status, text, err = fetch(client, sm, timeout, retries, delay)
            if status == 200 and text:
                for loc in parse_sitemap_xml(text):
                    if is_docs_url(loc, docs_prefix):
                        sitemap_urls.add(loc)

    seeds |= sitemap_urls

    # fixpoint crawl: keep crawling until no new /docs URLs exist
    queue: List[str] = sorted(seeds)
    seen: Set[str] = set()
    pages: Dict[str, Page] = {}
    errors: List[dict] = []
    link_graph: Dict[str, List[str]] = {}

    with httpx.Client(headers=headers) as client:
        while queue:
            url = queue.pop(0)
            if url in seen:
                continue
            seen.add(url)

            status, html, err = fetch(client, url, timeout, retries, delay)
            page = Page(id=stable_id(url), url=url, fetched_at_utc=utc_now(), status_code=status)

            if err:
                page.errors.append(err)
                pages[url] = page
                errors.append({"url": url, "error": err})
                continue

            if status >= 400 or not html.strip():
                page.errors.append(f"HTTP {status} or empty body")
                pages[url] = page
                errors.append({"url": url, "error": page.errors[-1]})
                continue

            soup = BeautifulSoup(html, "lxml")
            main = extract_main(soup)
            strip_noise(main)

            title, h1, headings = extract_headings(main)
            if not title:
                t = soup.title.get_text(" ", strip=True) if soup.title else ""
                title = t

            page.title = title or ""
            page.h1 = h1 or ""
            page.headings = headings

            page.plain_text = re.sub(r"\n{3,}", "\n\n", main.get_text("\n", strip=True)).strip()
            page.markdown = re.sub(r"\n{3,}", "\n\n", html2md(str(main), heading_style="ATX")).strip()

            page.code_blocks = extract_code_blocks(main)
            page.images = extract_images(url, main)

            internal_links = set(extract_internal_links(url, soup, docs_prefix))
            # also scan raw html for /docs links hidden in scripts/data blobs
            internal_links |= extract_any_docs_urls_from_raw_html(url, html, docs_prefix)

            page.internal_links = sorted(internal_links)
            link_graph[url] = page.internal_links

            pages[url] = page

            for nxt in page.internal_links:
                if nxt not in seen:
                    queue.append(nxt)
            queue = sorted(set(queue))  # dedupe + deterministic

    # Build ordered list
    ordered_pages = [pages[u] for u in sorted(pages.keys())]

    manifest = {
        "base": base_url,
        "docs_prefix": docs_prefix,
        "generated_at_utc": utc_now(),
        "counts": {
            "seed_urls": len(seeds),
            "sitemap_urls": len(sitemap_urls),
            "crawled_urls_total": len(ordered_pages),
            "ok_200": sum(1 for p in ordered_pages if p.status_code == 200 and not p.errors),
            "errors": len([p for p in ordered_pages if p.errors]),
        },
        "seed_urls": sorted(seeds),
        "crawled_urls": [p.url for p in ordered_pages],
        "errors": [{"url": p.url, "errors": p.errors} for p in ordered_pages if p.errors],
        "link_graph": link_graph,
    }

    # Outputs
    write_outputs(outdir, ordered_pages, manifest)
    build_fts_index(outdir, ordered_pages)

    print("CRAWL COMPLETE")
    print(json.dumps(manifest["counts"], indent=2))
    if manifest["counts"]["errors"] > 0:
        print("ERRORS PRESENT. See out/manifest.json", file=sys.stderr)
        sys.exit(2)

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default=DOCS_BASE_DEFAULT)
    ap.add_argument("--outdir", default="out")
    ap.add_argument("--docs-prefix", default=DOCS_PREFIX_DEFAULT)
    ap.add_argument("--delay", type=float, default=0.25)
    ap.add_argument("--timeout", type=float, default=30.0)
    ap.add_argument("--retries", type=int, default=4)
    ap.add_argument("--user-agent", default="Mozilla/5.0 (compatible; ChatifyDocsExporter/2.0)")
    args = ap.parse_args()
    crawl(args.base, args.outdir, args.docs_prefix, args.delay, args.timeout, args.retries, args.user_agent)

if __name__ == "__main__":
    main()
