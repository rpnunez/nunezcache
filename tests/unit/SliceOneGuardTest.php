<?php
/**
 * Slice 1 boundary tests.
 *
 * @package NunezCache
 * Purpose: The runtime stays free of WordPress, activation does not enable, and autosave has no purge hook.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Reads source to lock the slice 1 boundaries the behavior tests do not execute.
 */
class SliceOneGuardTest extends NunezCache_Test_Case {

	/**
	 * Runtime files do not call WordPress or an object cache.
	 */
	public function test_runtime_does_not_call_wordpress_or_redis(): void {
		$files = glob($this->plugin_root() . '/includes/runtime/*.php');
		$this->assertIsArray($files);
		$this->assertNotEmpty($files);
		foreach ($files as $file) {
			$code = (string) file_get_contents($file);
			$this->assertDoesNotMatchRegularExpression(
				'/\b(get_option|update_option|add_action|add_filter|wp_mkdir_p|wp_remote_get|is_multisite|wp_is_mobile|Redis)\b/',
				$code,
				$file
			);
		}
	}

	/**
	 * Activation does not turn caching on and does not write the drop-in.
	 */
	public function test_activation_does_not_enable(): void {
		$source = (string) file_get_contents($this->plugin_root() . '/includes/class-plugin.php');
		$start  = strpos($source, 'function activate');
		$end    = strpos($source, 'function ', (int) $start + 10);
		$this->assertNotFalse($start);
		$this->assertNotFalse($end);
		$body = substr($source, (int) $start, (int) $end - (int) $start);

		$this->assertStringNotContainsString('install(', $body);
		$this->assertStringNotContainsString("'enabled' => true", $body);
		$this->assertStringNotContainsString('"enabled" => true', $body);
	}

	/**
	 * Slice 1 does not delete files on save_post, so an autosave cannot purge.
	 */
	public function test_no_save_post_purge(): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->plugin_root() . '/includes', FilesystemIterator::SKIP_DOTS)
		);
		foreach ($iterator as $file) {
			if (!$file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
				continue;
			}
			$code = (string) file_get_contents($file->getPathname());
			$this->assertStringNotContainsString('save_post', $code, $file->getPathname());
			$this->assertStringNotContainsString('wp_is_post_autosave', $code, $file->getPathname());
		}
	}

	/**
	 * The early cache looks under wp-content/cache/nunezcache.
	 */
	public function test_cache_directory_is_under_wp_content(): void {
		$code = (string) file_get_contents($this->plugin_root() . '/includes/runtime/class-early-cache.php');
		$this->assertStringContainsString('/cache/nunezcache', $code);
	}
}
