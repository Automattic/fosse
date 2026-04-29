# Bluesky Handle Setup — Requirements

## Goal

Let users claim their domain (e.g., `@ryancowl.es`) as their Bluesky handle without leaving WordPress. Today, even after FOSSE connects a Bluesky account via OAuth, the user still has to manually set up DNS TXT or a `/.well-known/atproto-did` file, then go to bsky.app's "Change Handle" UI to complete verification. FOSSE knows the domain (it's running on it) and already knows the DID (stored after OAuth), so most of this can be automated.

Kraft, 2026-04-20: *"I can switch over to use my domain as my handle but looks like I need to do it from the bsky end. I don't see anything locally offering/suggesting."*

## Linear Issues

- **DOTCOM-16801** — Bluesky handle/DID setup done inside WordPress (this epic)
- **DOTCOM-16793** — parent epic (Onboarding & Setup UX)

## Requirements

1. **Serve `/.well-known/atproto-did`** automatically for sites where FOSSE is active and a Bluesky account is connected. Returns plain text containing only the connected account's DID.
2. **DNS TXT fallback display.** For sites that can't serve the well-known route (managed hosts, edge caches, etc.), show the user the exact TXT record content (`_atproto.<domain>` → `did=<did>`) with copy buttons.
3. **Verification status UI.** Tell the user whether their domain currently resolves to their DID. Surface clear next-steps if it doesn't.
4. **Handoff guidance to bsky.app.** Once verification is in place, link the user to Bluesky's "Change Handle" flow with their domain pre-prepared, so they can complete the swap on Bluesky's side.
5. **Bluesky_Provider extension, not a new component.** This work extends the existing provider rather than introducing a new top-level admin surface.
6. **Root-domain eligibility only.** The site must live at the host root for its domain to be usable as an ATProto handle. Subdirectory installs and subdirectory-multisite subsites are ineligible because handles cannot contain URL paths.

## Constraints

- Self-hosted WordPress plugin first.
- PHP 8.2+, WP 6.9+.
- Bundled plugins (`bundled/atmosphere/`) must NOT be modified. If an upstream change is needed, open a PR there.
- The suggested handle is derived from `home_url()`'s host, normalized as a DNS name, and rejected when `home_url()` contains a non-empty path.
- Must pass the existing CI matrix.

## Out of Scope

- Helping the user set up DNS records programmatically (we display, they configure their DNS provider).
- Automating the bsky.app "Change Handle" step itself (Bluesky's API doesn't allow third-party handle changes).
- Multi-user / per-user handles. Single-site-owner flow only.
- Path-based domain handles such as `example.com/blog`; ATProto handles are DNS names only.
