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
 * and is responsible for rendering its own setup section and status card.
 * Providers self-register via the 'fosse_register_providers' action.
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
	 * Render the setup section for this provider on the FOSSE Setup page.
	 *
	 * @return void
	 */
	public function render_setup_section(): void;

	/**
	 * Render the status card for this provider on the FOSSE Status page.
	 *
	 * @return void
	 */
	public function render_status_card(): void;

	/**
	 * Register any hooks this provider needs (admin_post handlers, filters, etc.).
	 *
	 * Called by Menu::register() after the provider is registered and available.
	 *
	 * @return void
	 */
	public function register_hooks(): void;
}
