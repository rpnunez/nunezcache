<?php
/**
 * Shared PHPUnit helpers.
 *
 * @package NunezCache
 * Purpose: Temporary directories and subprocess PHP for runtime tests.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Base case that deletes temporary directories after each test.
 */
abstract class NunezCache_Test_Case extends TestCase {

	/**
	 * Directories created by temp_dir(), removed in tearDown.
	 *
	 * @var array<int, string>
	 */
	private array $temp_dirs = array();

	/**
	 * Create a unique directory under the system temp path.
	 *
	 * @return string Absolute path, no trailing slash.
	 */
	protected function temp_dir(): string {
		$dir = sys_get_temp_dir() . '/nunezcache-' . bin2hex(random_bytes(6));
		mkdir($dir, 0777, true);
		$this->temp_dirs[] = $dir;
		return $dir;
	}

	/**
	 * Plugin repository root.
	 *
	 * @return string Absolute path.
	 */
	protected function plugin_root(): string {
		return dirname(__DIR__);
	}

	/**
	 * Run a PHP script in a separate process.
	 *
	 * @param string               $script Script source.
	 * @param array<int, string>   $args   Arguments after the script path.
	 * @return array{stdout: string, stderr: string, exit: int}
	 */
	protected function run_php(string $script, array $args = array()): array {
		$file = $this->temp_dir() . '/script.php';
		file_put_contents($file, $script);
		$command = array_merge(array(PHP_BINARY, $file), $args);
		$pipes   = array();
		$process = proc_open(
			$command,
			array(
				0 => array('pipe', 'r'),
				1 => array('pipe', 'w'),
				2 => array('pipe', 'w'),
			),
			$pipes
		);
		$this->assertIsResource($process);
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit = proc_close($process);
		return array(
			'stdout' => false === $stdout ? '' : $stdout,
			'stderr' => false === $stderr ? '' : $stderr,
			'exit'   => $exit,
		);
	}

	/**
	 * Delete temporary directories created by this case.
	 */
	protected function tearDown(): void {
		foreach ($this->temp_dirs as $dir) {
			$this->remove_tree($dir);
		}
		$this->temp_dirs = array();
		parent::tearDown();
	}

	/**
	 * Recursively delete a directory tree.
	 *
	 * @param string $dir Absolute path.
	 */
	private function remove_tree(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		$items = scandir($dir);
		if (false === $items) {
			return;
		}
		foreach ($items as $item) {
			if ('.' === $item || '..' === $item) {
				continue;
			}
			$path = $dir . '/' . $item;
			if (is_dir($path) && !is_link($path)) {
				$this->remove_tree($path);
			} else {
				unlink($path);
			}
		}
		rmdir($dir);
	}
}
