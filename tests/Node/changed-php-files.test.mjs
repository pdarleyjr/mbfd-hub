import assert from "node:assert/strict";
import test from "node:test";

import {
  collectChangedPhpFiles,
  parseChangedPhpFiles,
  runPintForChangedPhpFiles,
} from "../../scripts/ci/changed-php-files.mjs";

test("parses added, modified, copied, and renamed PHP paths without splitting spaces", () => {
  const output = Buffer.from(
    [
      "A",
      "app/Services/New Service.php",
      "M",
      "tests/Feature/Existing Test.php",
      "C100",
      "app/Source.php",
      "app/Copied Service.php",
      "R100",
      "app/Old Service.php",
      "app/Renamed Service.php",
      "R100",
      "app/Old.php",
      "docs/not-php.md",
      "D",
      "app/Deleted.php",
      "",
    ].join("\0"),
    "utf8",
  );

  assert.deepEqual(parseChangedPhpFiles(output), [
    "app/Copied Service.php",
    "app/Renamed Service.php",
    "app/Services/New Service.php",
    "tests/Feature/Existing Test.php",
  ]);
});

test("collects only changed PHP paths from the supplied base and head revisions", () => {
  const calls = [];
  const runner = (command, args) => {
    calls.push([command, args]);

    return {
      status: 0,
      stdout: Buffer.from("M\0app/Console/Command.php\0A\0docs/audit.md\0", "utf8"),
      stderr: Buffer.alloc(0),
    };
  };

  assert.deepEqual(
    collectChangedPhpFiles({
      base: "base-sha",
      head: "head-sha",
      gitBinary: "C:/Program Files/Git/cmd/git.exe",
      runner,
    }),
    ["app/Console/Command.php"],
  );
  assert.deepEqual(calls, [[
    "C:/Program Files/Git/cmd/git.exe",
    [
      "diff",
      "--name-status",
      "-z",
      "--find-renames",
      "--diff-filter=ACMR",
      "base-sha",
      "head-sha",
    ],
  ]]);
});

test("requires both revisions before starting Git", () => {
  assert.throws(() => collectChangedPhpFiles({ base: "", head: "head-sha" }), /PINT_BASE_SHA and PINT_HEAD_SHA/);
  assert.throws(() => collectChangedPhpFiles({ base: "base-sha", head: " " }), /PINT_BASE_SHA and PINT_HEAD_SHA/);
});

test("does not invoke Pint when a candidate contains no changed PHP files", () => {
  const calls = [];
  const result = runPintForChangedPhpFiles({
    files: [],
    runner: (command, args) => {
      calls.push([command, args]);

      return { status: 0 };
    },
  });

  assert.equal(result, 0);
  assert.deepEqual(calls, []);
});

test("invokes Pint through PHP with explicit file arguments and propagates a style failure", () => {
  const calls = [];
  assert.throws(() => {
    runPintForChangedPhpFiles({
      files: ["--unexpected-option.php", "app/Service With Space.php", "tests/Feature/Example.php"],
      phpBinary: "C:/PHP/php.exe",
      pintScript: "vendor/bin/pint",
      runner: (command, args) => {
        calls.push([command, args]);

        return { status: 1 };
      },
    });
  }, /exited with status 1/);
  assert.deepEqual(calls, [[
    "C:/PHP/php.exe",
    ["vendor/bin/pint", "--test", "--", "--unexpected-option.php", "app/Service With Space.php", "tests/Feature/Example.php"],
  ]]);
});
