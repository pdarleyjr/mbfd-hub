#!/usr/bin/env node
// Proves that the parallel "Daily Checkout mocked Playwright" matrix lanes in
// the shared Hub release gate run every mocked Daily Checkout test exactly once.
//
// Static mode (imported by tests/Node/ci-configuration.test.mjs) compares the
// workflow matrix with the project names declared in the Playwright config.
// CLI mode additionally resolves the real Playwright test list for the full
// config and for each lane's exact --project arguments, and fails unless the
// lanes are disjoint and their union equals the full serial test list.
import { spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
export const WORKFLOW_PATH = ".github/workflows/hub-release-gates.yml";
export const CONFIG_PATH = "playwright.daily-checkout.config.ts";
export const MATRIX_JOB = "daily-checkout-browser";

export function workflowJobBody(workflow, name) {
  const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  return workflow.match(
    new RegExp(
      `^  ${escapedName}:\\r?\\n(?<body>[\\s\\S]*?)(?=^  [A-Za-z0-9_-]+:\\r?$|(?![\\s\\S]))`,
      "m",
    ),
  )?.groups?.body;
}

/** Returns [{ lane, projects: string[] }] for every matrix include entry. */
export function matrixLanes(workflow) {
  const job = workflowJobBody(workflow, MATRIX_JOB);
  if (!job) {
    throw new Error(`${WORKFLOW_PATH} must define the ${MATRIX_JOB} job`);
  }
  const include = job.match(/^      matrix:\r?\n        include:\r?\n(?<body>[\s\S]*?)(?=^    [A-Za-z]|(?![\s\S]))/m)
    ?.groups?.body;
  if (!include) {
    throw new Error(`${MATRIX_JOB} must declare its lanes with strategy.matrix.include`);
  }

  return include
    .split(/^          - /m)
    .slice(1)
    .map((entry) => ({
      lane: entry.match(/^lane:\s*([A-Za-z0-9_-]+)/m)?.[1] ?? "",
      browsers: (entry.match(/^ {12}browsers:[ \t]*(.+?)\r?$/m)?.[1] ?? "").split(/\s+/).filter(Boolean),
      workers: Number(entry.match(/^ {12}workers:[ \t]*(\d+)/m)?.[1] ?? 0),
      projects: [...entry.matchAll(/--project=([A-Za-z0-9_.-]+)/g)].map((match) => match[1]),
    }));
}

/** Names of the literal Daily Playwright projects that run in WebKit. */
export function webkitProjects(config) {
  const projectsBlock = config.match(/^  projects: \[(?<body>[\s\S]*?)^  \],/m)?.groups?.body ?? "";
  const starts = [...projectsBlock.matchAll(/name:\s*(?:'(?<literal>[^']+)'|`)/g)];

  return starts
    .map((start, index) => ({
      name: start.groups.literal,
      body: projectsBlock.slice(start.index, starts[index + 1]?.index ?? projectsBlock.length),
    }))
    .filter(({ name, body }) => name !== undefined && /browserName:\s*'webkit'/.test(body))
    .map(({ name }) => name);
}

/**
 * Returns violations when a lane installs browsers it does not need to, or,
 * more importantly, runs a project in a browser it did not install.
 */
export function browserViolations(lanes, projects, webkit) {
  const violations = [];
  const webkitNames = new Set(webkit);
  const allowed = new Set(["chromium", "webkit"]);

  for (const { lane, browsers = [], workers, projects: laneProjects } of lanes) {
    for (const browser of browsers) {
      if (!allowed.has(browser)) violations.push(`lane ${lane} installs unknown browser ${browser}`);
    }
    if (workers !== undefined && !(workers >= 1 && workers <= 4)) {
      violations.push(`lane ${lane} must declare 1-4 Playwright workers`);
    }
    if (workers !== undefined && workers > 1 && laneProjects.some((project) => webkitNames.has(project))) {
      violations.push(`lane ${lane} runs WebKit projects with ${workers} workers; the long WebKit tests need one`);
    }
    if (browsers.length === 0) violations.push(`lane ${lane} must declare the browsers it installs`);
    const needsWebkit = laneProjects.some((project) => webkitNames.has(project));
    const needsChromium = laneProjects.some((project) => projects.includes(project) && !webkitNames.has(project));
    if (needsWebkit && !browsers.includes("webkit")) {
      violations.push(`lane ${lane} runs a WebKit project but does not install webkit`);
    }
    if (needsChromium && !browsers.includes("chromium")) {
      violations.push(`lane ${lane} runs a Chromium project but does not install chromium`);
    }
    if (!needsWebkit && browsers.includes("webkit")) {
      violations.push(`lane ${lane} installs webkit but runs no WebKit project`);
    }
  }

  return violations;
}

/** Project names declared in the Daily Playwright config, expanded statically. */
export function declaredProjects(config) {
  const viewportBlock = config.match(/const responsiveViewports = \[(?<body>[\s\S]*?)\] as const;/)?.groups?.body ?? "";
  const viewportNames = [...viewportBlock.matchAll(/name:\s*'([^']+)'/g)].map((match) => match[1]);
  const projectsBlock = config.match(/^  projects: \[(?<body>[\s\S]*?)^  \],/m)?.groups?.body ?? "";
  const literalNames = [...projectsBlock.matchAll(/name:\s*'([^']+)'/g)].map((match) => match[1]);
  const templatePrefix = projectsBlock.match(/name:\s*`([A-Za-z0-9_-]+)\$\{name\}`/)?.[1];

  if (templatePrefix === undefined && viewportNames.length > 0) {
    throw new Error("could not resolve the responsive project name template");
  }

  return [...literalNames, ...viewportNames.map((name) => `${templatePrefix}${name}`)];
}

/** Returns a list of human-readable partition violations (empty when valid). */
export function partitionViolations(lanes, projects) {
  const violations = [];
  const seen = new Map();
  const known = new Set(projects);

  if (lanes.length < 2) {
    violations.push("the mocked Daily Playwright matrix must define at least two parallel lanes");
  }
  for (const { lane, projects: laneProjects } of lanes) {
    if (!lane) violations.push("every matrix lane must have a lane name");
    if (laneProjects.length === 0) violations.push(`lane ${lane || "(unnamed)"} selects no Playwright project`);
    for (const project of laneProjects) {
      if (!known.has(project)) violations.push(`lane ${lane} selects unknown project ${project}`);
      if (seen.has(project)) violations.push(`project ${project} runs in both ${seen.get(project)} and ${lane}`);
      seen.set(project, lane);
    }
  }
  for (const project of projects) {
    if (!seen.has(project)) violations.push(`project ${project} is not assigned to any lane`);
  }

  return violations;
}

function listTests(projectArgs) {
  const result = spawnSync(
    "npx",
    ["playwright", "test", `--config=${CONFIG_PATH}`, "--list", "--reporter=json", ...projectArgs],
    { cwd: root, encoding: "utf8", shell: process.platform === "win32", maxBuffer: 64 * 1024 * 1024 },
  );
  if (result.status !== 0) {
    throw new Error(`playwright --list failed (${result.status}): ${result.stderr}`);
  }
  const report = JSON.parse(result.stdout);
  if (Array.isArray(report.errors) && report.errors.length > 0) {
    throw new Error(`playwright --list reported errors: ${JSON.stringify(report.errors)}`);
  }
  const ids = [];
  const visit = (suite) => {
    for (const spec of suite.specs ?? []) {
      for (const entry of spec.tests ?? []) {
        ids.push(`${entry.projectName} :: ${spec.file}:${spec.line}:${spec.column} :: ${spec.title}`);
      }
    }
    for (const child of suite.suites ?? []) visit(child);
  };
  for (const suite of report.suites ?? []) visit(suite);

  return { ids, projects: (report.config?.projects ?? []).map((project) => project.name) };
}

function main() {
  const workflow = readFileSync(resolve(root, WORKFLOW_PATH), "utf8");
  const lanes = matrixLanes(workflow);
  const full = listTests([]);
  const violations = partitionViolations(lanes, full.projects);
  violations.push(
    ...browserViolations(lanes, full.projects, webkitProjects(readFileSync(resolve(root, CONFIG_PATH), "utf8"))),
  );

  const covered = new Map();
  for (const { lane, projects } of lanes) {
    const laneTests = listTests(projects.map((project) => `--project=${project}`));
    if (laneTests.ids.length === 0) violations.push(`lane ${lane} resolves to zero tests`);
    for (const id of laneTests.ids) {
      if (covered.has(id)) violations.push(`test runs in both ${covered.get(id)} and ${lane}: ${id}`);
      covered.set(id, lane);
    }
  }
  for (const id of full.ids) {
    if (!covered.has(id)) violations.push(`test is not run by any lane: ${id}`);
  }
  if (covered.size !== full.ids.length) {
    violations.push(`lanes run ${covered.size} tests but the serial suite has ${full.ids.length}`);
  }

  if (violations.length > 0) {
    console.error("ERROR: mocked Daily Playwright lanes do not partition the suite:");
    for (const violation of violations) console.error(`  ${violation}`);
    process.exit(1);
  }
  console.log(
    `OK: ${lanes.length} lanes partition all ${full.ids.length} mocked Daily Playwright tests across ${full.projects.length} projects.`,
  );
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main();
}
