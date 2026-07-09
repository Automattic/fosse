# FOSSE Publishing FAQ

## Do I need a Mastodon account?

No. FOSSE makes your WordPress site itself available as a fediverse profile at your site's domain.

For example, a site-wide profile might look like `@site@devdotdev.dev`. In author mode, each author can have their own address, such as `@alice@devdotdev.dev`.

## Can I connect an existing Mastodon account?

Not in the same way Bluesky connects through OAuth. ActivityPub does not work as an external account connection in FOSSE; WordPress becomes the publishing identity.

If you already have a Mastodon account, you can keep using it separately, link to your new WordPress fediverse address from that profile, or use account alias and migration tools where your old server supports them.

## How do I post to the fediverse?

Publish a new public WordPress post, page, or other content type selected in **FOSSE -> Settings**. FOSSE shares eligible new content automatically. There is no separate fediverse publish button.

People receive posts by following the fediverse address shown in FOSSE Settings or Status. Delivery can take a minute because WordPress processes federation in background jobs.

## Why did old posts not publish?

FOSSE does not backfill existing content when you finish setup. New posts you publish from the selected content types are shared automatically. Editing or republishing an older eligible post can also share it for the first time.

## How do I edit the site fediverse profile?

Go to **FOSSE -> Settings**, then open **Blog profile settings** from the ActivityPub profile section.

The site-wide profile uses:

-   The WordPress site title as its display name.
-   The WordPress Site Icon as its avatar.
-   The WordPress tagline as its description unless you set a custom ActivityPub description.

Blog profile settings also lets you edit the profile handle, description, header image, extra fields, account aliases, and follower/following visibility.

## What is the difference between author profiles and the blog profile?

**Author profiles** let each WordPress author publish from their own fediverse address. This works well for personal sites or multi-author sites where readers follow specific writers.

**Blog profile** creates one site-wide fediverse identity. This works well for publications, project blogs, and sites where readers should follow the site rather than individual authors.

**Both** keeps author profiles and also provides a site-wide blog profile.

Changing this later does not move followers between profiles. New posts use the profile choice selected in FOSSE Settings.

## How is Bluesky different?

Bluesky is an account connection. FOSSE asks you to connect an existing Bluesky account and then shares eligible WordPress posts there too.

Fediverse publishing is different: your WordPress site becomes the account people follow.
