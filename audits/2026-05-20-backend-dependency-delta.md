# Backend dependency delta — bundled vs released

Date: 2026-05-20
Status: Reference snapshot for `sdd/bundled-backends-migration/`

## Question

If FOSSE switched from its currently-bundled snapshots of `wordpress-activitypub`
and `wordpress-atmosphere` to the latest WordPress.org-released versions today,
what would we lose? Can the dependency-header cutover (`Requires Plugins:
activitypub, atmosphere`) ship now, or do we need to wait for upstream releases?

Short answer: **wait**. Both bundles are ahead of their last WordPress.org
release by changes FOSSE actively depends on.

## What FOSSE ships on `trunk`

| Plugin       | Bundled commit | Version header | Source                                                                          |
| ------------ | -------------- | -------------- | ------------------------------------------------------------------------------- |
| ActivityPub  | `31cdcd0a`     | `8.3.0`        | upstream `Automattic/wordpress-activitypub` trunk, four commits past tag `8.3.0` |
| Atmosphere   | `2a0383a`      | `1.0.0`        | upstream `Automattic/wordpress-atmosphere` trunk, three commits past tag `1.0.0` |

(The version header on each bundle is the upstream constant, which is bumped
only on tag cuts — so the bundled copies report the most recent tag even
though their code is past it.)

## What WordPress.org currently distributes

| Plugin       | Stable tag | Notes                                |
| ------------ | ---------- | ------------------------------------ |
| ActivityPub  | `8.3.0`    | <https://wordpress.org/plugins/activitypub/> |
| Atmosphere   | `1.0.0`    | <https://wordpress.org/plugins/atmosphere/>  |

## Delta — what we'd lose by switching to the released versions

### ActivityPub: lose the `toot:blurhash` JSON-LD context term

Upstream PR <https://github.com/Automattic/wordpress-activitypub/pull/3327>
adds `'blurhash' => 'toot:blurhash'` to the outbound JSON-LD `@context` on
`Activitypub\Activity\Activity` and `Activitypub\Activity\Base_Object`. It
landed on trunk after the 8.3.0 cut.

FOSSE's `Automattic\Fosse\Blurhash` (`src/class-blurhash.php`) injects a
`blurhash` property onto each attachment via the `activitypub_attachment`
filter. Without the matching `@context` term, the field validates as
undefined in strict consumers — Mastodon parses its own attachments using
exactly this term, so the field silently falls out on the receiving side.

Other commits on AP trunk past 8.3.0 are also picked up by the current
bundle but are not load-bearing for FOSSE:

- `3d01f3ff` — SWICG ActivityPub API Basic Profile conformance for C2S (#3328)
- `0de6d768` — surface `ap_tombstone` in the dev debug menu (#3318)
- four dependency bumps

### Atmosphere: lose the `atmosphere_post_embed` filter and its helpers

Upstream PR <https://github.com/Automattic/wordpress-atmosphere/pull/72>
adds the `atmosphere_post_embed` filter to `Atmosphere\Transformer\Post`
and renames `Post::upload_thumbnail()` to `Post::upload_image_blob()` with
the old name kept as a back-compat alias. It also exposes
`Post::get_attachment_aspect_ratio()`. The PR landed on trunk after the
1.0.0 cut.

FOSSE's `Automattic\Fosse\Photo_Post_Atmosphere`
(`src/class-photo-post-atmosphere.php:139`) registers
`add_filter( 'atmosphere_post_embed', [...] )` to project a WordPress
photo-shaped post onto Bluesky as `app.bsky.embed.images`. Without the
filter, Atmosphere ships the default `app.bsky.embed.external` link card
for every photo post — the entire photo-post AT projection feature
becomes a no-op.

Other Atmosphere commits past 1.0.0 that the current bundle picks up:

- `26010ec` — publication link tag for standard.site discovery (#75)
- `2a0383a` — re-sync publication on theme / site-URL changes (#76)

Neither is currently consumed from FOSSE's source, but operators rely on
the discovery link tag for federation hygiene.

## Implications

1. **The `Requires Plugins: activitypub, atmosphere` cutover is blocked
   on upstream releases.** Adding the header today would make the public
   plugin require AP 8.3.0 / Atmosphere 1.0.0 with no way to express
   "and you need PR 3327 on AP, PR 72 on Atmosphere". Users would install
   the released plugins, the dependency check would pass, and federation
   would silently regress in two visible ways (Mastodon attachments lose
   blurhash; Bluesky photo posts become link cards).

2. **The readiness layer should ship first.** Per
   `sdd/bundled-backends-migration/plan.md` task 1, FOSSE needs runtime
   readiness checks that report whether each backend is installed, active,
   recent enough, and exposing the surface FOSSE consumes. This PR
   introduces the foundation (constants + a probe class) so the
   "is this build good enough?" question lives in one place, ready to be
   wired into setup/status UX in a follow-up.

3. **Minimum-version anchors are placeholders.** Until upstream cuts
   releases that include the two PRs above, the constants in
   `Backend_Readiness` are forward-pointers — they reject the current
   released versions on purpose. They must be bumped to the real release
   numbers as soon as each upstream tags.

   - ActivityPub: the first release containing
     <https://github.com/Automattic/wordpress-activitypub/pull/3327>
     (placeholder: `8.4.0`).
   - Atmosphere: the first release containing
     <https://github.com/Automattic/wordpress-atmosphere/pull/72>
     (placeholder: `1.1.0`).

4. **No effect on today's installs.** Bundled copies stay loaded, the
   activation flow is unchanged, and the readiness checks treat a
   bundled load as authoritative — they only enforce the version floor
   when a *standalone* copy of either plugin is detected.
