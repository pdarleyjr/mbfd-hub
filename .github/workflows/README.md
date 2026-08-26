# Hub workflow controls

`ci.yml` runs the reusable `hub-release-gates.yml` workflow for pull requests
to `main` and for `main` itself. It is validation-only: no self-hosted runner,
production environment, deployment secret, or SSH target is available to it.

`deploy.yml` has no push trigger. An operator must dispatch it from `main`,
set `confirm_production_activation` to true, pass the reusable gates for the
workflow's exact `github.sha`, and receive the GitHub `production` environment
approval before its Hub-only deployment job is eligible to run. The remote
checkout verifies the candidate SHA is a `main` ancestor and detaches at that
exact commit; it does not substitute the moving `origin/main` tip after gates.

The production Compose file currently builds the Sail application runtime with
PHP 8.5, while the baseline CI lane is PHP 8.4. `php-85-compatibility` is
therefore a required PostgreSQL compatibility lane in `hub-release-gates.yml`;
it is not advisory and cannot be skipped by release aggregation.

`production-activate.yml` is a separate, explicitly confirmed main-ref Hub
operations workflow for its documented runtime toggles. It is not a code
release path and does not replace `deploy.yml` or its candidate gates.

`deploy-support-ai-worker.yml` is likewise manual and main-ref only. Its
locked dependency and high-severity audit validation must pass before its own
environment-protected worker deployment job becomes eligible. It is separate
from the Laravel/Filament Hub candidate gate and does not affect Media Control.

The source controls above cannot prove repository branch-protection rules or
environment approvers are configured in GitHub. Those settings must remain
enabled for the `production` environment before any manual activation window;
branch protection must require the reusable workflow's aggregate release-gate
check after this workflow layout is adopted.
