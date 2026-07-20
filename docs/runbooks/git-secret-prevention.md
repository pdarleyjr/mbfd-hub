# Git secret prevention

MBFD Hub uses Gitleaks in GitHub Actions and in versioned local hooks. The local
hooks fail closed when the scanner is missing so a commit or push cannot silently
bypass the check.

## One-time workstation setup

1. Install a verified Gitleaks release.
2. Configure the repository to use the versioned hooks and scanner:

   ```sh
   git config core.hooksPath .githooks
   git config mbfd.gitleaksPath /absolute/path/to/gitleaks
   ```

3. Confirm the staged-content check works:

   ```sh
   .githooks/pre-commit
   ```

Never place a token in a remote URL, shell history, tracked documentation, or a
hook configuration value. `mbfd.gitleaksPath` stores only the scanner path.
