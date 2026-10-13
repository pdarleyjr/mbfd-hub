import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { dirname, join, relative, resolve, sep } from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

/*
 * UI modernization contract (WP0 baseline, "White field, grey glass, navy authority").
 *
 * `resources/css/mbfd-theme.css` is the only file allowed to give a `--hub-*` token a value, and
 * every `--hub2-*` name must be a one-line alias of a `--hub-*` token declared there.
 *
 * WP0 added these assertions as `todo` while the repository was in its audited state; WP1
 * consolidated the tokens and they are enforced from here on.
 */

const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const tokenSource = "resources/css/mbfd-theme.css";

// Token names section 4.1 of the modernization brief adds to (or moves into) mbfd-theme.css.
const requiredTokens = [
  "--hub-header",
  "--hub-header-deep",
  "--hub-header-elevated",
  "--hub-glass",
  "--hub-glass-alpha",
  "--hub-glass-border",
  "--hub-glass-highlight",
  "--hub-well",
  "--hub-overlay-alpha",
  "--hub-overlay-blur",
  "--hub-shadow-card",
  "--hub-shadow-elevated",
  "--hub-shell-bar-height",
  "--hub-sidebar-width",
  "--hub-sidebar-collapsed",
  "--hub-content-max",
  "--hub-radius-tag",
  "--hub-radius-control",
  "--hub-radius-card",
  "--hub-radius-sheet",
  "--hub-wallpaper-veil",
];

function cssFiles(directory, accept) {
  const found = [];
  for (const entry of readdirSync(directory)) {
    if (entry === "node_modules") continue;
    const path = join(directory, entry);
    if (statSync(path).isDirectory()) {
      found.push(...cssFiles(path, accept));
    } else if (path.endsWith(".css") && accept(path)) {
      found.push(path);
    }
  }
  return found;
}

// Authored sources only. Built output (public/css/filament, public/build, public/daily) is excluded.
function authoredStylesheets() {
  return [
    ...cssFiles(join(root, "resources"), () => true),
    ...cssFiles(join(root, "public", "css"), path => !path.includes(`${sep}filament${sep}`)),
  ].sort();
}

function stripComments(css) {
  return css.replace(/\/\*[\s\S]*?\*\//g, "");
}

/** Every `--hub-*` / `--hub2-*` custom-property declaration, with the file it lives in. */
function declarations() {
  const found = [];
  for (const file of authoredStylesheets()) {
    const css = stripComments(readFileSync(file, "utf8"));
    for (const match of css.matchAll(/(--hub2?-[\w-]+)\s*:\s*([^;}]+?)\s*(?:;|(?=}))/g)) {
      found.push({ name: match[1], value: match[2].trim(), file: relative(root, file).split(sep).join("/") });
    }
  }
  return found;
}

function filesDeclaring(all, name) {
  return [...new Set(all.filter(item => item.name === name).map(item => item.file))].sort();
}

test("the token inventory finds the stylesheets it is meant to police", () => {
  const all = declarations();
  assert.ok(all.some(item => item.file === tokenSource && item.name === "--hub-ink"), "mbfd-theme.css must be scanned");
  assert.ok(all.some(item => item.name.startsWith("--hub2-")), "the --hub2-* set must be scanned");
  assert.ok(
    authoredStylesheets().every(path => !path.includes("node_modules")),
    "dependencies must not be scanned",
  );
});

test("each section 4.1 token is declared with a value in mbfd-theme.css and nowhere else", () => {
  const all = declarations();
  const problems = [];
  for (const name of requiredTokens) {
    const files = filesDeclaring(all, name);
    if (files.length !== 1 || files[0] !== tokenSource) {
      problems.push(`${name}: declared in [${files.join(", ") || "no file"}], expected only ${tokenSource}`);
    }
  }
  assert.deepEqual(problems, [], `Token declarations are not consolidated:\n${problems.join("\n")}`);
});

test("no --hub-* or --hub2-* token receives a value in more than one stylesheet", () => {
  const all = declarations();
  const offenders = [...new Set(all.map(item => item.name))]
    .map(name => ({ name, files: filesDeclaring(all, name) }))
    .filter(item => item.files.length > 1)
    .map(item => `${item.name}: ${item.files.join(", ")}`);
  assert.deepEqual(offenders, [], `Tokens declared in several files:\n${offenders.join("\n")}`);
});

test("the authority bar navy is the official logo ring, #012766", () => {
  const all = declarations().filter(item => item.file === tokenSource);
  const value = name => all.find(item => item.name === name)?.value;
  assert.equal(value("--hub-header"), "1 39 102", "--hub-header must be #012766");
  assert.equal(value("--hub-header-deep"), "0 16 58", "--hub-header-deep must be #00103A");
  assert.equal(value("--hub-header-elevated"), "1 49 131", "--hub-header-elevated must be #013183");
});

test("no tracked source still uses the retired navy #102A43", () => {
  const tracked = execFileSync("git", ["ls-files", "-z"], { cwd: root, encoding: "utf8", maxBuffer: 64 * 1024 * 1024 })
    .split("\0")
    .filter(path => path !== "" && !path.startsWith("vacation-app/") && !/\.(png|jpe?g|webp|ico|woff2?|pdf|zip|lock)$/i.test(path))
    .filter(path => path !== ".impeccable.md" && path !== "tests/Node/design-tokens-contract.test.mjs");
  const offenders = tracked.filter(path => {
    try {
      return /#102a43/i.test(readFileSync(join(root, path), "utf8"));
    } catch {
      return false;
    }
  });
  assert.deepEqual(offenders, [], `The retired navy remains in:\n${offenders.join("\n")}`);
});

test("every --hub2-* token is a one-line alias of a --hub-* token declared in mbfd-theme.css", () => {
  const all = declarations();
  const hubNames = new Set(all.filter(item => item.file === tokenSource && item.name.startsWith("--hub-")).map(item => item.name));
  const problems = [];
  for (const item of all.filter(entry => entry.name.startsWith("--hub2-"))) {
    const alias = /^var\((--hub-[\w-]+)\)$/.exec(item.value);
    if (item.file !== tokenSource) {
      problems.push(`${item.name} is declared in ${item.file}`);
    } else if (alias === null) {
      problems.push(`${item.name} holds a value (${item.value}) instead of aliasing a --hub-* token`);
    } else if (!hubNames.has(alias[1])) {
      problems.push(`${item.name} aliases ${alias[1]}, which mbfd-theme.css does not declare`);
    }
  }
  assert.deepEqual(problems, [], `--hub2-* tokens are not aliases:\n${problems.join("\n")}`);
});
