# Spec: Bluesky Handle Setup

## Goal

Let users claim their domain as their Bluesky handle from inside FOSSE. Today the user has to leave WordPress to set up DNS or `/.well-known/atproto-did`, then go to bsky.app to apply the handle change. FOSSE can serve the well-known route automatically (since it controls the WordPress install at that domain) and show the user the verification status and next steps without leaving the admin.

## Requirements Summary

- Auto-serve `/.well-known/atproto-did` when Bluesky is connected.
- Show DNS TXT fallback for hosts where the well-known route can't be served.
- Surface verification status (does the domain currently resolve to the connected DID?).
- Hand the user off to bsky.app with their domain pre-prepared once verification passes.
- Live inside the existing `Bluesky_Provider`, not a new top-level admin surface.
- Offer the flow only when the WordPress site lives at the host root; path-bound installs get an ineligible state.

## Chosen Approach

**Bluesky_Provider extension with a lightweight well-known handler.**

The bundled Atmosphere plugin already serves `/.well-known/atproto-did`, but FOSSE re-serves it deliberately. FOSSE owns the unified setup UX and the `fosse_serve_atproto_did_well_known` opt-out contract; bundled-plugin policy also keeps FOSSE-specific behavior out of `bundled/`. Upstreaming a general Atmosphere opt-out hook was considered, but the immediate need is FOSSE-shaped: disable both FOSSE's route and the bundled fallback from FOSSE's setup flow. If Atmosphere later grows a generic opt-out filter, FOSSE can consume it and drop the local suppression shim.

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

Hook on `init` priority 1. Parse `$_SERVER['REQUEST_URI']` with `wp_parse_url( ..., PHP_URL_PATH )` and require strict equality with `/.well-known/atproto-did`. Query strings are ignored by parsing the path; other paths return early. If Bluesky is connected, set `Content-Type: text/plain`, print the DID, exit. No trailing newline. No HTML.

Filter `fosse_serve_atproto_did_well_known` (default `true`) lets users disable the route if their host serves it differently.

The bundled Atmosphere plugin registers its own `template_redirect` handler for the same path. When the FOSSE filter returns false, FOSSE also clears Atmosphere's `atmosphere_wellknown` query var via a paired `template_redirect` priority 1 hook so neither plugin serves the route (otherwise Atmosphere would silently take over and the opt-out wouldn't actually opt out).

### Verification check

`Bluesky_Provider::check_handle_verification($domain): array` calls `https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=<domain>` with `wp_remote_get`. Returns `['status' => 'verified'|'mismatch'|'not_found'|'error', 'resolved_did' => string|null, 'error' => string|null]`. The `not_found` status corresponds to the resolver's `HandleNotFound` error (the common "domain not configured yet" case while the user is still setting up DNS or the well-known route).

Cache results in a transient for 5 minutes keyed by `md5( $domain . '|' . $expected_did )`, so reconnecting a different Bluesky account within the cache window doesn't reuse a stale `verified` result for the new DID. The delimiter is part of the contract; concatenating the two strings directly can produce ambiguous cache inputs.

### Domain eligibility and normalization

The suggested handle comes from `home_url()`, but ATProto handles are DNS names, not URLs. FOSSE must use only the host portion and must not offer the flow when the WordPress home URL is path-bound.

Eligibility check:

```php
'' === trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' )
```

This allows single-site root installs, subdomain-multisite subsites, and subdirectory-multisite main sites when they live at host-root. It excludes subdirectory installs and subdirectory-multisite subsites because handles cannot include `/blog` or any other path segment.

Domain normalization before resolver calls:

- Lowercase the host.
- Strip a trailing dot.
- Reject hosts with an explicit port.
- Convert IDNs to ASCII with `idn_to_ascii()` when available; if conversion is needed but unavailable or fails, surface an error state instead of sending a Unicode label to the resolver.

### UI states (inside Bluesky_Provider's setup section)

| Bluesky state | Domain handle subsection shows |
|---|---|
| Not connected | Nothing (subsection hidden). |
| Connected, but site URL is path-bound | Ineligible state explaining that Bluesky handles can only use the bare domain, not a URL path. Do not show setup buttons. |
| Connected, using default `*.bsky.social` handle | CTA: "Use your domain as your Bluesky handle" with current site URL pre-populated and a "Set up" button. |
| Setup started, resolver returns `not_found` | Verification panel with two methods (well-known active by default; DNS TXT shown on click). "Check verification" button calls the resolver. Status: "Pending — Bluesky doesn't see your domain yet." |
| Setup started, resolver returns `mismatch` | Warning state showing the connected DID and the DID Bluesky currently resolves for the domain. Keep the verification methods visible so the user can correct DNS or reconnect the right account. |
| Setup started, resolver returns `error` | Error state with the resolver/API error message and a retry button. Keep DNS fallback visible; do not claim verification state. |
| Verified, handle not yet changed on Bluesky | "Verified! Now finish the change on bsky.app" with deep link to Bluesky's handle settings. |
| Domain handle active on Bluesky | "Your Bluesky handle is `@yourdomain.com`. Keep this site up so the handle keeps resolving." |

### Data

No new persistent identity options are required. We read `did` from the existing `atmosphere_connection` option that `Bluesky_Provider` already uses. The stored `handle` in that option is captured at OAuth time and goes stale once the user changes their handle on bsky.app, so it cannot be used to drive the "active" state.

A single new option `fosse_bluesky_handle_setup_started` (boolean, autoload disabled) tracks whether the user has clicked "Set up domain handle" so the CTA disappears once they're in the flow.

### Distinguishing "Verified" from "Active"

After verification passes, we need to know whether the user has actually completed the handle change on bsky.app. We query `app.bsky.actor.getProfile?actor=<did>` (cached for 5 minutes in `fosse_handle_current_<md5(did)>`) to get the actor's *current* handle. If `current_handle === <domain>`, we're in the active state. Otherwise, we stay in the "Verified! Now finish the change on bsky.app" state. This avoids reading the stale `atmosphere_connection['handle']`.

### bsky.app handoff

Bluesky's settings deep link: `https://bsky.app/settings/account/handle`. We can't pre-fill the field via URL params (no public API for that), but we can show the user the exact handle to paste, with a copy button.

This is a single-site-owner flow. It does not try to assign per-user domain handles or coordinate multiple Bluesky accounts on one WordPress install.
