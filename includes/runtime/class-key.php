<?php
/**
 * Cache key.
 *
 * @package NunezCache
 * Purpose: Hash blog id, scheme, host, path, and a normalized query into one hex key.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Builds the file name used under pages/{blog_id}/.
 */
class NunezCache_Key {

	/**
	 * Hex key for a request.
	 *
	 * Tracking-parameter names are removed, then the remaining query is sorted.
	 * An empty tracking list still runs that normalization and strips nothing.
	 * Does not throw.
	 *
	 * @param NunezCache_Request $request              Request fields.
	 * @param array<int, string> $tracking_parameters  Names to drop. Case-insensitive.
	 * @return string 64-character hexadecimal SHA-256.
	 */
	public static function from_request(NunezCache_Request $request, array $tracking_parameters = array()): string {
		$parts = array(
			(string) $request->blog_id(),
			$request->scheme(),
			$request->host(),
			$request->path(),
			self::normalize_query($request->query(), $tracking_parameters),
		);
		return hash('sha256', implode("\n", $parts));
	}

	/**
	 * Query string after tracking names are removed and the rest are sorted.
	 *
	 * Does not throw. Spaces are percent-encoded.
	 *
	 * @param array<string, mixed> $query                Raw query pairs.
	 * @param array<int, string>   $tracking_parameters  Names to drop.
	 * @return string Canonical query without a leading `?`. Empty when nothing remains.
	 */
	public static function normalize_query(array $query, array $tracking_parameters): string {
		$drop = array();
		foreach ($tracking_parameters as $name) {
			$drop[ strtolower((string) $name) ] = true;
		}

		$clean = array();
		foreach ($query as $name => $value) {
			if (isset($drop[ strtolower((string) $name) ])) {
				continue;
			}
			if (is_array($value)) {
				$clean[ (string) $name ] = self::sort_query($value);
				continue;
			}
			$clean[ (string) $name ] = (string) $value;
		}
		ksort($clean);
		return http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * Sort nested query arrays so the same pairs always hash together.
	 *
	 * @param array<mixed> $query Nested query.
	 * @return array<mixed>
	 */
	private static function sort_query(array $query): array {
		ksort($query);
		foreach ($query as $name => $value) {
			if (is_array($value)) {
				$query[ $name ] = self::sort_query($value);
			}
		}
		return $query;
	}
}
