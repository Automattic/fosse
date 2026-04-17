<?php
/**
 * PHPUnit bootstrap: loads WordPress via WorDBless (dbless engine) and the FOSSE plugin.
 *
 * @package Automattic\Fosse
 */

/**
 * Include the composer autoloader.
 */
require_once __DIR__ . '/../../vendor/autoload.php';

\WorDBless\Load::load();

require_once __DIR__ . '/../../fosse.php';
