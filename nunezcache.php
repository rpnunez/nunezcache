<?php
/**
 * Plugin Name: NunezCache
 * Description: Anonymous HTML page cache stored as files. A second view of a public page is served before WordPress boots.
 * Version: 0.1.0
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author: Raymond Nunez
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nunezcache
 *
 * @package NunezCache
 * Purpose: WordPress entry. Defines constants, registers activation hooks, and boots the plugin.
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit;
}

define('NUNEZCACHE_VERSION', '0.1.0');
define('NUNEZCACHE_FILE', __FILE__);
define('NUNEZCACHE_DIR', __DIR__);

require_once NUNEZCACHE_DIR . '/includes/runtime/class-early-cache.php';
require_once NUNEZCACHE_DIR . '/includes/dropin/class-dropin-installer.php';
require_once NUNEZCACHE_DIR . '/includes/capture/class-response-capture.php';
require_once NUNEZCACHE_DIR . '/includes/admin/class-status-screen.php';
require_once NUNEZCACHE_DIR . '/includes/admin/class-admin.php';
require_once NUNEZCACHE_DIR . '/includes/class-plugin.php';

register_activation_hook(NUNEZCACHE_FILE, array( 'NunezCache_Plugin', 'activate' ));
register_deactivation_hook(NUNEZCACHE_FILE, array( 'NunezCache_Plugin', 'deactivate' ));

/**
 * Mark the current request as uncacheable.
 *
 * Capture reads the flag and does not store the body. A HIT has already exited
 * and cannot be changed by this function.
 *
 * @param string $reason Short reason code. Do not pass secrets.
 */
function nunezcache_do_not_cache( $reason = 'do_not_cache' ): void {
	if (!is_string($reason) || '' === $reason) {
		$reason = 'do_not_cache';
	}
	$GLOBALS['nunezcache_do_not_cache'] = $reason;
}

$nunezcache_plugin = new NunezCache_Plugin();
$nunezcache_plugin->boot();
