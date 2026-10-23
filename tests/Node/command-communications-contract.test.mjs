import assert from "node:assert/strict";
import { readdirSync, readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const src = resolve(root, "resources/js/daily-checkout/src");
const commsDir = resolve(src, "components/command-communications");
const read = (path) => readFileSync(resolve(src, path), "utf8");
const commsFiles = readdirSync(commsDir).map((name) => ({ name, source: readFileSync(resolve(commsDir, name), "utf8") }));
const surfaces = [
  ...commsFiles,
  { name: "commandCommunicationsApi.ts", source: read("utils/commandCommunicationsApi.ts") },
  { name: "Command300Page.tsx", source: read("components/Command300Page.tsx") },
  { name: "Command300Card.tsx", source: read("components/Command300Card.tsx") },
];

test("command communications never inject HTML", () => {
  for (const { name, source } of surfaces) {
    assert.doesNotMatch(source, /dangerouslySetInnerHTML|innerHTML\s*=|insertAdjacentHTML/, `${name} must render text as React nodes`);
  }
});

test("command communications drafts are never persisted on shared workstations", () => {
  for (const { name, source } of surfaces) {
    assert.doesNotMatch(source, /localStorage|sessionStorage|indexedDB|lib\/db|cachedData/, `${name} must keep drafts in memory only`);
  }
});

test("links are limited to http(s) and carry a safe rel", () => {
  const linkify = read("components/command-communications/linkify.ts");
  assert.match(linkify, /https\?:\\\/\\\//);
  assert.match(linkify, /protocol === 'http:' \|\| url\.protocol === 'https:'/);
  const post = read("components/command-communications/PostItem.tsx");
  assert.match(post, /rel="noopener noreferrer nofollow"/);
  assert.match(post, /target="_blank"/);
});

test("components carry no hex colour literals and stay small", () => {
  for (const { name, source } of commsFiles.filter((file) => file.name.endsWith(".tsx"))) {
    assert.doesNotMatch(source, /#[0-9a-fA-F]{3,8}\b/, `${name} must use hub tokens`);
  }
  for (const { name, source } of commsFiles) {
    assert.ok(source.split("\n").length < 300, `${name} must stay under 300 lines`);
  }
});

test("Communications is the first and default tab", () => {
  const page = read("components/Command300Page.tsx");
  const tabs = /const tabs: TabItem<string>\[\] = \[\s*\{ id: COMMS_TAB, label: 'Communications'/;
  assert.match(page, tabs);
  assert.match(page, /const COMMS_TAB = 'communications'/);
  assert.match(page, /\(known \|\| pending\) && requested \? requested : COMMS_TAB/);
  assert.match(page, /next\.delete\('station'\)/);
});

test("submission is idempotent and shows no message before the server answers", () => {
  const api = read("utils/commandCommunicationsApi.ts");
  assert.match(api, /client_submission_id/);
  assert.match(api, /xhr\.status === 200 \|\| xhr\.status === 201/);
  assert.doesNotMatch(api, /Content-Type'\s*,/, "multipart upload must leave Content-Type to the browser");
  const draft = read("components/command-communications/useCommandDraft.ts");
  assert.match(draft, /crypto\.randomUUID/);
  const hook = read("components/command-communications/useCommandCommunications.ts");
  assert.ok(hook.indexOf("await sendCommandPost") < hook.indexOf("addPost(post)"), "the post is added only after the server responds");
});

test("access gating reads the server capability and 403 is distinct from network errors", () => {
  const identity = read("lib/offlineIdentity.ts");
  assert.match(identity, /command_300_access/);
  assert.match(identity, /commandAccess/);
  const list = read("components/StationListPage.tsx");
  assert.match(list, /commandAccess === 'allowed' && <Command300Card \/>/);
  const page = read("components/Command300Page.tsx");
  assert.match(page, /isForbiddenError\(err\)/);
  assert.match(page, /<AccessDenied \/>/);
});

test("the panel is channel-aware and the 300 channel stays the default", () => {
  const api = read("utils/commandCommunicationsApi.ts");
  assert.match(api, /command_300: '\/api\/command-communications'/);
  assert.match(api, /captain_5: '\/api\/captain5\/communications'/);
  for (const fn of ["fetchCommandPosts", "fetchCommandSummary", "setCommandPostPinned", "markCommandPostsRead", "deleteCommandPost", "sendCommandPost"]) {
    assert.match(api, new RegExp(`${fn}[\\s\\S]{0,260}channel(?:: CommandChannel)? = DEFAULT_CHANNEL`), `${fn} must default to the 300 channel`);
  }
  const hook = read("components/command-communications/useCommandCommunications.ts");
  assert.match(hook, /channel = DEFAULT_CHANNEL/);
  assert.match(hook, /\}, setProgress, channel\)/, "the send goes to the hook's channel");
  const feed = read("components/command-communications/useCommandFeed.ts");
  assert.match(feed, /fetchCommandPosts\(\{ limit: PAGE_SIZE, channel \}\)/);
  assert.match(feed, /markCommandPostsRead\(channel\)/);
  assert.match(feed, /setCommandPostPinned\(post\.id, pinned, channel\)/);
  const panel = read("components/command-communications/CommunicationsPanel.tsx");
  assert.match(panel, /channel = comms\.channel \?\? DEFAULT_CHANNEL/);
  assert.match(panel, /CHANNEL_COPY\[channel\]/);
});

test("the Command 300 page keeps using the unchanged 300 channel", () => {
  const page = read("components/Command300Page.tsx");
  assert.doesNotMatch(page, /captain_5|captain5/, "the 300 page must not point at the Captain 5 channel");
  const card = read("components/Command300Card.tsx");
  assert.doesNotMatch(card, /captain_5|captain5/);
});

test("removal needs a reason, uses DELETE, and removed messages render as tombstones", () => {
  const api = read("utils/commandCommunicationsApi.ts");
  assert.match(api, /method: 'DELETE'/);
  assert.match(api, /JSON\.stringify\(\{ reason \}\)/);
  const control = read("components/command-communications/RemoveControl.tsx");
  assert.match(control, /reason\.trim\(\)\.length < MIN_REASON/);
  const post = read("components/command-communications/PostItem.tsx");
  assert.match(post, /post\.deleted \? <Tombstone/);
  assert.match(post, /reply\.deleted \? <Tombstone/);
  assert.match(post, /post\.can_delete && onRemove/);
  assert.match(post, /!post\.parent_id && post\.audience === 'both'/, "the audience badge shows on shared (Both) posts only");
  // A tombstone shows who removed it, never the original text.
  const tombstone = /function Tombstone[\s\S]*?\n}\n/.exec(post)?.[0] ?? "";
  assert.match(tombstone, /post\.removed\?\.by/);
  assert.doesNotMatch(tombstone, /post\.body|post\.subject/);
  const normalizer = read("utils/commandCommunicationsApi.ts");
  assert.match(normalizer, /can_delete: item\.can_delete === true && item\.deleted !== true/);
});
