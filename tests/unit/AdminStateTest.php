<?php
/**
 * Status word and enable guard tests.
 *
 * @package NunezCache
 * Purpose: Off, On, and Paused, including the WP_CACHE and multisite refusals.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Covers pure admin decisions that do not boot WordPress.
 */
class AdminStateTest extends NunezCache_Test_Case {

	/**
	 * Load the admin classes. They have no load-time WordPress calls.
	 */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname(__DIR__, 2) . '/includes/admin/class-status-screen.php';
		require_once dirname(__DIR__, 2) . '/includes/admin/class-admin.php';
	}

	/**
	 * The status word is Off, On, or Paused. Missing WP_CACHE is not On.
	 */
	public function test_status_word(): void {
		$this->assertSame('Off', NunezCache_Admin::status_word(false, false, false));
		$this->assertSame('Off', NunezCache_Admin::status_word(false, true, false));
		$this->assertSame('On', NunezCache_Admin::status_word(false, true, true));
		$this->assertSame('Paused', NunezCache_Admin::status_word(true, false, true));
		$this->assertSame('Paused', NunezCache_Admin::status_word(true, true, true));
	}

	/**
	 * Multisite, an unwritable directory, and missing WP_CACHE block enable.
	 */
	public function test_enable_block_reasons(): void {
		$this->assertSame('multisite', NunezCache_Admin::enable_block_reason(true, true, true));
		$this->assertSame('not_writable', NunezCache_Admin::enable_block_reason(false, false, true));
		$this->assertSame('wp_cache_missing', NunezCache_Admin::enable_block_reason(false, true, false));
		$this->assertSame('', NunezCache_Admin::enable_block_reason(false, true, true));
	}
}
