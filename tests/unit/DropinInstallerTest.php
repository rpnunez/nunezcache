<?php
/**
 * Drop-in installer tests.
 *
 * @package NunezCache
 * Purpose: The template only calls the early cache, and wp-config is edited only on request.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Covers drop-in install, a foreign file, and the WP_CACHE edit.
 */
class DropinInstallerTest extends NunezCache_Test_Case {

	/**
	 * Load the installer. It has no load-time WordPress calls.
	 */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname(__DIR__, 2) . '/includes/dropin/class-dropin-installer.php';
	}

	/**
	 * The template's executable lines are the require and the serve call.
	 */
	public function test_template_only_calls_early_cache(): void {
		$template = (string) file_get_contents($this->plugin_root() . '/includes/dropin/template.php');
		$code     = preg_replace('/\/\*.*?\*\//s', '', $template);
		$this->assertIsString($code);
		$lines = array();
		foreach (preg_split('/\R/', $code) ?: array() as $line) {
			$trimmed = trim($line);
			if ('' === $trimmed || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
				continue;
			}
			$lines[] = $trimmed;
		}

		$this->assertSame(
			array(
				'<?php',
				'require_once __NUNEZCACHE_EARLY_FILE__;',
				'NunezCache_Early_Cache::serve();',
			),
			$lines
		);
	}

	/**
	 * Install writes a drop-in that calls serve() with the real runtime path.
	 */
	public function test_install_writes_dropin(): void {
		$dir     = $this->temp_dir();
		$dropin  = $dir . '/advanced-cache.php';
		$early   = $this->plugin_root() . '/includes/runtime/class-early-cache.php';
		$installer = new NunezCache_Dropin_Installer($this->plugin_root(), $dropin, $dir . '/wp-config.php');

		$this->assertTrue($installer->install());
		$contents = (string) file_get_contents($dropin);
		$this->assertStringContainsString('NunezCache_Early_Cache::serve();', $contents);
		$this->assertStringContainsString(var_export($early, true), $contents);
		$this->assertStringNotContainsString('__NUNEZCACHE_EARLY_FILE__', $contents);
		$this->assertTrue($installer->is_ours());
		$this->assertTrue($installer->is_active());
	}

	/**
	 * A foreign advanced-cache.php is left in place.
	 */
	public function test_install_does_not_replace_a_foreign_dropin(): void {
		$dir    = $this->temp_dir();
		$dropin = $dir . '/advanced-cache.php';
		file_put_contents($dropin, "<?php\n// Foreign cache\n");
		$installer = new NunezCache_Dropin_Installer($this->plugin_root(), $dropin, $dir . '/wp-config.php');

		$this->assertFalse($installer->install());
		$this->assertSame("<?php\n// Foreign cache\n", (string) file_get_contents($dropin));
	}

	/**
	 * Deactivation neuters our drop-in and does not touch a page file.
	 */
	public function test_neuter_keeps_html_and_removes_serve_call(): void {
		$dir    = $this->temp_dir();
		$dropin = $dir . '/advanced-cache.php';
		$html   = $dir . '/page.html';
		file_put_contents($html, '<html>keep</html>');
		$installer = new NunezCache_Dropin_Installer($this->plugin_root(), $dropin, $dir . '/wp-config.php');
		$installer->install();

		$this->assertTrue($installer->neuter());
		$this->assertTrue($installer->is_ours());
		$this->assertFalse($installer->is_active());
		$this->assertStringNotContainsString('NunezCache_Early_Cache::serve()', (string) file_get_contents($dropin));
		$this->assertSame('<html>keep</html>', (string) file_get_contents($html));
	}

	/**
	 * Allow wp-config.php edit inserts the define. A second call leaves a single true define.
	 */
	public function test_allow_wp_config_edit_writes_define_when_writable(): void {
		$dir    = $this->temp_dir();
		$config = $dir . '/wp-config.php';
		file_put_contents($config, "<?php\n/* That's all, stop editing! */\nrequire_once ABSPATH . 'wp-settings.php';\n");
		$installer = new NunezCache_Dropin_Installer($this->plugin_root(), $dir . '/advanced-cache.php', $config);

		$this->assertTrue($installer->allow_wp_config_edit());
		$contents = (string) file_get_contents($config);
		$this->assertSame(1, preg_match_all("/define\s*\(\s*'WP_CACHE'\s*,\s*true\s*\)\s*;/", $contents));
		$this->assertStringContainsString('require_once ABSPATH . \'wp-settings.php\';', $contents);

		file_put_contents($config, "<?php\ndefine( 'WP_CACHE', false );\n");
		$this->assertTrue($installer->allow_wp_config_edit());
		$updated = (string) file_get_contents($config);
		$this->assertStringContainsString("define( 'WP_CACHE', true );", $updated);
		$this->assertStringNotContainsString('false', $updated);
	}

	/**
	 * A config that is not writable is left unchanged.
	 */
	public function test_allow_wp_config_edit_refuses_when_not_writable(): void {
		$dir    = $this->temp_dir();
		$config = $dir . '/wp-config.php';
		$original = "<?php\n/* That's all, stop editing! */\n";
		file_put_contents($config, $original);
		chmod($config, 0444);
		$installer = new NunezCache_Dropin_Installer($this->plugin_root(), $dir . '/advanced-cache.php', $config);

		$this->assertFalse($installer->config_is_writable());
		$this->assertFalse($installer->allow_wp_config_edit());
		$this->assertSame($original, (string) file_get_contents($config));
		chmod($config, 0644);
	}
}
