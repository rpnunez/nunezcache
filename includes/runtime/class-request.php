<?php
/**
 * HTTP request value for the cache key and bypass checks.
 *
 * @package NunezCache
 * Purpose: Read the request from superglobals without loading WordPress.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Immutable view of the fields the early cache is allowed to see.
 */
class NunezCache_Request {

	/**
	 * Uppercase HTTP method, for example `GET`.
	 */
	private string $method;

	/**
	 * `http` or `https`.
	 */
	private string $scheme;

	/**
	 * Host header, lowercased, including a non-default port when present.
	 */
	private string $host;

	/**
	 * URL path, always starting with `/`.
	 */
	private string $path;

	/**
	 * Query pairs before tracking parameters are removed.
	 *
	 * @var array<string, mixed>
	 */
	private array $query;

	/**
	 * Cookie name to value. Values are never written to the request log.
	 *
	 * @var array<string, string>
	 */
	private array $cookies;

	/**
	 * Blog id included in the cache key. The early path uses 1 on a single site.
	 */
	private int $blog_id;

	/**
	 * Store the pieces of one request.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $scheme  `http` or `https`.
	 * @param string               $host    Host header.
	 * @param string               $path    URL path.
	 * @param array<string, mixed> $query   Query pairs.
	 * @param array<string, string> $cookies Cookie name to value.
	 * @param int                  $blog_id Blog id. Single-site callers pass 1.
	 */
	public function __construct(
		string $method,
		string $scheme,
		string $host,
		string $path,
		array $query,
		array $cookies,
		int $blog_id
	) {
		$this->method  = strtoupper($method);
		$this->scheme  = 'https' === strtolower($scheme) ? 'https' : 'http';
		$this->host    = strtolower(str_replace(array( "\r", "\n" ), '', $host));
		$this->path    = '' === $path ? '/' : $path;
		$this->query   = $query;
		$this->cookies = $cookies;
		$this->blog_id = $blog_id;
	}

	/**
	 * Build a request from the current PHP superglobals.
	 *
	 * Scheme detection follows the same HTTPS and port checks core uses.
	 * Blog id is 1 because this path must not ask WordPress which site is current.
	 *
	 * @return self
	 */
	public static function from_globals(): self {
		$method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
		$https  = false;
		if (isset($_SERVER['HTTPS'])) {
			$flag = strtolower((string) $_SERVER['HTTPS']);
			if ('on' === $flag || '1' === $flag) {
				$https = true;
			}
		} elseif (isset($_SERVER['SERVER_PORT']) && '443' === (string) $_SERVER['SERVER_PORT']) {
			$https = true;
		}

		$host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : 'localhost';
		$host = str_replace(array( "\r", "\n" ), '', $host);
		if ('' === $host) {
			$host = 'localhost';
		}

		$uri  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$path = parse_url($uri, PHP_URL_PATH);
		if (!is_string($path) || '' === $path) {
			$path = '/';
		}
		if ('/' !== $path[0]) {
			$path = '/' . $path;
		}

		$query = array();
		$raw   = parse_url($uri, PHP_URL_QUERY);
		if (is_string($raw) && '' !== $raw) {
			parse_str($raw, $query);
		}

		$cookies = array();
		foreach ($_COOKIE as $name => $value) {
			if (is_array($value)) {
				continue;
			}
			$cookies[ (string) $name ] = (string) $value;
		}

		return new self($method, $https ? 'https' : 'http', $host, $path, $query, $cookies, 1);
	}

	/**
	 * HTTP method.
	 *
	 * @return string Uppercase method.
	 */
	public function method(): string {
		return $this->method;
	}

	/**
	 * URL scheme.
	 *
	 * @return string `http` or `https`.
	 */
	public function scheme(): string {
		return $this->scheme;
	}

	/**
	 * Host header.
	 *
	 * @return string Lowercased host.
	 */
	public function host(): string {
		return $this->host;
	}

	/**
	 * URL path.
	 *
	 * @return string Path beginning with `/`.
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Query pairs before normalization.
	 *
	 * @return array<string, mixed>
	 */
	public function query(): array {
		return $this->query;
	}

	/**
	 * Cookies, including values. Callers must not log the values.
	 *
	 * @return array<string, string>
	 */
	public function cookies(): array {
		return $this->cookies;
	}

	/**
	 * Blog id stored in the cache key.
	 *
	 * @return int Positive site id. The early path returns 1.
	 */
	public function blog_id(): int {
		return $this->blog_id;
	}

	/**
	 * Request fields for the bypass filter. Cookie values are omitted.
	 *
	 * The drop-in does not call the filter. Capture does, after WordPress boots,
	 * and a HIT has already exited by then.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'method'       => $this->method,
			'scheme'       => $this->scheme,
			'host'         => $this->host,
			'path'         => $this->path,
			'query'        => $this->query,
			'cookie_names' => array_keys($this->cookies),
			'blog_id'      => $this->blog_id,
		);
	}
}
