<?php
/**
 * Marker exception for the redirect trap used by Menu/Onboarding tests.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

/**
 * Marker exception thrown by the redirect trap so tests can catch only
 * the redirect path and let any other exception bubble up as a failure.
 */
class RedirectFired extends \Exception {}
