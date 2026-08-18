import pathlib
import re
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[3]
RESTORE_SCRIPTS = (
    ROOT / "scripts" / "operations" / "mbfd-ecosystem-restore-smoke.sh",
    ROOT / "infra" / "backup" / "mbfd-ecosystem-restore-smoke.sh",
)


class RestoreChecksumScopeTests(unittest.TestCase):
    def test_partial_restore_verifies_each_required_artifact_only(self):
        for script_path in RESTORE_SCRIPTS:
            with self.subTest(script=script_path.relative_to(ROOT)):
                source = script_path.read_text(encoding="utf-8")
                self.assertIn("required_artifacts=(", source)
                self.assertIn("stage_dir_candidates=(", source)
                self.assertIn('"$candidate/databases/mbfd-hub.dump"', source)
                self.assertIn('"${#stage_dir_candidates[@]}" -eq 1', source)
                self.assertIn('relative="${artifact#"$manifest_dir"/}"', source)
                self.assertRegex(
                    source,
                    re.compile(r"awk -v expected=.*required_manifest", re.DOTALL),
                )
                self.assertIn('sha256sum -c "$required_manifest" --quiet', source)
                self.assertNotIn("sha256sum -c SHA256SUMS", source)
                self.assertNotRegex(source, r'mbfd_dump="\$\(find .* -name mbfd-hub\.dump')


if __name__ == "__main__":
    unittest.main()
