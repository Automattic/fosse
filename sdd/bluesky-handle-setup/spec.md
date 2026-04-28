# Spec: Bluesky Handle Setup

## Goal

Let users claim their domain as their Bluesky handle from inside FOSSE. Today the user has to leave WordPress to set up DNS or `/.well-known/atproto-did`, then go to bsky.app to apply the handle change. FOSSE can serve the well-known route automatically (since it controls the WordPress install at that domain) and show the user the verification status and next steps without leaving the admin.

## Requirements Summary

- Auto-serve `/.well-known/atproto-did` when Bluesky is connected.
- Show DNS TXT fallback for hosts where the well-known route can't be served.
- Surface verification status (does the domain currently resolve to the connected DID?).
- Hand the user off to bsky.app with their domain pre-prepared once verification passes.
- Live inside the existing `Bluesky_Provider`, not a new top-level admin surface.

## Chosen Approach

**Bluesky_Provider extension with a lightweight well-known handler.**

### Verification path

Two methods are valid for ATProto handle ownership:

1. **`/.well-known/atproto-did`** — plain-text HTTPS response containing only the DID. FOSSE controls the WordPress install at the domain, so we serve this automatically.
2. **DNS TXT record at `_atproto.<domain>`** — content `did=<did>`. Requires the user to configure DNS at their registrar. Used as a fallback for managed hosts that intercept `.well-known`.

We default to method 1 and surface method 2 only when the user clicks "Show DNS fallback."

### Verification check

Use Bluesky's public `com.atproto.identity.resolveHandle` API to ask, "what DID does Bluesky see for this handle?" If it matches the stored DID, verified. If not, surface the mismatch.

This avoids relying on PHP DNS extensions (not always available on managed hosts) and gives the user an authoritative answer from Bluesky itself.

### Alternatives Considered

- **A — Local DNS lookup via PHP `dns_get_record`.** Cheaper (no outbound HTTP) but requires PHP DNS extensions which aren't guaranteed on shared hosts. Also doesn't catch the case where DNS resolves but Bluesky hasn't yet picked it up.
- **B — Skip verification, just trust the user.** Simplest, but loses the "did it actually work?" signal that's the whole point of this feature.
- **C — Custom UI on a dedicated FOSSE > Domain Handle page.** Cleaner page hierarchy but adds menu surface for what's really just an extension of the Bluesky connection. Rejected for scope.

## Technical Details

### Well-known route

Hook on `init` priority 1. If `$_SERVER['REQUEST_URI']` matches `/.well-known/atproto-did` (with any query string stripped), check that Bluesky is connected, set `Content-Type: text/plain`, print the DID, exit. No trailing newline. No HTML.

Filter `fosse_serve_atproto_did_well_known` (default `true`) lets users disable the route if their host serves it differently.

The bundled Atmosphere plugin registers its own `template_redirect` handler for the same path. When the FOSSE filter returns false, FOSSE also clears Atmosphere's `atmosphere_wellknown` query var via a paired `template_redirect` priority 1 hook so neither plugin serves the route (otherwise Atmosphere would silently take over and the opt-out wouldn't actually opt out).

### Verification check

`Bluesky_Provider::check_handle_verification($domain): array` calls `https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=<domain>` with `wp_remote_get`. Returns `['status' => 'verified'|'mismatch'|'not_found'|'error', 'resolved_did' => string|null, 'error' => string|null]`. The `not_found` status corresponds to the resolver's `HandleNotFound` error (the common "domain not configured yet" case while the user is still setting up DNS or the well-known route).

Cache results in a transient for 5 minutes keyed by hash of `domain + expected DID`, so reconnecting a different Bluesky account within the cache window doesn't reuse a stale `verified` result for the new DID.

### UI states (inside Bluesky_Provider's setup section)

| Bluesky state | Domain handle subsection shows |
|---|---|
| Not connected | Nothing (subsection hidden). |
| Connected, using default `*.bsky.social` handle | CTA: "Use your domain as your Bluesky handle" with current site URL pre-populated and a "Set up" button. |
| Setup started, not yet verified | Verification panel with two methods (well-known active by default; DNS TXT shown on click). "Check verification" button calls the resolver. Status: "Pending — Bluesky doesn't see your domain yet." |
| Verified, handle not yet changed on Bluesky | "Verified! Now finish the change on bsky.app" with deep link to Bluesky's handle settings. |
| Domain handle active on Bluesky | "Your Bluesky handle is `@yourdomain.com`. Keep this site up so the handle keeps resolving." |

### Data

No new options required. We read `did` from the existing `atmosphere_connection` option that `Bluesky_Provider` already uses. The stored `handle` in that option is captured at OAuth time and goes stale once the user changes their handle on bsky.app, so it cannot be used to drive the "active" state.

A single new option `fosse_bluesky_handle_setup_started` (boolean) tracks whether the user has clicked "Set up domain handle" so the CTA disappears once they're in the flow.

### Distinguishing "Verified" from "Active"

After verification passes, we need to know whether the user has actually completed the handle change on bsky.app. We query `app.bsky.actor.getProfile?actor=<did>` (cached in the same 5-minute transient as the resolver lookup) to get the actor's *current* handle. If `current_handle === <domain>`, we're in the active state. Otherwise, we stay in the "Verified! Now finish the change on bsky.app" state. This avoids reading the stale `atmosphere_connection['handle']`.

### bsky.app handoff

Bluesky's settings deep link: `https://bsky.app/settings/account/handle`. We can't pre-fill the field via URL params (no public API for that), but we can show the user the exact handle to paste, with a copy button.
