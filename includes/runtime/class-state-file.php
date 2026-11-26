<?php
/**
 * Drop-in state file.
 *
 * @package NunezCache
 * Purpose: Read and write state.php, the settings the early cache can see without the options table.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Settings the drop-in is allowed to read. The options table is an admin copy of this file.
 */
interface NunezCache_State {

	/**
	 * Current state, with every slot filled.
	 *
	 * Does not throw. A missing or unreadable file returns the defaults, which are off.
	 *
	 * @return array{enabled: bool, paused: bool, generation: int, bypass_token: string, ttl: int, extra_path_prefixes: array<int, string>, tracking_parameters: array<int, string>}
	 */
	public function read(): array;

	/**
	 * Replace state.php atomically.
	 *
	 * Does not throw.
	 *
	 * @param array<string, mixed> $state Fields to merge onto the defaults.
	 * @return bool False when the write fails.
	 */
	public function write(array $state): bool;
}

/**
 * state.php as a PHP file that returns an array.
 */
class NunezCache_State_File implements NunezCache_State {

	/**
	 * Absolute path to state.php.
	 */
	private string $path;

	/**
	 * Point at one state file.
	 *
	 * @param string $path Absolute path.
	 */
	public function __construct(string $path) {
		$this->path = $path;
	}

	/**
	 * Default slots. Caching is off. TTL is 10 hours, in seconds, and is only a safety expiry.
	 *
	 * @return array{enabled: bool, paused: bool, generation: int, bypass_token: string, ttl: int, extra_path_prefixes: array<int, string>, tracking_parameters: array<int, string>}
	 */
	public static function defaults(): array {
		return array(
			'enabled'              => false,
			'paused'               => false,
			'generation'           => 1,
			'bypass_token'         => '',
			'ttl'                  => 36000,
			'extra_path_prefixes'  => array(),
			'tracking_parameters'  => array(),
		);
	}

	/**
	 * Read state.php, or the defaults when the file is missing or not an array.
	 *
	 * @return array{enabled: bool, paused: bool, generation: int, bypass_token: string, ttl: int, extra_path_prefixes: array<int, string>, tracking_parameters: array<int, string>}
	 */
	public function read(): array {
		$data = $this->include_array($this->path);
		if (null === $data) {
			return self::defaults();
		}
		return $this->normalize(array_merge(self::defaults(), $data));
	}

	/**
	 * Write every slot. Unknown keys are dropped.
	 *
	 * @param array<string, mixed> $state Fields to merge onto the defaults.
	 * @return bool False when the directory or rename fails.
	 */
	public function write(array $state): bool {
		$normalized = $this->normalize(array_merge(self::defaults(), $state));
		$payload    = "<?php\nreturn " . var_export($normalized, true) . ";\n";
		return $this->atomic_write($this->path, $payload);
	}

	/**
	 * Force the types the early cache expects.
	 *
	 * @param array<string, mixed> $state Merged state.
	 * @return array{enabled: bool, paused: bool, generation: int, bypass_token: string, ttl: int, extra_path_prefixes: array<int, string>, tracking_parameters: array<int, string>}
	 */
	private function normalize(array $state): array {
		$token = isset($state['bypass_token']) ? (string) $state['bypass_token'] : '';
		$token = str_replace(array( "\r", "\n" ), '', $token);
		return array(
			'enabled'              => true === $state['enabled'] || 1 === $state['enabled'] || '1' === $state['enabled'],
			'paused'               => true === $state['paused'] || 1 === $state['paused'] || '1' === $state['paused'],
			'generation'           => isset($state['generation']) ? (int) $state['generation'] : 1,
			'bypass_token'         => $token,
			'ttl'                  => isset($state['ttl']) ? (int) $state['ttl'] : 36000,
			'extra_path_prefixes'  => $this->string_list($state['extra_path_prefixes'] ?? array()),
			'tracking_parameters'  => $this->string_list($state['tracking_parameters'] ?? array()),
		);
	}

	/**
	 * Keep only non-empty strings, in order.
	 *
	 * @param mixed $value Raw list.
	 * @return array<int, string>
	 */
	private function string_list(mixed $value): array {
		if (!is_array($value)) {
			return array();
		}
		$list = array();
		foreach ($value as $item) {
			if (!is_string($item) || '' === $item) {
				continue;
			}
			$list[] = $item;
		}
		return $list;
	}

	/**
	 * Include a PHP file that returns an array.
	 *
	 * Does not throw. A missing file, a parse error, or a non-array return is null.
	 *
	 * @param string $path Absolute path.
	 * @return array<string, mixed>|null
	 */
	private function include_array(string $path): ?array {
		if (!is_file($path)) {
			return null;
		}
		set_error_handler(array( $this, 'ignore_include_warning' ));
		try {
			$data = include $path;
		} catch (Throwable) {
			$data = null;
		} finally {
			restore_error_handler();
		}
		if (!is_array($data)) {
			return null;
		}
		return $data;
	}

	/**
	 * Ignore a warning raised while state.php is included.
	 *
	 * @param int    $severity Error level. Not used.
	 * @param string $message  Warning text. Not used.
	 * @param string $file     File that raised the warning. Not used.
	 * @param int    $line     Line that raised the warning. Not used.
	 * @return bool True so the warning is not displayed.
	 */
	private function ignore_include_warning(int $severity, string $message, string $file = '', int $line = 0): bool {
		unset($severity, $message, $file, $line);
		return true;
	}

	/**
	 * Write via a temp file in the same directory, then rename.
	 *
	 * @param string $path     Destination.
	 * @param string $contents Full file contents.
	 * @return bool False when the write or rename fails.
	 */
	private function atomic_write(string $path, string $contents): bool {
		$dir = dirname($path);
		if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
			return false;
		}
		$tmp     = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
		$written = file_put_contents($tmp, $contents, LOCK_EX);
		if (false === $written) {
			unlink($tmp);
			return false;
		}
		if (!rename($tmp, $path)) {
			unlink($tmp);
			return false;
		}
		return true;
	}
}
