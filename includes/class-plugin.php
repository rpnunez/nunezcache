<?php
/**
 * Plugin composition root.
 *
 * @package NunezCache
 * Purpose: Create cache directories on activation, boot capture and admin, and stop serving on deactivation.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Wires the concrete runtime classes. Activation creates directories and does not enable caching.
 */
class NunezCache_Plugin {

	/**
	 * Option name for the admin copy of state. Autoload is false.
	 */
	public const OPTION = 'nunezcache_settings';

	/**
	 * Cache root under wp-content. Page files are not stored in the plugin directory.
	 *
	 * @return string Absolute path without a trailing slash.
	 */
	public static function cache_root(): string {
		return WP_CONTENT_DIR . '/cache/nunezcache';
	}

	/**
	 * Create the cache directories and a disabled state file when one does not exist yet.
	 *
	 * Does not write the drop-in and does not turn caching on.
	 */
	public static function activate(): void {
		self::prepare_directories();
		$root = self::cache_root();
		$path = $root . '/state.php';
		$file = new NunezCache_State_File($path);
		if (!is_file($path)) {
			$defaults                   = NunezCache_State_File::defaults();
			$defaults['bypass_token']   = bin2hex(random_bytes(32));
			$defaults['enabled']        = false;
			$defaults['paused']         = false;
			$file->write($defaults);
			if (null === get_option(self::OPTION, null)) {
				add_option(self::OPTION, $defaults, '', false);
			}
			return;
		}
		if (null === get_option(self::OPTION, null)) {
			add_option(self::OPTION, $file->read(), '', false);
		}
	}

	/**
	 * Stop serving by neutering our drop-in. Stored HTML stays on disk.
	 */
	public static function deactivate(): void {
		self::installer()->neuter();
		$file  = new NunezCache_State_File(self::cache_root() . '/state.php');
		$state = $file->read();
		$state['enabled'] = false;
		$state['paused']  = false;
		$file->write($state);
		if (null === get_option(self::OPTION, null)) {
			add_option(self::OPTION, $state, '', false);
		} else {
			update_option(self::OPTION, $state, false);
		}
	}

	/**
	 * Register capture and the status screen.
	 */
	public function boot(): void {
		$root      = self::cache_root();
		$state     = new NunezCache_State_File($root . '/state.php');
		$store     = new NunezCache_File_Store($root);
		$log       = new NunezCache_Request_Log($root . '/log/requests.jsonl');
		$capture   = new NunezCache_Response_Capture($state, $store, $log);
		$capture->hooks();
		$admin = new NunezCache_Admin($state, $store, $log, self::installer(), new NunezCache_Status_Screen());
		$admin->hooks();
	}

	/**
	 * Create pages, index, and log directories plus the access denials.
	 */
	private static function prepare_directories(): void {
		$root = self::cache_root();
		wp_mkdir_p($root . '/pages/1');
		wp_mkdir_p($root . '/index/1');
		wp_mkdir_p($root . '/log');
		$silence = $root . '/index.php';
		if (!is_file($silence)) {
			file_put_contents($silence, "<?php\n// Silence is golden.\n");
		}
		$htaccess = $root . '/.htaccess';
		if (!is_file($htaccess)) {
			file_put_contents(
				$htaccess,
				"<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n"
			);
		}
	}

	/**
	 * Drop-in installer pointed at this plugin and wp-content.
	 *
	 * @return NunezCache_Dropin_Installer
	 */
	private static function installer(): NunezCache_Dropin_Installer {
		return new NunezCache_Dropin_Installer(NUNEZCACHE_DIR, WP_CONTENT_DIR . '/advanced-cache.php');
	}
}
