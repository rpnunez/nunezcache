<?php
/**
 * HTTP cache behavior without WordPress.
 *
 * @package NunezCache
 * Purpose: Anonymous GET is MISS then HIT; logged-in and POST do not replace the file; purge restores MISS.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Drives the early cache through PHP's built-in server.
 */
class HttpCacheTest extends NunezCache_Test_Case {

	/**
	 * Built-in server process for the current request.
	 *
	 * @var resource|null
	 */
	private $server = null;

	/**
	 * Cache root for the running server.
	 */
	private string $root = '';

	/**
	 * wp-content stand-in passed to the front controller.
	 */
	private string $content = '';

	/**
	 * Base URL of the running server, including the port.
	 */
	private string $base = '';

	/**
	 * Stop a server left open by a failed request.
	 */
	protected function tearDown(): void {
		$this->stop_server();
		parent::tearDown();
	}

	/**
	 * First anonymous GET is MISS, the second is HIT, and the bodies match.
	 */
	public function test_anonymous_get_is_miss_then_hit(): void {
		$this->boot_server(array( 'enabled' => true ));

		$first  = $this->http('GET', '/hello/');
		$second = $this->http('GET', '/hello/');

		$this->assertSame('MISS', $first['result']);
		$this->assertSame('HIT', $second['result']);
		$this->assertSame($first['body'], $second['body']);
		$this->assertStringContainsString('public-post', $second['body']);
		$this->assertStringContainsString('must-revalidate', $second['cache_control']);
		$files = glob($this->root . '/pages/1/*.html');
		$this->assertIsArray($files);
		$this->assertNotEmpty($files);
		$this->assertStringContainsString('/cache/nunezcache/pages/1/', $files[0]);
	}

	/**
	 * A logged-in cookie neither serves nor overwrites the stored file.
	 */
	public function test_logged_in_cookie_does_not_serve_or_overwrite(): void {
		$this->boot_server(array( 'enabled' => true ));
		$stored = $this->http('GET', '/hello/');
		$file   = $this->only_html();
		$hash   = hash_file('sha256', $file);

		$logged_in = $this->http(
			'GET',
			'/hello/',
			array( 'Cookie: wordpress_logged_in_abc=secret' )
		);
		$again = $this->http('GET', '/hello/');

		$this->assertSame('BYPASS', $logged_in['result']);
		$this->assertNotSame($stored['body'], $logged_in['body']);
		$this->assertSame($hash, hash_file('sha256', $file));
		$this->assertSame('HIT', $again['result']);
		$this->assertSame($stored['body'], $again['body']);
	}

	/**
	 * POST is not stored and does not delete the anonymous file.
	 */
	public function test_post_is_not_stored_and_autosave_does_not_delete(): void {
		$this->boot_server(array( 'enabled' => true ));
		$this->http('GET', '/hello/');
		$file = $this->only_html();
		$hash = hash_file('sha256', $file);

		$post = $this->http('POST', '/hello/');
		$again = $this->http('GET', '/hello/');

		$this->assertSame('BYPASS', $post['result']);
		$this->assertSame($hash, hash_file('sha256', $file));
		$this->assertSame('HIT', $again['result']);
		$this->assertFileExists($file);
	}

	/**
	 * Purge all makes the next anonymous GET a MISS and leaves state.php.
	 */
	public function test_purge_all_makes_the_next_get_a_miss(): void {
		$this->boot_server(array( 'enabled' => true ));
		$this->http('GET', '/hello/');
		$removed = ( new NunezCache_File_Store($this->root) )->purge_all();
		$after   = $this->http('GET', '/hello/');

		$this->assertGreaterThan(0, $removed);
		$this->assertSame('MISS', $after['result']);
		$this->assertFileExists($this->root . '/state.php');
	}

	/**
	 * Prepare the cache directory. Each HTTP call starts its own server process.
	 *
	 * @param array<string, mixed> $state State merged onto defaults.
	 */
	private function boot_server(array $state): void {
		$this->content = $this->temp_dir();
		$this->root    = $this->content . '/cache/nunezcache';
		mkdir($this->root, 0777, true);
		( new NunezCache_State_File($this->root . '/state.php') )->write(
			array_merge(NunezCache_State_File::defaults(), $state)
		);
		$port = $this->free_port();
		$this->base = 'http://127.0.0.1:' . $port;
		$this->start_server($port);
	}

	/**
	 * Start a one-request server. A HIT calls exit, which stops this process.
	 */
	private function start_server(int $port): void {
		$this->stop_server();
		$env = getenv();
		if (!is_array($env)) {
			$env = array();
		}
		$env['NUNEZCACHE_CONTENT_DIR'] = $this->content;
		$env['NUNEZCACHE_EARLY_FILE']  = $this->plugin_root() . '/includes/runtime/class-early-cache.php';
		$pipes   = array();
		$process = proc_open(
			array(
				PHP_BINARY,
				'-d',
				'display_errors=0',
				'-S',
				'127.0.0.1:' . $port,
				$this->plugin_root() . '/tests/fixtures/front-controller.php',
			),
			array(
				0 => array( 'file', '/dev/null', 'r' ),
				1 => array( 'file', '/dev/null', 'a' ),
				2 => array( 'file', '/dev/null', 'a' ),
			),
			$pipes,
			$this->plugin_root(),
			$env
		);
		$this->assertIsResource($process);
		$this->server = $process;
		$this->wait_until_ready($port);
	}

	/**
	 * Stop the one-request server if it is still running.
	 */
	private function stop_server(): void {
		if (!is_resource($this->server)) {
			return;
		}
		proc_terminate($this->server);
		proc_close($this->server);
		$this->server = null;
	}

	/**
	 * Bind a free loopback port, then release it for the server.
	 */
	private function free_port(): int {
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
		$this->assertIsResource($socket, $error);
		$name = stream_socket_get_name($socket, false);
		fclose($socket);
		$this->assertIsString($name);
		$port = (int) substr($name, (int) strrpos($name, ':') + 1);
		$this->assertGreaterThan(0, $port);
		return $port;
	}

	/**
	 * Wait until the built-in server accepts connections.
	 */
	private function wait_until_ready(int $port): void {
		for ($i = 0; $i < 50; $i++) {
			$socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
			if (is_resource($socket)) {
				fclose($socket);
				return;
			}
			usleep(100000);
		}
		$this->fail('Built-in server did not start.');
	}

	/**
	 * Issue one HTTP request to the fixture.
	 *
	 * @param array<int, string> $headers Extra request headers.
	 * @return array{result: string, body: string, cache_control: string}
	 */
	private function http(string $method, string $path, array $headers = array()): array {
		$headers[] = 'Connection: close';
		$context = stream_context_create(
			array(
				'http' => array(
					'method'        => $method,
					'header'        => implode("\r\n", $headers),
					'ignore_errors' => true,
					'timeout'       => 5,
				),
			)
		);
		$body = file_get_contents($this->base . $path, false, $context);
		$this->assertIsArray($http_response_header);
		$result = '';
		$cache  = '';
		foreach ($http_response_header as $header) {
			if (str_starts_with($header, 'X-NunezCache:')) {
				$result = trim(substr($header, strlen('X-NunezCache:')));
			}
			if (str_starts_with($header, 'Cache-Control:')) {
				$cache = trim(substr($header, strlen('Cache-Control:')));
			}
		}
		return array(
			'result'        => $result,
			'body'          => false === $body ? '' : $body,
			'cache_control' => $cache,
		);
	}

	/**
	 * The single cached HTML file.
	 */
	private function only_html(): string {
		$files = glob($this->root . '/pages/1/*.html');
		$this->assertIsArray($files);
		$this->assertCount(1, $files);
		return $files[0];
	}
}
