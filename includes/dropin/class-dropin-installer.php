<?php
/**
 * Drop-in installer.
 *
 * @package NunezCache
 * Purpose: Write and neuter advanced-cache.php, and set WP_CACHE only after an explicit edit.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH') && 'cli' !== PHP_SAPI && 'phpdbg' !== PHP_SAPI && 'cli-server' !== PHP_SAPI) {
	exit;
}

/**
 * Copies the drop-in template and edits wp-config.php after consent.
 */
class NunezCache_Dropin_Installer {

	/**
	 * Marker present in both the live drop-in and the inactive placeholder.
	 */
	private const MARKER = 'NunezCache drop-in';

	/**
	 * Plugin directory, without a trailing slash.
	 */
	private string $plugin_dir;

	/**
	 * Absolute path of wp-content/advanced-cache.php.
	 */
	private string $dropin_path;

	/**
	 * Absolute wp-config.php path, or empty when it should be detected later.
	 */
	private string $wp_config_path;

	/**
	 * Bind paths. An empty config path is detected from ABSPATH when a method needs it.
	 *
	 * @param string $plugin_dir     Plugin root.
	 * @param string $dropin_path    Destination advanced-cache.php.
	 * @param string $wp_config_path Optional explicit wp-config.php path.
	 */
	public function __construct(string $plugin_dir, string $dropin_path, string $wp_config_path = '') {
		$this->plugin_dir     = rtrim($plugin_dir, '/');
		$this->dropin_path    = $dropin_path;
		$this->wp_config_path = $wp_config_path;
	}

	/**
	 * Write our drop-in. A foreign file is left untouched.
	 *
	 * @return bool False when the template is missing, the file is foreign, or the write fails.
	 */
	public function install(): bool {
		if ($this->is_foreign()) {
			return false;
		}
		$template = $this->plugin_dir . '/includes/dropin/template.php';
		$source   = file_get_contents($template);
		if (!is_string($source) || !str_contains($source, '__NUNEZCACHE_EARLY_FILE__')) {
			return false;
		}
		$early    = $this->plugin_dir . '/includes/runtime/class-early-cache.php';
		$rendered = str_replace('__NUNEZCACHE_EARLY_FILE__', var_export($early, true), $source);
		return $this->atomic_write($this->dropin_path, $rendered);
	}

	/**
	 * Replace our drop-in with a file that does nothing. HTML files are not touched.
	 *
	 * A foreign drop-in is left in place. A missing file is not created.
	 *
	 * @return bool False when the current file is not ours or the write fails.
	 */
	public function neuter(): bool {
		if (!$this->is_ours()) {
			return false;
		}
		return $this->atomic_write($this->dropin_path, $this->neutered_source());
	}

	/**
	 * Delete the drop-in when it belongs to NunezCache.
	 *
	 * @return bool False when the file is missing, foreign, or unlink fails.
	 */
	public function remove(): bool {
		if (!$this->is_ours() || !is_file($this->dropin_path)) {
			return false;
		}
		return unlink($this->dropin_path);
	}

	/**
	 * Whether the drop-in file carries our marker, including the inactive placeholder.
	 *
	 * @return bool True when the file exists and belongs to NunezCache.
	 */
	public function is_ours(): bool {
		$contents = $this->contents();
		return null !== $contents && str_contains($contents, self::MARKER);
	}

	/**
	 * Whether the drop-in calls the early cache.
	 *
	 * @return bool True when a live NunezCache drop-in is installed.
	 */
	public function is_active(): bool {
		$contents = $this->contents();
		return null !== $contents && str_contains($contents, 'NunezCache_Early_Cache::serve()');
	}

	/**
	 * Whether some other plugin owns advanced-cache.php.
	 *
	 * @return bool True when a non-empty file exists and it is not ours.
	 */
	public function is_foreign(): bool {
		$contents = $this->contents();
		if (null === $contents || '' === trim($contents)) {
			return false;
		}
		return !str_contains($contents, self::MARKER);
	}

	/**
	 * Whether wp-config.php can be edited.
	 *
	 * @return bool True when the resolved file exists and is writable.
	 */
	public function config_is_writable(): bool {
		$path = $this->resolved_config_path();
		return '' !== $path && is_file($path) && is_writable($path);
	}

	/**
	 * Set WP_CACHE to true. Does not enable the cache by itself.
	 *
	 * Does not throw. A file that is not writable is left unchanged.
	 *
	 * @return bool False when the file cannot be edited or the define cannot be placed.
	 */
	public function allow_wp_config_edit(): bool {
		if (!$this->config_is_writable()) {
			return false;
		}
		$path     = $this->resolved_config_path();
		$contents = file_get_contents($path);
		if (!is_string($contents)) {
			return false;
		}
		$define  = "define( 'WP_CACHE', true );";
		$pattern = '/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*(true|false|0|1)\s*\)\s*;/i';
		if (1 === preg_match($pattern, $contents)) {
			$updated = preg_replace($pattern, $define, $contents, 1);
		} else {
			$insert = $define . " // Added by NunezCache\n";
			$needle = "/* That's all, stop editing!";
			if (str_contains($contents, $needle)) {
				$updated = str_replace($needle, $insert . $needle, $contents);
			} else {
				$settings = "require_once ABSPATH . 'wp-settings.php';";
				if (!str_contains($contents, $settings)) {
					return false;
				}
				$updated = str_replace($settings, $insert . $settings, $contents);
			}
		}
		if (!is_string($updated)) {
			return false;
		}
		return $this->atomic_write($path, $updated);
	}

	/**
	 * The line the Status screen shows when wp-config.php cannot be edited.
	 *
	 * @return string PHP define, safe to display.
	 */
	public function suggested_wp_cache_line(): string {
		return "define( 'WP_CACHE', true );";
	}

	/**
	 * Resolved wp-config.php path.
	 *
	 * @return string Absolute path, or empty when it cannot be known.
	 */
	public function resolved_config_path(): string {
		if ('' !== $this->wp_config_path) {
			return $this->wp_config_path;
		}
		if (!defined('ABSPATH')) {
			return '';
		}
		$local = ABSPATH . 'wp-config.php';
		$above = dirname(ABSPATH) . '/wp-config.php';
		if (is_readable($local)) {
			return $local;
		}
		if (is_readable($above) && !is_readable(dirname(ABSPATH) . '/wp-settings.php')) {
			return $above;
		}
		return $local;
	}

	/**
	 * Drop-in contents, or null when the file is missing.
	 *
	 * @return string|null
	 */
	private function contents(): ?string {
		if (!is_file($this->dropin_path)) {
			return null;
		}
		$contents = file_get_contents($this->dropin_path);
		return is_string($contents) ? $contents : null;
	}

	/**
	 * Inactive drop-in. WordPress can include it while WP_CACHE stays true.
	 *
	 * @return string PHP source.
	 */
	private function neutered_source(): string {
		return <<<'PHP'
<?php
/**
 * NunezCache drop-in
 *
 * @package NunezCache
 * Purpose: Inactive placeholder while NunezCache is deactivated.
 * License: GPL-2.0-or-later
 */

PHP;
	}

	/**
	 * Write via a temp file in the same directory, then rename.
	 *
	 * @param string $path     Destination.
	 * @param string $contents Full file contents.
	 * @return bool False when the write or rename fails.
	 */
	private function atomic_write(string $path, string $contents): bool {
		$dir = dirname($path);
		if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
			return false;
		}
		$mode = is_file($path) ? fileperms($path) : 0644;
		$tmp  = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
		if (false === file_put_contents($tmp, $contents, LOCK_EX)) {
			if (is_file($tmp)) {
				unlink($tmp);
			}
			return false;
		}
		if (is_int($mode)) {
			chmod($tmp, $mode & 0777);
		}
		if (!rename($tmp, $path)) {
			unlink($tmp);
			return false;
		}
		return true;
	}
}
