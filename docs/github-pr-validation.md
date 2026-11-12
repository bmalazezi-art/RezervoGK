# GitHub pull request validation

The **Gjej Portier PR validation** workflow runs on pull requests targeting `main`. Its stable required check is **PR validation**. It runs on a GitHub-hosted Ubuntu 24.04 runner with read-only repository contents permission, no secrets, no deployment credentials, and checkout credential persistence disabled.

The job installs locked Composer and npm dependencies once, then runs Pint on PHP files changed by the pull request. Laravel tests run when application or PHP test paths change; frontend tests run when frontend, locale, or frontend test paths change. TypeScript validation, the Vite production asset build, and release tooling tests run on every pull request. This keeps unrelated test suites from blocking documentation and deployment-tool-only changes while still testing affected application areas. Tests use in-memory SQLite and no production credentials. The workflow does not run database migrations against a production database or transfer artifacts to a server.

The **Restricted SFTP integration test** remains a separate workflow/check. Its stable check name is **Disposable Ubuntu SFTP jail test**. It exercises a synthetic jail in its disposable GitHub-hosted runner; it is not a production deployment or production configuration check.

## Repository settings to configure manually

For the `main` branch ruleset or protection rule, require both **PR validation** and **Disposable Ubuntu SFTP jail test** before merging. Do not require a deployment or production environment check as part of this validation phase. No GitHub secrets or environments are needed for these workflows.
