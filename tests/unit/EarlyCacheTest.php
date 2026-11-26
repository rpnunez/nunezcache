<?php
/**
 * Early cache decision tests.
 *
 * @package NunezCache
 * Purpose: Prove HIT, MISS, bypass, pause, and expiry without booting WordPress.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Covers NunezCache_Early_Cache::decide() and the HIT headers.
 */
class EarlyCacheTest extends NunezCache_Test_Case {

	/**
	 * Public anonymous request.
	 */
	private function request(string $method = 'GET', array $cookies = array()): NunezCache_Request {
		return new NunezCache_Request($method, 'https', 'example.test', '/hello/', array(), $cookies, 1);
	}

	/**
	 * Write state.php under a temp cache root.
	 *
	 * @param array<string, mixed> $overrides Fields merged onto defaults.
	 */
	private function root_with_state(array $overrides = array()): string {
		return $this->content_tree($overrides)['root'];
	}

	/**
	 * Cache root nested at wp-content/cache/nunezcache, which serve() derives.
	 *
	 * @param array<string, mixed> $overrides Fields merged onto defaults.
	 * @return array{content: string, root: string}
	 */
	private function content_tree(array $overrides = array()): array {
		$content = $this->temp_dir();
		$root    = $content . '/cache/nunezcache';
		mkdir($root, 0777, true);
		$state = new NunezCache_State_File($root . '/state.php');
		$state->write(array_merge(NunezCache_State_File::defaults(), $overrides));
		return array(
			'content' => $content,
			'root'    => $root,
		);
	}

	/**
	 * Fresh metadata for a body.
	 *
	 * @return array<string, mixed>
	 */
	private function meta(string $body, int $expires): array {
		return array(
			'url'            => 'https://example.test/hello/',
			'created'        => $expires - 36000,
			'expires'        => $expires,
			'bytes'          => strlen($body),
			'content_hash'   => hash('sha256', $body),
			'dependency_ids' => array(
				'posts' => array( 7 ),
				'terms' => array(),
				'users' => array(),
			),
		);
	}

	/**
	 * Off and paused do not serve and do not look like a miss.
	 */
	public function test_off_and_paused_are_ignored(): void {
		$request = $this->request();
		$body    = '<!DOCTYPE html><html><body>cached</body></html>';
		$now     = 1_700_000_000;

		$off = $this->root_with_state(array( 'enabled' => false, 'paused' => false ));
		$key = NunezCache_Key::from_request($request, array());
		( new NunezCache_File_Store($off) )->write($key, 1, $body, $this->meta($body, $now + 100));
		$off_decision = NunezCache_Early_Cache::decide($off, $request, $now);
		$this->assertSame('ignore', $off_decision['action']);

		$paused = $this->root_with_state(array( 'enabled' => true, 'paused' => true ));
		( new NunezCache_File_Store($paused) )->write($key, 1, $body, $this->meta($body, $now + 100));
		$paused_decision = NunezCache_Early_Cache::decide($paused, $request, $now);
		$this->assertSame('ignore', $paused_decision['action']);
	}

	/**
	 * A missing file is a miss. A fresh file is a hit with the same body.
	 */
	public function test_miss_then_hit_bodies_match(): void {
		$root    = $this->root_with_state(array( 'enabled' => true ));
		$request = $this->request();
		$now     = 1_700_000_000;
		$miss    = NunezCache_Early_Cache::decide($root, $request, $now);
		$this->assertSame('miss', $miss['action']);

		$body = '<!DOCTYPE html><html><body>public-post</body></html>';
		( new NunezCache_File_Store($root) )->write($miss['key'], 1, $body, $this->meta($body, $now + 100));
		$hit = NunezCache_Early_Cache::decide($root, $request, $now);

		$this->assertSame('hit', $hit['action']);
		$this->assertSame($body, $hit['body']);
		$this->assertSame($miss['key'], $hit['key']);
	}

	/**
	 * An expired meta file is not served.
	 */
	public function test_expired_meta_is_a_miss(): void {
		$root    = $this->root_with_state(array( 'enabled' => true ));
		$request = $this->request();
		$now     = 1_700_000_000;
		$body    = '<!DOCTYPE html><html><body>old</body></html>';
		$key     = NunezCache_Key::from_request($request, array());
		( new NunezCache_File_Store($root) )->write($key, 1, $body, $this->meta($body, $now - 1));

		$decision = NunezCache_Early_Cache::decide($root, $request, $now);

		$this->assertSame('miss', $decision['action']);
	}

	/**
	 * A logged-in cookie does not receive the stored body.
	 */
	public function test_logged_in_cookie_does_not_serve(): void {
		$root    = $this->root_with_state(array( 'enabled' => true ));
		$anon    = $this->request();
		$now     = 1_700_000_000;
		$body    = '<!DOCTYPE html><html><body>public-post</body></html>';
		$key     = NunezCache_Key::from_request($anon, array());
		$store   = new NunezCache_File_Store($root);
		$store->write($key, 1, $body, $this->meta($body, $now + 50));

		$logged_in = $this->request('GET', array( 'wordpress_logged_in_abc' => 'secret' ));
		$decision  = NunezCache_Early_Cache::decide($root, $logged_in, $now);

		$this->assertSame('bypass', $decision['action']);
		$this->assertSame('logged_in_cookie', $decision['reason']);
		$this->assertSame($body, $store->read($key, 1)['body']);
	}

	/**
	 * POST is a bypass, so capture will not store it.
	 */
	public function test_post_is_bypass(): void {
		$root     = $this->root_with_state(array( 'enabled' => true ));
		$decision = NunezCache_Early_Cache::decide($root, $this->request('POST'), 1_700_000_000);

		$this->assertSame('bypass', $decision['action']);
		$this->assertSame('method', $decision['reason']);
	}

	/**
	 * Purge all makes the next anonymous read a miss.
	 */
	public function test_purge_all_makes_the_next_read_a_miss(): void {
		$root    = $this->root_with_state(array( 'enabled' => true ));
		$request = $this->request();
		$now     = 1_700_000_000;
		$body    = '<!DOCTYPE html><html><body>public-post</body></html>';
		$store   = new NunezCache_File_Store($root);
		$key     = NunezCache_Key::from_request($request, array());
		$store->write($key, 1, $body, $this->meta($body, $now + 50));
		$this->assertSame('hit', NunezCache_Early_Cache::decide($root, $request, $now)['action']);

		$store->purge_all();

		$this->assertSame('miss', NunezCache_Early_Cache::decide($root, $request, $now)['action']);
		$this->assertFileExists($root . '/state.php');
	}

	/**
	 * HIT response headers are fixed and do not vary by cookie.
	 */
	public function test_hit_headers(): void {
		$body    = '<!DOCTYPE html><html></html>';
		$headers = NunezCache_Early_Cache::hit_headers($body);

		$this->assertContains('X-NunezCache: HIT', $headers);
		$this->assertContains('Content-Type: text/html; charset=UTF-8', $headers);
		$this->assertContains('Cache-Control: public, max-age=0, must-revalidate', $headers);
		$this->assertContains('Content-Length: ' . (string) strlen($body), $headers);
	}

	/**
	 * serve() on a HIT prints the body, logs HIT, and does not continue into WordPress.
	 */
	public function test_serve_hit_exits_without_wordpress(): void {
		$tree    = $this->content_tree(
			array(
				'enabled'      => true,
				'bypass_token' => 'super-secret-token',
			)
		);
		$root    = $tree['root'];
		$content = $tree['content'];
		$request = new NunezCache_Request('GET', 'https', 'example.test', '/hello/', array(), array(), 1);
		$body    = '<!DOCTYPE html><html><body>public-post</body></html>';
		$now     = time() + 100;
		$key     = NunezCache_Key::from_request($request, array());
		( new NunezCache_File_Store($root) )->write(
			$key,
			1,
			$body,
			$this->meta($body, $now)
		);

		$script = <<<'PHP'
<?php
define('WP_CONTENT_DIR', $argv[1]);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['REQUEST_URI'] = '/hello/';
$_COOKIE = array();
require $argv[2];
if (function_exists('add_action') || function_exists('did_action')) {
    fwrite(STDERR, "WORDPRESS\n");
}
NunezCache_Early_Cache::serve();
file_put_contents($argv[3], 'continued');
echo 'CONTINUED';
PHP;

		$result = $this->run_php(
			$script,
			array(
				$content,
				$this->plugin_root() . '/includes/runtime/class-early-cache.php',
				$root . '/continued.txt',
			)
		);

		$this->assertSame(0, $result['exit'], $result['stderr']);
		$this->assertSame($body, $result['stdout']);
		$this->assertStringNotContainsString('CONTINUED', $result['stdout']);
		$this->assertFileDoesNotExist($root . '/continued.txt');
		$this->assertStringNotContainsString('WORDPRESS', $result['stderr']);
		$log = (string) file_get_contents($root . '/log/requests.jsonl');
		$this->assertStringContainsString('"result":"HIT"', $log);
		$this->assertStringNotContainsString('super-secret-token', $log);
		$this->assertStringNotContainsString($body, $log);
	}

	/**
	 * A logged-in cookie does not exit with the file and does not mark a miss.
	 */
	public function test_serve_logged_in_does_not_define_miss(): void {
		$tree    = $this->content_tree(array( 'enabled' => true ));
		$root    = $tree['root'];
		$content = $tree['content'];
		$request = $this->request();
		$body    = '<!DOCTYPE html><html><body>public-post</body></html>';
		$key     = NunezCache_Key::from_request($request, array());
		( new NunezCache_File_Store($root) )->write($key, 1, $body, $this->meta($body, time() + 100));
		$hash    = hash('sha256', (string) file_get_contents($root . '/pages/1/' . $key . '.html'));

		$script = <<<'PHP'
<?php
define('WP_CONTENT_DIR', $argv[1]);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['REQUEST_URI'] = '/hello/';
$_COOKIE = array('wordpress_logged_in_abc' => 'secret');
require $argv[2];
NunezCache_Early_Cache::serve();
echo defined('NUNEZCACHE_MISS') ? 'MISS' : 'NOMISS';
echo defined('NUNEZCACHE_BYPASS') ? NUNEZCACHE_BYPASS : 'NOBYPASS';
PHP;

		$result = $this->run_php(
			$script,
			array(
				$content,
				$this->plugin_root() . '/includes/runtime/class-early-cache.php',
			)
		);

		$this->assertSame('NOMISSlogged_in_cookie', $result['stdout']);
		$this->assertStringNotContainsString('public-post', $result['stdout']);
		$this->assertSame($hash, hash('sha256', (string) file_get_contents($root . '/pages/1/' . $key . '.html')));
	}

	/**
	 * A multisite constant refuses the cached file and does not mark a miss.
	 */
	public function test_multisite_does_not_serve(): void {
		$tree    = $this->content_tree(array( 'enabled' => true ));
		$root    = $tree['root'];
		$content = $tree['content'];
		$request = $this->request();
		$body    = '<!DOCTYPE html><html><body>public-post</body></html>';
		$key     = NunezCache_Key::from_request($request, array());
		( new NunezCache_File_Store($root) )->write($key, 1, $body, $this->meta($body, time() + 100));

		$script = <<<'PHP'
<?php
define('MULTISITE', true);
define('WP_CONTENT_DIR', $argv[1]);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['REQUEST_URI'] = '/hello/';
$_COOKIE = array();
require $argv[2];
NunezCache_Early_Cache::serve();
echo defined('NUNEZCACHE_MISS') ? 'MISS' : 'NOMISS';
echo defined('NUNEZCACHE_BYPASS') ? 'BYPASS' : 'NOBYPASS';
echo 'DONE';
PHP;

		$result = $this->run_php(
			$script,
			array(
				$content,
				$this->plugin_root() . '/includes/runtime/class-early-cache.php',
			)
		);

		$this->assertSame('NOMISSNOBYPASSDONE', $result['stdout']);
		$this->assertStringNotContainsString('public-post', $result['stdout']);
	}
}
