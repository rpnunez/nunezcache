<?php
/**
 * Narrow bypass tests.
 *
 * @package NunezCache
 * Purpose: Slice 1 reasons are method, logged-in cookie, and admin paths only.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Covers the bypass reasons shipped in slice 1.
 */
class BypassTest extends NunezCache_Test_Case {

	/**
	 * Rules array with the slots later slices fill.
	 *
	 * @var array<string, mixed>
	 */
	private array $rules = array(
		'extra_path_prefixes'  => array( '/cart/' ),
		'tracking_parameters'  => array(),
	);

	/**
	 * Build a request for a public path.
	 *
	 * @param array<string, string> $cookies Cookie name to value.
	 */
	private function request(string $method = 'GET', string $path = '/hello/', array $cookies = array()): NunezCache_Request {
		return new NunezCache_Request($method, 'https', 'example.test', $path, array(), $cookies, 1);
	}

	/**
	 * GET and HEAD of a public URL may be stored.
	 */
	public function test_get_and_head_are_cacheable(): void {
		$bypass = new NunezCache_Bypass();

		$this->assertSame('', $bypass->reason($this->request('GET'), $this->rules));
		$this->assertSame('', $bypass->reason($this->request('HEAD'), $this->rules));
	}

	/**
	 * Anything other than GET or HEAD is the method reason.
	 */
	public function test_post_and_put_are_method(): void {
		$bypass = new NunezCache_Bypass();

		$this->assertSame('method', $bypass->reason($this->request('POST'), $this->rules));
		$this->assertSame('method', $bypass->reason($this->request('PUT', '/wp-admin/'), $this->rules));
	}

	/**
	 * A wordpress_logged_in_ cookie is not served and is not a cacheable miss.
	 */
	public function test_logged_in_cookie_reason(): void {
		$bypass = new NunezCache_Bypass();
		$cookie = array( 'wordpress_logged_in_abc' => 'secret-value' );

		$this->assertSame('logged_in_cookie', $bypass->reason($this->request('GET', '/hello/', $cookie), $this->rules));
	}

	/**
	 * Unrelated cookies stay cacheable. The cookie value is not a reason.
	 */
	public function test_other_cookies_are_not_logged_in(): void {
		$bypass = new NunezCache_Bypass();
		$cookie = array( 'wordpress_test_cookie' => 'wordpress_logged_in_abc' );

		$this->assertSame('', $bypass->reason($this->request('GET', '/hello/', $cookie), $this->rules));
	}

	/**
	 * wp-admin and wp-login.php are admin, including a subdirectory install.
	 */
	public function test_admin_paths(): void {
		$bypass = new NunezCache_Bypass();

		$this->assertSame('admin', $bypass->reason($this->request('GET', '/wp-admin/'), $this->rules));
		$this->assertSame('admin', $bypass->reason($this->request('GET', '/wp-admin'), $this->rules));
		$this->assertSame('admin', $bypass->reason($this->request('GET', '/wp-admin/admin-ajax.php'), $this->rules));
		$this->assertSame('admin', $bypass->reason($this->request('GET', '/wp-login.php'), $this->rules));
		$this->assertSame('admin', $bypass->reason($this->request('GET', '/blog/wp-admin/index.php'), $this->rules));
		$this->assertSame('', $bypass->reason($this->request('GET', '/wp-admin-guide/'), $this->rules));
	}

	/**
	 * Extra path prefixes are a later slice. Slice 1 does not apply them.
	 */
	public function test_extra_prefixes_are_not_applied_yet(): void {
		$bypass = new NunezCache_Bypass();

		$this->assertSame('', $bypass->reason($this->request('GET', '/cart/'), $this->rules));
	}
}
