<?php
/**
 * Early page cache.
 *
 * @package NunezCache
 * Purpose: Serve a fresh anonymous HTML file and exit, or mark a miss or bypass and return.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/class-state-file.php';
require_once __DIR__ . '/class-request.php';
require_once __DIR__ . '/class-bypass.php';
require_once __DIR__ . '/class-key.php';
require_once __DIR__ . '/class-file-store.php';
require_once __DIR__ . '/class-request-log.php';

/**
 * Drop-in entry. serve() is the only method the template calls.
 */
class NunezCache_Early_Cache {

	/**
	 * Serve a HIT and exit, or return so the caller can continue.
	 *
	 * Off, paused, and multisite return without defining a miss, so nothing is stored.
	 * A HIT logs one row and exits. This method does not read the options table.
	 */
	public static function serve(): void {
		$started = microtime(true);
		if (!defined('NUNEZCACHE_STARTED_AT')) {
			define('NUNEZCACHE_STARTED_AT', $started);
		}
		if (!defined('WP_CONTENT_DIR')) {
			return;
		}

		$root     = WP_CONTENT_DIR . '/cache/nunezcache';
		$request  = NunezCache_Request::from_globals();
		$decision = self::decide($root, $request);

		if ('hit' === $decision['action']) {
			self::log_result($root, $decision, $started, 'HIT', '');
			self::send_hit($decision['body'], $request->method());
			exit;
		}
		if ('miss' === $decision['action']) {
			if (!defined('NUNEZCACHE_MISS')) {
				define('NUNEZCACHE_MISS', true);
			}
			if (!defined('NUNEZCACHE_KEY') && '' !== $decision['key']) {
				define('NUNEZCACHE_KEY', $decision['key']);
			}
			return;
		}
		if ('bypass' === $decision['action'] && !defined('NUNEZCACHE_BYPASS')) {
			define('NUNEZCACHE_BYPASS', $decision['reason']);
		}
	}

	/**
	 * Decide hit, miss, bypass, or ignore for one request.
	 *
	 * Ignore means the cache is off, paused, or this is a multisite process.
	 * Does not throw and does not write.
	 *
	 * @param string             $cache_root Cache root. Page files live under this directory.
	 * @param NunezCache_Request $request    Request to judge.
	 * @param int|null           $now        Unix timestamp in seconds. Null uses the current time.
	 * @return array{action: string, reason: string, body: string, key: string, bytes: int, url: string, blog_id: int}
	 */
	public static function decide(string $cache_root, NunezCache_Request $request, ?int $now = null): array {
		$decision = array(
			'action'  => 'ignore',
			'reason'  => '',
			'body'    => '',
			'key'     => '',
			'bytes'   => 0,
			'url'     => $request->path(),
			'blog_id' => $request->blog_id(),
		);
		if (defined('MULTISITE') && MULTISITE) {
			return $decision;
		}

		$root  = rtrim($cache_root, '/');
		$state = ( new NunezCache_State_File($root . '/state.php') )->read();
		if (empty($state['enabled']) || !empty($state['paused'])) {
			return $decision;
		}

		$reason = ( new NunezCache_Bypass() )->reason(
			$request,
			array(
				'extra_path_prefixes' => $state['extra_path_prefixes'],
				'tracking_parameters' => $state['tracking_parameters'],
			)
		);
		if ('' !== $reason) {
			$decision['action'] = 'bypass';
			$decision['reason'] = $reason;
			return $decision;
		}

		$key              = NunezCache_Key::from_request($request, $state['tracking_parameters']);
		$decision['key']  = $key;
		$entry            = ( new NunezCache_File_Store($root) )->read($key, $request->blog_id());
		$now              = null === $now ? time() : $now;
		if (null === $entry) {
			$decision['action'] = 'miss';
			return $decision;
		}
		$expires = isset($entry['meta']['expires']) ? (int) $entry['meta']['expires'] : 0;
		if ($expires <= $now) {
			$decision['action'] = 'miss';
			return $decision;
		}

		$decision['action'] = 'hit';
		$decision['body']   = $entry['body'];
		$decision['bytes']  = strlen($entry['body']);
		return $decision;
	}

	/**
	 * Response headers for a stored document.
	 *
	 * @param string $body Stored HTML. The length header uses its byte size.
	 * @return array<int, string> Header lines without a status line.
	 */
	public static function hit_headers(string $body): array {
		return array(
			'Content-Type: text/html; charset=UTF-8',
			'Cache-Control: public, max-age=0, must-revalidate',
			'X-NunezCache: HIT',
			'Content-Length: ' . (string) strlen($body),
		);
	}

	/**
	 * Send a HIT. HEAD sends headers only.
	 *
	 * @param string $body   Stored HTML.
	 * @param string $method Uppercase method.
	 */
	private static function send_hit(string $body, string $method): void {
		if (!headers_sent()) {
			http_response_code(200);
			foreach (self::hit_headers($body) as $header) {
				header($header, true);
			}
		}
		if ('HEAD' !== $method) {
			echo $body;
		}
	}

	/**
	 * Append one HIT or other result. Failures are ignored.
	 *
	 * @param string               $root      Cache root.
	 * @param array<string, mixed> $decision  Decision from decide().
	 * @param float                $started   microtime(true) when serve() began.
	 * @param string               $result    HIT, MISS, or BYPASS.
	 * @param string               $reason    Reason code, or empty.
	 */
	private static function log_result(string $root, array $decision, float $started, string $result, string $reason): void {
		$log = new NunezCache_Request_Log(rtrim($root, '/') . '/log/requests.jsonl');
		$log->append(
			array(
				'time'         => time(),
				'result'       => $result,
				'reason'       => $reason,
				'url'          => isset($decision['url']) ? (string) $decision['url'] : '',
				'milliseconds' => (int) round((microtime(true) - $started) * 1000),
				'bytes'        => isset($decision['bytes']) ? (int) $decision['bytes'] : 0,
			)
		);
	}
}
