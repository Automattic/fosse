<?php
/**
 * Connection provider interface.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Admin;

/**
 * Contract for federation protocol providers.
 *
 * Each provider represents one federated protocol (ActivityPub, Bluesky, etc.)
 * and is responsible for rendering its own settings fields, connection actions,
 * and status card. Providers self-register via the `fosse_register_providers`
 * action.
 *
 * ### Implementing a standalone provider
 *
 * Standalone provider plugins implement this interface and push an instance
 * onto the registry from a `fosse_register_providers` callback:
 *
 * ```php
 * add_action( 'fosse_register_providers', static function () {
 *     \Automattic\Fosse\Admin\Connection_Provider_Registry::register(
 *         new My_Plugin\My_Provider()
 *     );
 * } );
 * ```
 *
 * `fosse_register_providers` fires from
 * {@see \Automattic\Fosse\Provider_Loader::boot()} on `plugins_loaded`
 * priority 10. The add-on's callback must be attached no later than
 * `plugins_loaded` priority 9 — registering it from the plugin main file
 * is the simplest path.
 *
 * Return `false` from {@see self::is_available()} when the provider's
 * underlying SDK isn't loaded; FOSSE will skip {@see self::register_hooks()}
 * cleanly instead of erroring out.
 */
interface Connection_Provider {

	/**
	 * Unique slug identifying this provider (e.g. 'activitypub', 'bluesky').
	 *
	 * @return string
	 */
	public function get_slug(): string;

	/**
	 * Human-readable display name (e.g. 'ActivityPub', 'Bluesky').
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Whether the underlying plugin is present and usable.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Current connection status data.
	 *
	 * Returns an associative array with at least a 'connected' boolean.
	 * Providers may include additional keys relevant to their protocol.
	 *
	 * @return array<string, mixed>
	 */
	public function get_status(): array;

	/**
	 * Render this provider's settings fields inside the unified Settings form.
	 *
	 * Implementations render protocol-specific form rows only — no opening
	 * `<form>` tag, no submit button. The Settings page wraps every
	 * provider's fields in a single form posting to `fosse_save_settings`.
	 *
	 * @return void
	 */
	public function render_setup_section(): void;

	/**
	 * Render this provider's connection actions outside the Settings form.
	 *
	 * Connect/disconnect flows post to their own admin-post endpoints with
	 * their own nonces, so they cannot share the unified Settings form.
	 * Providers without connection actions (e.g. ActivityPub, which is
	 * always "connected" when the plugin is loaded) render nothing.
	 *
	 * @return void
	 */
	public function render_connection_actions(): void;

	/**
	 * Render the status card for this provider on the FOSSE Status page.
	 *
	 * @return void
	 */
	public function render_status_card(): void;

	/**
	 * Persist this provider's settings from a unified save submission.
	 *
	 * Called by the Settings page's unified save handler after capability
	 * and nonce checks have passed. Implementations validate and update
	 * their own options. Returning `false` signals a hard rejection
	 * (e.g. an input failed sanitization) so the caller can suppress the
	 * blanket "settings saved" success notice; the implementation is
	 * responsible for adding any explanatory `add_settings_error` entries.
	 *
	 * @param array<string, mixed> $post_data Raw POST payload — still slashed.
	 *                                        Implementations are responsible for
	 *                                        `wp_unslash` and per-field sanitization.
	 * @return bool
	 */
	public function save_settings( array $post_data ): bool;

	/**
	 * Register any hooks this provider needs (admin_post handlers, filters, etc.).
	 *
	 * Called by Provider_Loader::boot() after the provider is registered and
	 * available. Settings save is centralized in Setup_Page; providers only
	 * register their own protocol-specific hooks here (OAuth callbacks,
	 * connect/disconnect handlers, projection filters).
	 *
	 * @return void
	 */
	public function register_hooks(): void;
}
