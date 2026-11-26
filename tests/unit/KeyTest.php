<?php
/**
 * Cache key tests.
 *
 * @package NunezCache
 * Purpose: Prove the hex key includes blog, scheme, host, path, and a normalized query.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Covers hashing and query normalization.
 */
class KeyTest extends NunezCache_Test_Case {

	/**
	 * Build a request with the fields a key cares about.
	 *
	 * @param array<string, mixed> $query Query before tracking parameters are removed.
	 */
	private function request(array $query = array(), int $blog_id = 1, string $scheme = 'https', string $host = 'example.test', string $path = '/hello/'): NunezCache_Request {
		return new NunezCache_Request('GET', $scheme, $host, $path, $query, array(), $blog_id);
	}

	/**
	 * The same input always hashes to the same 64-character hex key.
	 */
	public function test_same_request_same_key(): void {
		$first  = NunezCache_Key::from_request($this->request(array( 'a' => '1' )));
		$second = NunezCache_Key::from_request($this->request(array( 'a' => '1' )));

		$this->assertSame($first, $second);
		$this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first);
	}

	/**
	 * Query parameter order does not fork the key.
	 */
	public function test_query_order_is_normalized(): void {
		$left  = NunezCache_Key::from_request($this->request(array( 'b' => '2', 'a' => '1' )));
		$right = NunezCache_Key::from_request($this->request(array( 'a' => '1', 'b' => '2' )));

		$this->assertSame($left, $right);
	}

	/**
	 * A listed tracking parameter is removed before the hash.
	 */
	public function test_listed_tracking_parameter_is_stripped(): void {
		$bare = NunezCache_Key::from_request($this->request(), array( 'utm_source' ));
		$utm  = NunezCache_Key::from_request(
			$this->request(array( 'utm_source' => 'x' )),
			array( 'UTM_SOURCE' )
		);
		$kept = NunezCache_Key::from_request(
			$this->request(array( 'utm_source' => 'x', 'ref' => '1' )),
			array( 'utm_source' )
		);

		$this->assertSame($bare, $utm);
		$this->assertNotSame($bare, $kept);
	}

	/**
	 * Slice 1 ships an empty tracking list, so utm_source still changes the key.
	 */
	public function test_empty_tracking_list_strips_nothing(): void {
		$bare = NunezCache_Key::from_request($this->request(), array());
		$utm  = NunezCache_Key::from_request($this->request(array( 'utm_source' => 'x' )), array());

		$this->assertNotSame($bare, $utm);
	}

	/**
	 * Scheme, host, path, and blog id are part of the hash.
	 */
	public function test_scheme_host_path_and_blog_change_the_key(): void {
		$base = NunezCache_Key::from_request($this->request());

		$this->assertNotSame($base, NunezCache_Key::from_request($this->request(array(), 1, 'http')));
		$this->assertNotSame($base, NunezCache_Key::from_request($this->request(array(), 1, 'https', 'other.test')));
		$this->assertNotSame($base, NunezCache_Key::from_request($this->request(array(), 1, 'https', 'example.test', '/other/')));
		$this->assertNotSame($base, NunezCache_Key::from_request($this->request(array(), 2)));
	}

	/**
	 * Normalization sorts the remaining pairs and percent-encodes them.
	 */
	public function test_normalize_query_sorts_and_drops_tracking_names(): void {
		$normalized = NunezCache_Key::normalize_query(
			array(
				'b'          => '2',
				'utm_source' => 'x',
				'a'          => '1 2',
			),
			array( 'utm_source' )
		);

		$this->assertSame('a=1%202&b=2', $normalized);
	}
}
