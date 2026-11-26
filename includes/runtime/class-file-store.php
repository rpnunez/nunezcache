<?php
/**
 * Disk store for cached HTML.
 *
 * @package NunezCache
 * Purpose: Atomically read and write page files under the cache root, and purge them.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Page storage. Callers use these four operations and do not unlink paths themselves.
 */
interface NunezCache_Store {

	/**
	 * Read one HTML and meta pair.
	 *
	 * Does not throw. Null means missing, partial, empty, or unreadable.
	 * Expiry is the caller's decision.
	 *
	 * @param string $key     64-character hex key.
	 * @param int    $blog_id Blog id directory.
	 * @return array{body: string, meta: array<string, mixed>}|null
	 */
	public function read(string $key, int $blog_id): ?array;

	/**
	 * Write the HTML file and its meta file.
	 *
	 * Does not throw.
	 *
	 * @param string               $key     64-character hex key.
	 * @param int                  $blog_id Blog id directory.
	 * @param string               $body    Full HTML document.
	 * @param array<string, mixed> $meta    Metadata returned by the meta file.
	 * @return bool False when the key is unsafe or the write fails.
	 */
	public function write(string $key, int $blog_id, string $body, array $meta): bool;

	/**
	 * Delete one HTML and meta pair.
	 *
	 * Does not throw.
	 *
	 * @param string $key     64-character hex key.
	 * @param int    $blog_id Blog id directory.
	 * @return bool False when the key is unsafe or nothing was deleted.
	 */
	public function purge_key(string $key, int $blog_id): bool;

	/**
	 * Delete page and index files. Leaves state.php and the request log.
	 *
	 * @return int Number of files removed.
	 */
	public function purge_all(): int;
}

/**
 * Files under {root}/pages/{blog_id}/{key}.html and a sibling meta PHP file.
 */
class NunezCache_File_Store implements NunezCache_Store {

	/**
	 * Absolute cache root. Page files live in pages/ under this directory.
	 */
	private string $root;

	/**
	 * Bind the store to one cache root.
	 *
	 * @param string $root Absolute directory. This is not the plugin directory.
	 */
	public function __construct(string $root) {
		$this->root = rtrim($root, '/');
	}

	/**
	 * Read a complete pair. An empty body or a meta file that does not return an array is a miss.
	 *
	 * @param string $key     64-character hex key.
	 * @param int    $blog_id Blog id directory.
	 * @return array{body: string, meta: array<string, mixed>}|null
	 */
	public function read(string $key, int $blog_id): ?array {
		$paths = $this->pair($key, $blog_id);
		if (null === $paths || !is_file($paths['html']) || !is_file($paths['meta'])) {
			return null;
		}
		$body = file_get_contents($paths['html']);
		if (!is_string($body) || '' === $body) {
			return null;
		}
		$meta = $this->include_array($paths['meta']);
		if (null === $meta) {
			return null;
		}
		return array(
			'body' => $body,
			'meta' => $meta,
		);
	}

	/**
	 * Write HTML, then meta, each via rename in the same directory.
	 *
	 * @param string               $key     64-character hex key.
	 * @param int                  $blog_id Blog id directory.
	 * @param string               $body    Full HTML document.
	 * @param array<string, mixed> $meta    Metadata array.
	 * @return bool False when the key is unsafe or a rename fails.
	 */
	public function write(string $key, int $blog_id, string $body, array $meta): bool {
		$paths = $this->pair($key, $blog_id);
		if (null === $paths || '' === $body) {
			return false;
		}
		if (!$this->atomic_write($paths['html'], $body)) {
			return false;
		}
		$payload = "<?php\nreturn " . var_export($meta, true) . ";\n";
		return $this->atomic_write($paths['meta'], $payload);
	}

	/**
	 * Unlink one pair when the key stays inside the cache root.
	 *
	 * @param string $key     64-character hex key.
	 * @param int    $blog_id Blog id directory.
	 * @return bool True when at least one file was removed.
	 */
	public function purge_key(string $key, int $blog_id): bool {
		$paths = $this->pair($key, $blog_id);
		if (null === $paths) {
			return false;
		}
		$removed = false;
		if (is_file($paths['html']) && unlink($paths['html'])) {
			$removed = true;
		}
		if (is_file($paths['meta']) && unlink($paths['meta'])) {
			$removed = true;
		}
		return $removed;
	}

	/**
	 * Remove pages/ and index/ and leave state.php and log/.
	 *
	 * @return int Files removed.
	 */
	public function purge_all(): int {
		return $this->delete_tree($this->root . '/pages') + $this->delete_tree($this->root . '/index');
	}

	/**
	 * Safe paths for one pair, or null when the key or blog id could escape the root.
	 *
	 * @param string $key     Proposed key.
	 * @param int    $blog_id Proposed blog id.
	 * @return array{html: string, meta: string, dir: string}|null
	 */
	private function pair(string $key, int $blog_id): ?array {
		if (1 !== preg_match('/\A[a-f0-9]{64}\z/', $key) || $blog_id < 1) {
			return null;
		}
		$dir = $this->root . '/pages/' . $blog_id;
		return array(
			'dir'  => $dir,
			'html' => $dir . '/' . $key . '.html',
			'meta' => $dir . '/' . $key . '.meta.php',
		);
	}

	/**
	 * Include a meta file. Parse errors and non-arrays are misses.
	 *
	 * @param string $path Absolute meta path.
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
	 * Ignore a warning raised while a meta file is included.
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
			if (is_file($tmp)) {
				unlink($tmp);
			}
			return false;
		}
		if (!rename($tmp, $path)) {
			unlink($tmp);
			return false;
		}
		return true;
	}

	/**
	 * Delete files under a directory and then the directory. The count is files only.
	 *
	 * @param string $directory Absolute directory.
	 * @return int Files removed.
	 */
	private function delete_tree(string $directory): int {
		if (!is_dir($directory) || is_link($directory)) {
			return 0;
		}
		$count = 0;
		$items = scandir($directory);
		if (false === $items) {
			return 0;
		}
		foreach ($items as $item) {
			if ('.' === $item || '..' === $item) {
				continue;
			}
			$path = $directory . '/' . $item;
			if (is_link($path) || is_file($path)) {
				if (unlink($path)) {
					++$count;
				}
				continue;
			}
			if (is_dir($path)) {
				$count += $this->delete_tree($path);
			}
		}
		rmdir($directory);
		return $count;
	}
}
