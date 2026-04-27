# Implementation Notes — Bluesky Handle Setup

## Design Decisions

### Verification via Bluesky's resolveHandle API, not local DNS
- **Decision**: `check_handle_verification()` calls `https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=<domain>` rather than doing a local `dns_get_record` lookup.
- **Reason**: The user's actual question is "does Bluesky see my domain as my handle?" — only Bluesky's resolver can answer that authoritatively. Local DNS could resolve correctly while Bluesky's cache is still warm with the old answer, producing a green status that turns out wrong. Plus, PHP DNS extensions aren't guaranteed on managed hosts.

### Well-known route is default-on with an opt-out filter
- **Decision**: When Bluesky is connected, FOSSE serves `/.well-known/atproto-did` automatically. A `fosse_serve_atproto_did_well_known` filter (default `true`) lets users disable it.
- **Reason**: The whole point of this feature is to make the verification step disappear for the common case. Most WordPress sites don't have anything else competing for `.well-known/atproto-did`. The filter exists for the edge cases (managed hosts that intercept `.well-known`, sites running multiple ATProto identities, etc.) so users have an out without us needing a settings toggle.

### Domain handle UI lives inside Bluesky_Provider, not a new admin page
- **Decision**: The setup, verification, and status UI all live as a subsection inside `Bluesky_Provider::render_setup_section()`.
- **Reason**: This is an extension of "your Bluesky connection," not a separate concept. Adding a top-level FOSSE > Domain Handle page would split related state across two admin surfaces and make the connection ↔ handle relationship less obvious. The subsection only renders when Bluesky is connected, so it follows the user's mental model (Bluesky first, then domain handle).

### Single boolean tracks setup intent
- **Decision**: `fosse_bluesky_handle_setup_started` (autoload-false option) flips to `1` when the user clicks "Set up domain handle" and never flips back.
- **Reason**: The verification result itself is the source of truth for "is this working?" — we don't want a separate "is verified" boolean that can drift. The boolean is just for UI state ("show the CTA vs. show the verification panel"). Once the user starts, the panel stays even if verification fails, so they have somewhere to retry.

### Verification cached for 5 minutes
- **Decision**: Results from `check_handle_verification()` are cached in a transient keyed by hash of `domain + expected DID` for 5 minutes. The "Check verification" button busts the transient. The DID is part of the key so reconnecting a different Bluesky account within the cache window doesn't reuse a stale `verified` result.
- **Reason**: Page loads in the WordPress admin can hit this multiple times (status card, setup section). 5 minutes is short enough that user intent ("I just changed my DNS, check again") still works via the explicit button, but long enough that incidental page loads don't hammer Bluesky.

## Known Limitations (Expected)

### No automatic DNS configuration
FOSSE displays the TXT record content with a copy button but does not attempt to configure DNS at the user's registrar. Most users will need to log into their DNS provider (Cloudflare, Namecheap, etc.) and add the record manually. Future work could integrate with WordPress.com's DNS API for wpcom-hosted sites, but is out of scope for the radical-month MVP.

### No automatic handle change on Bluesky
Bluesky doesn't expose a public API for third-party handle changes (security boundary — the user must consent on their own session). FOSSE displays the deep link to `https://bsky.app/settings/account/handle` and shows the exact handle to paste, but the user has to complete the change in Bluesky's app.

### Verification status is point-in-time
The verification check runs when the page loads (or when the user clicks "Check verification"). It does not poll continuously. If Bluesky's resolver state changes after page load, the user sees stale status until the next refresh.

### Domain change requires re-running verification
If the user's WordPress moves to a new domain (e.g., custom domain change, host migration), the well-known route serves the same DID but now from a different host. Bluesky's verification will fail for the new domain until the user re-runs setup. This is expected ATProto behavior, not a FOSSE bug, but worth noting in user-facing copy.
