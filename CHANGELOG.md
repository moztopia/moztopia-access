# Changelog

## 0.9.0 — Padlock (2026-11-09)

- Introduce independent Laravel roles, permissions and teams with explicit global/team assignment semantics.
- Add `revokeTeam` as the membership-removal counterpart, preserving the team and unrelated grants.
- Use one explicit `assignRole`/`assignPermission`/`assignTeam` and `revokeRole`/`revokePermission`/`revokeTeam` vocabulary without aliases.
- Place the user trait at `Moztopia\Access\Traits\HasAccess` in `src/Traits/`.
- Resolve explicit team scopes by unique stable names or UUID-backed model objects, with separate display labels.
- Add the `HasAccess` trait and trusted `AccessService` administration API.
- Enforce UUID relationships, membership-dependent grants, uniqueness and transactional mutations.
- Add Pest integration and independent-process concurrency tests, publishable resources and Git-managed wiki documentation.

Initial MIT-licensed public release.
