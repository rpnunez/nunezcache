<?php
/**
 * Bypass reasons for slice 1.
 *
 * @package NunezCache
 * Purpose: Decide whether a request may be stored. Slice 1 checks method, logged-in cookie, and admin paths.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Narrow bypass list. Later slices add reasons inside reason() without a new call site.
 */
class NunezCache_Bypass {

	/**
	 * Reason the request must not be stored, or an empty string when it may.
	 *
	 * Slice 1 reasons are `method`, `logged_in_cookie`, and `admin`.
	 * `extra_path_prefixes` is accepted and not applied yet.
	 * Does not throw.
	 *
	 * @param NunezCache_Request   $request Request to judge.
	 * @param array<string, mixed> $rules   Slots reserved for later slices, including `extra_path_prefixes`.
	 * @return string Reason code, or `''` when the request may be stored.
	 */
	public function reason(NunezCache_Request $request, array $rules): string {
		// Slice 1 does not apply $rules['extra_path_prefixes']. The argument stays for later slices.
		$method = $request->method();
		if ('GET' !== $method && 'HEAD' !== $method) {
			return 'method';
		}

		foreach (array_keys($request->cookies()) as $name) {
			if (str_starts_with((string) $name, 'wordpress_logged_in_')) {
				return 'logged_in_cookie';
			}
		}

		if ($this->is_admin_path($request->path())) {
			return 'admin';
		}

		return '';
	}

	/**
	 * Whether the path is wp-admin or wp-login.php, including a subdirectory install.
	 *
	 * @param string $path URL path.
	 * @return bool True when the request is an admin path.
	 */
	private function is_admin_path(string $path): bool {
		$path = '/' . ltrim($path, '/');
		if (1 === preg_match('#/wp-admin(/|$)#', $path)) {
			return true;
		}
		if (1 === preg_match('#/wp-login\.php$#', $path)) {
			return true;
		}
		return false;
	}
}
