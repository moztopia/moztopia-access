# Maintaining the public repository

## Access and review

Keep write/admin access limited to trusted maintainers and review it periodically. Public users can fork, open issues/Discussions and propose PRs; that does not grant write access. Do not grant collaborator access just to remove a contribution-volume cap. Code owners identify reviewers, not an invitation to share account credentials.

Integration branches (`develop`, future `main`, `staging` and maintenance lines) require PRs and resolved conversations. Normal review requires a fresh approval from another maintainer and applicable code-owner review. Explicit repository-owner approval is also sufficient, including for a PR authored using the owner's account. Only the owner's user identity has a PR-only exception to the additional review rule; other actors retain the normal review gate.

Before using that exception, record the owner's explicit approval on the PR/issue or reference the approving instruction from the active work session. An agent using the owner's credentials must not interpret access to the exception as permission to merge. GitHub cannot distinguish an automated caller from the human using the same account. A request to change the rule is not itself approval of an open PR. The owner explicitly authorized the initial Access 0.9.0 promotion and main merge in this work session; future main merges require separate owner authorization.

Force pushes and deletion remain blocked by the inherited organization rules, including for the owner. Tag immutability and release protections are unchanged. The exception is limited to PR integration; it does not authorize direct protected-branch pushes or arbitrary governance bypasses.

Keep `develop` as the contribution target. Move the public default to `main` only through the separately reviewed public release promotion. Release tags are administrator-created, cannot be changed/deleted, and published GitHub releases are immutable. Corrections require a new version. Protect Packagist maintainer accounts as carefully as GitHub accounts; do not add maintainers or change the package source casually.

## Automation and secrets

The repository defaults Actions tokens to read-only and disables Actions approval of PR reviews. All external contributors require workflow-run approval. Only local actions and GitHub-owned external actions are currently allowed; evaluate and explicitly allowlist any other action before use. Do not approve a run until its workflow and executed code are reviewed. Avoid privileged `pull_request_target` execution of fork code, self-hosted runners for untrusted PRs, credential-bearing test fixtures and persistent checkout credentials when not required.

No test CI workflow or required status check is invented by this settings change. Add actual checks through a reviewed change before requiring their exact names. Existing package checks are `composer test`, `composer lint` and Composer validation. Provider mocks require no live credentials.

Dependency alerts, private vulnerability reporting, secret scanning and push protection are enabled. Investigate alerts promptly; do not bypass a secret finding just to unblock a push. Revoke exposed credentials with their provider before treating repository removal as remediation. Automatic dependency-update PRs need branch names and issue tracking compatible with organization governance; do not weaken organization rules to enable a bot.

## Moderation

Use Q&A for support, Ideas for proposals, issues for scoped work and private reports for vulnerabilities. The issue/discussion forms request safe, actionable context. They help triage; they are not an anti-spam authentication barrier, and API clients can still submit content.

External users can have at most five concurrent open PRs, including drafts. Handle regular contributors reasonably; review trusted-user exemptions only when justified. Leave ordinary participation open. For an actual abuse surge, use GitHub's moderation tools: hide/delete spam, report malicious content, block repeat abusers where permitted, lock a thread when needed, and apply the narrowest short-lived interaction restriction. Remove temporary limits after the incident. Do not publish reporter details or retaliate against good-faith criticism/security research.

The wiki remains publicly readable but editable only by users in teams with push access. Follow the local ignored `wiki/` clone workflow and record wiki commits on the parent repository issue. Review links and images before publishing.

## Account and incident controls

Maintainers should use passkeys or security-key-backed 2FA, unique passwords, limited-lifetime narrowly scoped tokens and protected recovery methods. Repository settings cannot establish that these personal/organization account controls are satisfied. Review organization 2FA enforcement and GitHub/Packagist account recovery separately; do not change organization-wide membership/security policies as a side effect of one repository task.

If an account or release is compromised, contain access first, revoke/rotate credentials, review organization/repository audit logs and hooks, assess affected tags/package versions, coordinate private disclosure, and publish a new fixed version with clear advisories. Immutable tags/releases help preserve evidence; do not promise that they prevent all account or supply-chain compromise.

## Funding and support

The owner-provided funding destination is [Buy Me a Coffee](https://buymeacoffee.com/mozrin). Keep the funding file and public links consistent. Sponsorship conveys no merge rights, security exception, guaranteed response time or paid support commitment.
