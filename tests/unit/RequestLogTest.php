<?php
/**
 * Request log tests.
 *
 * @package NunezCache
 * Purpose: Prove the ring buffer caps at 200 and never stores secrets.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Covers append, the cap, and the last 50 rows.
 */
class RequestLogTest extends NunezCache_Test_Case {

	/**
	 * One safe row.
	 *
	 * @return array<string, mixed>
	 */
	private function row(int $time, string $result = 'HIT'): array {
		return array(
			'time'         => $time,
			'result'       => $result,
			'reason'       => '',
			'url'          => '/hello/',
			'milliseconds' => 4,
			'bytes'        => 1024,
		);
	}

	/**
	 * Recent rows come back newest first.
	 */
	public function test_recent_is_newest_first(): void {
		$log = new NunezCache_Request_Log($this->temp_dir() . '/requests.jsonl');
		$log->append($this->row(1, 'MISS'));
		$log->append($this->row(2, 'HIT'));

		$recent = $log->recent(50);

		$this->assertSame('HIT', $recent[0]['result']);
		$this->assertSame('MISS', $recent[1]['result']);
	}

	/**
	 * The file keeps 200 rows. recent(50) is the newest fifty.
	 */
	public function test_cap_is_200_and_recent_50(): void {
		$path = $this->temp_dir() . '/requests.jsonl';
		$log  = new NunezCache_Request_Log($path);
		for ($i = 1; $i <= 205; $i++) {
			$log->append($this->row($i));
		}

		$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$this->assertIsArray($lines);
		$this->assertCount(200, $lines);

		$recent = $log->recent(50);
		$this->assertCount(50, $recent);
		$this->assertSame(205, $recent[0]['time']);
		$this->assertSame(156, $recent[49]['time']);

		$oldest = json_decode((string) $lines[0], true);
		$this->assertSame(6, $oldest['time']);
	}

	/**
	 * Cookie values, bodies, and the bypass token are not written.
	 */
	public function test_secrets_are_dropped(): void {
		$path = $this->temp_dir() . '/requests.jsonl';
		$log  = new NunezCache_Request_Log($path);
		$log->append(
			array(
				'time'         => 1,
				'result'       => 'BYPASS',
				'reason'       => 'logged_in_cookie',
				'url'          => "/hello/\nSet-Cookie: no",
				'milliseconds' => 1,
				'bytes'        => 0,
				'body'         => '<html>secret body</html>',
				'cookie'       => 'wordpress_logged_in_x=secret',
				'bypass_token' => 'super-secret-token',
			)
		);

		$raw = (string) file_get_contents($path);
		$this->assertStringNotContainsString('secret body', $raw);
		$this->assertStringNotContainsString('wordpress_logged_in_x', $raw);
		$this->assertStringNotContainsString('super-secret-token', $raw);
		$this->assertStringNotContainsString("\nSet-Cookie", $raw);
		$this->assertSame('logged_in_cookie', $log->recent(1)[0]['reason']);
	}

	/**
	 * A missing log reads as no rows.
	 */
	public function test_missing_file_is_empty(): void {
		$log = new NunezCache_Request_Log($this->temp_dir() . '/missing.jsonl');

		$this->assertSame(array(), $log->recent(50));
	}
}
