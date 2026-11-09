# Changelog

## Unreleased

- Introduce independent Laravel roles, permissions and teams with explicit global/team assignment semantics.
- Prefer `assignRole`, `assignPermission` and `assignTeam`, with equivalent supported grant/give/allow aliases.
- Resolve explicit team scopes by unique stable names or UUID-backed model objects, with separate display labels.
- Add the `HasAccess` trait and trusted `AccessService` administration API.
- Enforce UUID relationships, membership-dependent grants, uniqueness and transactional mutations.
- Add Pest integration and independent-process concurrency tests, publishable resources and Git-managed wiki documentation.

No release or license has been authorized.
