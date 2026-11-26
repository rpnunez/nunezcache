<?php
/**
 * Response qualification tests.
 *
 * @package NunezCache
 * Purpose: Only a 200 HTML document is stored. Logged-in cookies and POST bypasses are not.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Covers the capture decision that runs after WordPress on a miss.
 */
class CaptureQualifyTest extends NunezCache_Test_Case {

	/**
	 * Capture under test.
	 */
	private NunezCache_Response_Capture $capture;

	/**
	 * Build capture against a temp cache.
	 */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname(__DIR__, 2) . '/includes/capture/class-response-capture.php';
		$root = $this->temp_dir();
		$this->capture = new NunezCache_Response_Capture(
			new NunezCache_State_File($root . '/state.php'),
			new NunezCache_File_Store($root),
			new NunezCache_Request_Log($root . '/log/requests.jsonl')
		);
	}

	/**
	 * A full HTML document at status 200 is stored as a miss.
	 */
	public function test_html_200_is_stored(): void {
		$decision = $this->capture->qualify(
			200,
			'text/html; charset=UTF-8',
			'<!DOCTYPE html><html><body>Hi</body></html>',
			'',
			'',
			false,
			false
		);

		$this->assertTrue($decision['store']);
		$this->assertSame('MISS', $decision['result']);
		$this->assertSame('', $decision['reason']);
	}

	/**
	 * POST's early reason, a logged-in cookie, and a non-HTML body are not stored.
	 */
	public function test_refuses_post_logged_in_and_non_html(): void {
		$post = $this->capture->qualify(200, 'text/html', '<!DOCTYPE html><html></html>', 'method', '', false, false);
		$this->assertFalse($post['store']);
		$this->assertSame('BYPASS', $post['result']);
		$this->assertSame('method', $post['reason']);

		$logged_in = $this->capture->qualify(200, 'text/html', '<!DOCTYPE html><html></html>', '', '', true, false);
		$this->assertFalse($logged_in['store']);
		$this->assertSame('logged_in_cookie', $logged_in['reason']);

		$set_cookie = $this->capture->qualify(200, 'text/html', '<!DOCTYPE html><html></html>', '', '', false, true);
		$this->assertFalse($set_cookie['store']);
		$this->assertSame('logged_in_cookie', $set_cookie['reason']);

		$json = $this->capture->qualify(200, 'application/json', '{"ok":true}', '', '', false, false);
		$this->assertFalse($json['store']);
		$this->assertSame('not_html', $json['reason']);

		$status = $this->capture->qualify(404, 'text/html', '<!DOCTYPE html><html></html>', '', '', false, false);
		$this->assertFalse($status['store']);
		$this->assertSame('status', $status['reason']);
	}

	/**
	 * nunezcache_do_not_cache() is a bypass and does not store.
	 */
	public function test_do_not_cache_reason_is_not_stored(): void {
		$decision = $this->capture->qualify(
			200,
			'text/html',
			'<!DOCTYPE html><html></html>',
			'',
			'cart',
			false,
			false
		);

		$this->assertFalse($decision['store']);
		$this->assertSame('cart', $decision['reason']);
	}
}
