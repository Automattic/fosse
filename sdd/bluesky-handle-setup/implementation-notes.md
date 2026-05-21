# Implementation Notes — Bluesky Handle Setup

## Status Snapshot

Task 1 is implemented on this branch: `/.well-known/atproto-did` is served from `Bluesky_Provider` only when Atmosphere reports a connected account, the FOSSE opt-out filter is honored, and the bundled Atmosphere fallback is suppressed when FOSSE opts out. The resolver, UI, DNS fallback, admin-post handlers, and broader feature tests remain planned work.

## Design Decisions

### Verification via Bluesky's resolveHandle API, not local DNS
- **Decision**: `check_handle_verification()` calls `https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=<domain>` rather than doing a local `dns_get_record` lookup.
- **Reason**: The user's actual question is "does Bluesky see my domain as my handle?" — only Bluesky's resolver can answer that authoritatively. Local DNS could resolve correctly while Bluesky's cache is still warm with the old answer, producing a green status that turns out wrong. Plus, PHP DNS extensions aren't guaranteed on managed hosts.

### Well-known route is default-on with an opt-out filter
- **Decision**: When Bluesky is connected, FOSSE serves `/.well-known/atproto-did` automatically. A `fosse_serve_atproto_did_well_known` filter (default `true`) lets users disable it.
- **Reason**: The whole point of this feature is to make the verification step disappear for the common case. Most WordPress sites don't have anything else competing for `.well-known/atproto-did`. The filter exists for the edge cases (managed hosts that intercept `.well-known`, sites running multiple ATProto identities, etc.) so users have an out without us needing a settings toggle.

### FOSSE re-serves a route Atmosphere also serves (superseded — see deviations below)
- **Decision**: FOSSE serves the well-known response itself and suppresses Atmosphere's handler when `fosse_serve_atproto_did_well_known` returns false.
- **Reason**: FOSSE owns the unified setup experience and its opt-out filter must mean "FOSSE will not serve this route, and the bundled fallback will not silently serve it either." This behavior is FOSSE-shaped, so it stays out of `bundled/`. A generic upstream Atmosphere opt-out hook can replace the suppression shim later if it becomes useful outside FOSSE.

### Domain handle UI lives inside Bluesky_Provider, not a new admin page
- **Decision**: The setup, verification, and status UI all live as a subsection inside `Bluesky_Provider::render_setup_section()`.
- **Reason**: This is an extension of "your Bluesky connection," not a separate concept. Adding a top-level FOSSE > Domain Handle page would split related state across two admin surfaces and make the connection ↔ handle relationship less obvious. The subsection only renders when Bluesky is connected, so it follows the user's mental model (Bluesky first, then domain handle).

### Single boolean tracks setup intent
- **Decision**: `fosse_bluesky_handle_setup_started` (autoload-false option) flips to `1` when the user clicks "Set up domain handle" and never flips back.
- **Reason**: The verification result itself is the source of truth for "is this working?" — we don't want a separate "is verified" boolean that can drift. The boolean is just for UI state ("show the CTA vs. show the verification panel"). Once the user starts, the panel stays even if verification fails, so they have somewhere to retry.

### Verification cached for 5 minutes
- **Decision**: Results from `check_handle_verification()` are cached in a transient keyed by `md5( $domain . '|' . $expected_did )` for 5 minutes. The "Check verification" button busts the transient. The DID is part of the key so reconnecting a different Bluesky account within the cache window doesn't reuse a stale `verified` result.
- **Reason**: Page loads in the WordPress admin can hit this multiple times (status card, setup section). 5 minutes is short enough that user intent ("I just changed my DNS, check again") still works via the explicit button, but long enough that incidental page loads don't hammer Bluesky.

### Path-bound installs are ineligible
- **Decision**: The UI checks `'' === trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' )` before offering domain-handle setup.
- **Reason**: ATProto handles are DNS names. A WordPress site at `example.com/blog` cannot use `example.com/blog` as a handle, and offering `example.com` would be misleading because FOSSE does not control that host root. Subdomain multisite remains eligible because each subsite still lives at a host root.

## Design deviations from spec

The implementation that actually shipped (Automattic/fosse#97 and follow-ups on this branch) deviates from the originally-scoped approach captured in the Design Decisions above. The decisions below override those where they conflict; cross-reference `sdd/bluesky-handle-setup/spec.md` and `sdd/bluesky-handle-setup/requirements.md` for the original framing.

### Direct `com.atproto.identity.updateHandle` instead of bsky.app handoff
- **Decision**: `Bluesky_Domain_Handle::set_handle()` calls `com.atproto.identity.updateHandle` directly through Atmosphere's DPoP-authenticated `\Atmosphere\API` client rather than deep-linking the user to `https://bsky.app/settings/account/handle` for a manual change.
- **Reason**: Atmosphere already holds a valid OAuth token with `identity:handle` scope. Doing the call ourselves keeps the entire flow inside one click ("Use example.com as my Bluesky handle") instead of bouncing the user out to Bluesky and asking them to paste a handle. The "no automatic handle change on Bluesky" limitation noted above is no longer accurate for this branch — it applied when the design assumed third-party clients couldn't drive the change.

### Prerequisite: PR 115 brought the `identity:handle` OAuth scope upstream
- **Decision**: This branch depends on `bundled/wordpress-atmosphere/` already declaring `identity:handle` in its requested OAuth scopes. PR 115 (bundled plugin sync) merged in upstream wordpress-atmosphere PR #53 which added the scope; without that prerequisite, the `updateHandle` call would 401 even though FOSSE's code path is correct.
- **Reason**: The OAuth client is bundled, not vendored. Scope changes flow through wordpress-atmosphere, then into FOSSE via the bundled-mirror sync. This dependency is invisible at the FOSSE call site, so it's worth documenting.

### Well-known route delegated to Atmosphere (issue #169)
- **Decision**: FOSSE no longer serves `/.well-known/atproto-did` itself. Atmosphere's `serve_wellknown_atproto_did()` owns the route end-to-end; FOSSE only retains a `template_redirect` priority-1 suppression hook that fires when the `fosse_serve_atproto_did_well_known` filter returns false (clearing Atmosphere's query var and forcing a 404 so the opt-out contract still means "neither plugin responds").
- **Reason**: The duplicated handler kept silently drifting from Atmosphere's identity contract (the original `is_connected()` gate broke domain-handle reconnect; PR 166 fixed it by mirroring `has_identity()`). Atmosphere's bundled handler already gates on `has_identity()`, validates the DID via `get_did()`, and emits the same `text/plain` body — there's no FOSSE-specific shape worth maintaining a parallel implementation for. See issue #169.

### Snapshot-and-revert on disconnect (`OPTION_PREVIOUS_HANDLE`)
- **Decision**: When `set_handle()` succeeds, FOSSE snapshots the previous handle to `OPTION_PREVIOUS_HANDLE`, keyed by the connected DID. On disconnect, `Bluesky_Domain_Handle::maybe_revert_on_disconnect()` runs BEFORE Atmosphere's OAuth revoke (so the access token is still valid) and restores the snapshotted handle when the snapshot's DID matches the currently-connected DID.
- **Reason**: Setting a handle via `updateHandle` is destructive — the prior handle stops resolving immediately. Without revert, disconnecting from Bluesky would leave the user's Bluesky identity stuck on the FOSSE-set domain handle even after they've severed the integration. DID-binding the snapshot prevents two failure modes: (1) silently rewriting a different account's handle if the user reconnects to a different Bluesky account before disconnecting, and (2) reusing a stale snapshot from a prior account.

## Known Limitations (Expected)

### No automatic DNS configuration
FOSSE displays the TXT record content with a copy button but does not attempt to configure DNS at the user's registrar. Most users will need to log into their DNS provider (Cloudflare, Namecheap, etc.) and add the record manually. Future work could integrate with WordPress.com's DNS API for wpcom-hosted sites, but is out of scope for the radical-month MVP.

### No automatic handle change on Bluesky
Bluesky doesn't expose a public API for third-party handle changes (security boundary — the user must consent on their own session). FOSSE displays the deep link to `https://bsky.app/settings/account/handle` and shows the exact handle to paste, but the user has to complete the change in Bluesky's app.

### Verification status is point-in-time
The verification check runs when the page loads (or when the user clicks "Check verification"). It does not poll continuously. If Bluesky's resolver state changes after page load, the user sees stale status until the next refresh.

### Domain change requires re-running verification
If the user's WordPress moves to a new domain (e.g., custom domain change, host migration), the well-known route serves the same DID but now from a different host. Bluesky's verification will fail for the new domain until the user re-runs setup. This is expected ATProto behavior, not a FOSSE bug, but worth noting in user-facing copy.
