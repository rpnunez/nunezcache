<?php
/**
 * Uninstall.
 *
 * @package NunezCache
 * Purpose: Delete the cache directory, the settings option, and our drop-in. Does not run on deactivation.
 * License: GPL-2.0-or-later
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

require_once __DIR__ . '/includes/dropin/class-dropin-installer.php';

/**
 * Removes NunezCache files when the plugin is deleted.
 */
class NunezCache_Uninstall {

	/**
	 * Delete the cache root, the settings option, and a NunezCache drop-in.
	 */
	public static function run(): void {
		$root = WP_CONTENT_DIR . '/cache/nunezcache';
		if (is_dir($root)) {
			self::delete_tree($root);
		}
		delete_option('nunezcache_settings');
		$installer = new NunezCache_Dropin_Installer(__DIR__, WP_CONTENT_DIR . '/advanced-cache.php');
		$installer->remove();
	}

	/**
	 * Delete a directory tree.
	 *
	 * @param string $dir Absolute directory.
	 */
	private static function delete_tree(string $dir): void {
		$items = scandir($dir);
		if (false === $items) {
			return;
		}
		foreach ($items as $item) {
			if ('.' === $item || '..' === $item) {
				continue;
			}
			$path = $dir . '/' . $item;
			if (is_dir($path) && !is_link($path)) {
				self::delete_tree($path);
				continue;
			}
			unlink($path);
		}
		rmdir($dir);
	}
}

NunezCache_Uninstall::run();
