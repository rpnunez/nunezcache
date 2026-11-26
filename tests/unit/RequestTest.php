<?php
/**
 * Request parsing tests.
 *
 * @package NunezCache
 * Purpose: Prove the runtime request is built from superglobals without WordPress.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Covers scheme, host, path, query, and the single-site blog id.
 */
class RequestTest extends NunezCache_Test_Case {

	/**
	 * Saved superglobals restored after each test.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved = array();

	/**
	 * Snapshot superglobals.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved = array(
			'SERVER' => $_SERVER,
			'COOKIE' => $_COOKIE,
		);
	}

	/**
	 * Restore superglobals.
	 */
	protected function tearDown(): void {
		$_SERVER = $this->saved['SERVER'];
		$_COOKIE = $this->saved['COOKIE'];
		parent::tearDown();
	}

	/**
	 * An HTTPS GET exposes the pieces the cache key needs.
	 */
	public function test_from_globals_reads_https_get(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTPS']          = 'on';
		$_SERVER['HTTP_HOST']      = 'Example.TEST';
		$_SERVER['REQUEST_URI']    = '/hello/?b=2&a=1';
		$_COOKIE                   = array( 'plain' => '1' );

		$request = NunezCache_Request::from_globals();

		$this->assertSame('GET', $request->method());
		$this->assertSame('https', $request->scheme());
		$this->assertSame('example.test', $request->host());
		$this->assertSame('/hello/', $request->path());
		$this->assertSame(array( 'b' => '2', 'a' => '1' ), $request->query());
		$this->assertSame(array( 'plain' => '1' ), $request->cookies());
		$this->assertSame(1, $request->blog_id());
	}

	/**
	 * Port 443 counts as HTTPS when the HTTPS flag is absent.
	 */
	public function test_port_443_is_https(): void {
		unset($_SERVER['HTTPS']);
		$_SERVER['SERVER_PORT']    = '443';
		$_SERVER['REQUEST_METHOD'] = 'HEAD';
		$_SERVER['HTTP_HOST']      = 'example.test';
		$_SERVER['REQUEST_URI']    = '/';
		$_COOKIE                   = array();

		$request = NunezCache_Request::from_globals();

		$this->assertSame('https', $request->scheme());
		$this->assertSame('HEAD', $request->method());
		$this->assertSame('/', $request->path());
	}

	/**
	 * The filter array lists cookie names and omits cookie values.
	 */
	public function test_to_array_omits_cookie_values(): void {
		$request = new NunezCache_Request(
			'GET',
			'http',
			'example.test',
			'/a/',
			array( 'a' => '1' ),
			array( 'wordpress_logged_in_x' => 'secret' ),
			1
		);

		$array = $request->to_array();

		$this->assertSame(array( 'wordpress_logged_in_x' ), $array['cookie_names']);
		$this->assertArrayNotHasKey('cookies', $array);
		$this->assertStringNotContainsString('secret', (string) json_encode($array));
	}
}
