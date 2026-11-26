<?php
/**
 * Miss capture.
 *
 * @package NunezCache
 * Purpose: Buffer a miss and store a 200 HTML document with the main post id.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH') && 'cli' !== PHP_SAPI && 'phpdbg' !== PHP_SAPI && 'cli-server' !== PHP_SAPI) {
	exit;
}

/**
 * Stores qualifying responses after WordPress boots. A HIT never reaches this class.
 */
class NunezCache_Response_Capture {

	/**
	 * Drop-in state, including TTL in seconds.
	 */
	private NunezCache_State $state;

	/**
	 * Page store.
	 */
	private NunezCache_Store $store;

	/**
	 * Request log.
	 */
	private NunezCache_Logger $log;

	/**
	 * Whether this request started an output buffer.
	 */
	private bool $buffering = false;

	/**
	 * Output-buffer level before our buffer, used to find the captured body.
	 */
	private int $level = 0;

	/**
	 * Whether finish() has already run.
	 */
	private bool $finished = false;

	/**
	 * Wire the collaborators capture uses after a miss.
	 *
	 * @param NunezCache_State  $state State file.
	 * @param NunezCache_Store  $store Page store.
	 * @param NunezCache_Logger $log   Request log.
	 */
	public function __construct(NunezCache_State $state, NunezCache_Store $store, NunezCache_Logger $log) {
		$this->state = $state;
		$this->store = $store;
		$this->log   = $log;
	}

	/**
	 * Register the buffer and the shutdown writer.
	 */
	public function hooks(): void {
		add_action('init', array( $this, 'start_buffer' ), 0);
		add_action('shutdown', array( $this, 'finish' ), PHP_INT_MAX);
	}

	/**
	 * Start a buffer and send the MISS header when the early cache asked for a miss.
	 *
	 * Logged-in requests are not buffered. The header is removed later when the body is not stored.
	 */
	public function start_buffer(): void {
		if (!defined('NUNEZCACHE_MISS') || !NUNEZCACHE_MISS || $this->buffering) {
			return;
		}
		if ($this->has_logged_in_cookie()) {
			return;
		}
		if (function_exists('is_user_logged_in') && is_user_logged_in()) {
			return;
		}
		$this->buffering = true;
		$this->level     = ob_get_level();
		if (!headers_sent()) {
			header('X-NunezCache: MISS');
		}
		ob_start();
	}

	/**
	 * Store a qualifying body and append the log row.
	 *
	 * The `nunezcache_bypass` filter runs here. It cannot change a HIT, because that request already exited.
	 */
	public function finish(): void {
		if ($this->finished) {
			return;
		}
		$this->finished = true;
		if (!defined('NUNEZCACHE_MISS') && !defined('NUNEZCACHE_BYPASS')) {
			return;
		}

		$body    = $this->captured_body();
		$request = NunezCache_Request::from_globals();
		$early   = defined('NUNEZCACHE_BYPASS') ? (string) constant('NUNEZCACHE_BYPASS') : '';
		$flag    = isset($GLOBALS['nunezcache_do_not_cache']) ? (string) $GLOBALS['nunezcache_do_not_cache'] : '';
		$reason  = '' !== $early ? $early : $flag;
		if (function_exists('apply_filters')) {
			$filtered = apply_filters('nunezcache_bypass', $reason, $request->to_array());
			if (is_string($filtered)) {
				$reason = $filtered;
			}
		}

		$status   = http_response_code();
		$code     = is_int($status) ? $status : 200;
		$decision = $this->qualify(
			$code,
			$this->content_type(),
			$body,
			$reason,
			'',
			$this->has_logged_in_cookie(),
			$this->sets_logged_in_cookie()
		);

		if ($this->buffering && !$decision['store'] && !headers_sent()) {
			header_remove('X-NunezCache');
		}
		if ($decision['store'] && !$this->store_body($request, $body)) {
			$decision['store']  = false;
			$decision['result'] = 'BYPASS';
			$decision['reason'] = 'not_stored';
		}
		$this->log->append(
			array(
				'time'         => time(),
				'result'       => $decision['result'],
				'reason'       => $decision['reason'],
				'url'          => $request->path(),
				'milliseconds' => $this->elapsed_milliseconds(),
				'bytes'        => strlen($body),
			)
		);
	}

	/**
	 * Whether a completed response may be written, and how to log it.
	 *
	 * Does not throw. A non-empty reason means do not store.
	 *
	 * @param int    $status_code           HTTP status.
	 * @param string $content_type          Content-Type header value, or empty.
	 * @param string $body                  Response body.
	 * @param string $early_reason          Bypass reason from the early cache or the filter.
	 * @param string $do_not_cache_reason   Reason passed to nunezcache_do_not_cache(), or empty.
	 * @param bool   $logged_in_cookie      Whether a logged-in cookie is present.
	 * @param bool   $sets_logged_in_cookie Whether the response sets a logged-in cookie.
	 * @return array{store: bool, result: string, reason: string}
	 */
	public function qualify(
		int $status_code,
		string $content_type,
		string $body,
		string $early_reason,
		string $do_not_cache_reason,
		bool $logged_in_cookie,
		bool $sets_logged_in_cookie
	): array {
		if ('' !== $early_reason) {
			return $this->bypass($early_reason);
		}
		if ('' !== $do_not_cache_reason) {
			return $this->bypass($do_not_cache_reason);
		}
		if ($logged_in_cookie || $sets_logged_in_cookie) {
			return $this->bypass('logged_in_cookie');
		}
		if (200 !== $status_code) {
			return $this->bypass('status');
		}
		if ('' !== $content_type && false === stripos($content_type, 'text/html')) {
			return $this->bypass('not_html');
		}
		if (1 !== preg_match('/<!DOCTYPE\s+html|<html\b/i', $body)) {
			return $this->bypass('not_html');
		}
		return array(
			'store'  => true,
			'result' => 'MISS',
			'reason' => '',
		);
	}

	/**
	 * A bypass row.
	 *
	 * @param string $reason Reason code.
	 * @return array{store: bool, result: string, reason: string}
	 */
	private function bypass(string $reason): array {
		return array(
			'store'  => false,
			'result' => 'BYPASS',
			'reason' => $reason,
		);
	}

	/**
	 * Body held by our buffer. The buffer stays open so the client still receives it.
	 *
	 * @return string Captured body, or empty when this request was not buffered.
	 */
	private function captured_body(): string {
		if (!$this->buffering || ob_get_level() <= $this->level) {
			return '';
		}
		return (string) ob_get_contents();
	}

	/**
	 * Write HTML and a meta file whose dependency list is the main post id only.
	 *
	 * @param NunezCache_Request $request Current request.
	 * @param string             $body    HTML document.
	 * @return bool False when the store rejects the write.
	 */
	private function store_body(NunezCache_Request $request, string $body): bool {
		$state = $this->state->read();
		$ttl   = (int) $state['ttl'];
		if ($ttl < 1) {
			$ttl = 36000;
		}
		$key   = defined('NUNEZCACHE_KEY') ? (string) constant('NUNEZCACHE_KEY') : NunezCache_Key::from_request($request, $state['tracking_parameters']);
		$posts = array();
		if (function_exists('is_singular') && is_singular() && function_exists('get_queried_object_id')) {
			$id = (int) get_queried_object_id();
			if ($id > 0) {
				$posts[] = $id;
			}
		}
		$now  = time();
		$meta = array(
			'url'            => $request->scheme() . '://' . $request->host() . $request->path(),
			'created'        => $now,
			'expires'        => $now + $ttl,
			'bytes'          => strlen($body),
			'content_hash'   => hash('sha256', $body),
			'dependency_ids' => array(
				'posts' => $posts,
				'terms' => array(),
				'users' => array(),
			),
		);
		return $this->store->write($key, $request->blog_id(), $body, $meta);
	}

	/**
	 * Milliseconds since the early cache started, or since this method when that timestamp is absent.
	 *
	 * @return int Elapsed milliseconds.
	 */
	private function elapsed_milliseconds(): int {
		$started = defined('NUNEZCACHE_STARTED_AT') ? (float) constant('NUNEZCACHE_STARTED_AT') : microtime(true);
		$elapsed = (int) round((microtime(true) - $started) * 1000);
		return $elapsed < 0 ? 0 : $elapsed;
	}

	/**
	 * Whether the request sent a logged-in cookie.
	 *
	 * @return bool True when a wordpress_logged_in_ cookie is present.
	 */
	private function has_logged_in_cookie(): bool {
		foreach (array_keys($_COOKIE) as $name) {
			if (str_starts_with((string) $name, 'wordpress_logged_in_')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the response sets a logged-in cookie.
	 *
	 * @return bool True when a Set-Cookie header carries wordpress_logged_in_.
	 */
	private function sets_logged_in_cookie(): bool {
		if (!function_exists('headers_list')) {
			return false;
		}
		foreach (headers_list() as $header) {
			if (0 === stripos($header, 'Set-Cookie:') && false !== stripos($header, 'wordpress_logged_in_')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Current Content-Type header value, without the header name.
	 *
	 * @return string Type, or empty when the header was not set.
	 */
	private function content_type(): string {
		if (!function_exists('headers_list')) {
			return '';
		}
		$type = '';
		foreach (headers_list() as $header) {
			if (0 === stripos($header, 'Content-Type:')) {
				$type = trim(substr($header, strlen('Content-Type:')));
			}
		}
		return $type;
	}
}
