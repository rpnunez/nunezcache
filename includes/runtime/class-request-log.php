<?php
/**
 * Request ring buffer.
 *
 * @package NunezCache
 * Purpose: Append HIT, MISS, and BYPASS rows and return the newest ones. Cap is 200.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Request log the early cache and the admin screen share.
 */
interface NunezCache_Logger {

	/**
	 * Append one row. Bodies, cookies, and the bypass token are dropped.
	 *
	 * Does not throw. A failed write is ignored so a log problem cannot turn a HIT into an error.
	 *
	 * @param array<string, mixed> $row Row fields. Only time, result, reason, url, milliseconds, and bytes are kept.
	 */
	public function append(array $row): void;

	/**
	 * Newest rows first.
	 *
	 * @param int $limit Maximum rows to return. The admin screen asks for 50.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent(int $limit = 50): array;
}

/**
 * JSONL file capped at 200 rows.
 */
class NunezCache_Request_Log implements NunezCache_Logger {

	/**
	 * Maximum stored rows. The admin screen shows 50 of these.
	 */
	private const CAP = 200;

	/**
	 * Absolute path to requests.jsonl.
	 */
	private string $path;

	/**
	 * Point at one log file.
	 *
	 * @param string $path Absolute path.
	 */
	public function __construct(string $path) {
		$this->path = $path;
	}

	/**
	 * Append one safe row and drop the oldest when the cap is exceeded.
	 *
	 * @param array<string, mixed> $row Proposed row.
	 */
	public function append(array $row): void {
		$clean = $this->clean($row);
		$dir   = dirname($this->path);
		if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
			return;
		}
		$handle = fopen($this->path, 'c+');
		if (false === $handle) {
			return;
		}
		try {
			if (!flock($handle, LOCK_EX)) {
				return;
			}
			$lines    = $this->read_raw($handle);
			$encoded  = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if (!is_string($encoded)) {
				return;
			}
			$lines[] = $encoded;
			if (count($lines) > self::CAP) {
				$lines = array_slice($lines, -self::CAP);
			}
			ftruncate($handle, 0);
			rewind($handle);
			fwrite($handle, implode("\n", $lines) . "\n");
			fflush($handle);
		} finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	/**
	 * Return the newest rows.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent(int $limit = 50): array {
		if ($limit < 1 || !is_file($this->path)) {
			return array();
		}
		$handle = fopen($this->path, 'rb');
		if (false === $handle) {
			return array();
		}
		$rows = array();
		try {
			if (!flock($handle, LOCK_SH)) {
				return array();
			}
			foreach ($this->read_raw($handle) as $line) {
				$decoded = json_decode($line, true);
				if (is_array($decoded)) {
					$rows[] = $decoded;
				}
			}
		} finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
		return array_reverse(array_slice($rows, -$limit));
	}

	/**
	 * Keep the public columns and drop anything that could be a secret.
	 *
	 * @param array<string, mixed> $row Proposed row.
	 * @return array{time: int, result: string, reason: string, url: string, milliseconds: int, bytes: int}
	 */
	private function clean(array $row): array {
		$result = isset($row['result']) ? (string) $row['result'] : '';
		if ('HIT' !== $result && 'MISS' !== $result && 'BYPASS' !== $result) {
			$result = 'BYPASS';
		}
		$reason = isset($row['reason']) ? strtolower((string) $row['reason']) : '';
		$reason = preg_replace('/[^a-z0-9_]/', '', $reason);
		if (!is_string($reason)) {
			$reason = '';
		}
		if (strlen($reason) > 40) {
			$reason = substr($reason, 0, 40);
		}
		$url = isset($row['url']) ? (string) $row['url'] : '';
		$url = str_replace(array( "\r", "\n" ), '', $url);
		if (strlen($url) > 500) {
			$url = substr($url, 0, 500);
		}
		$milliseconds = isset($row['milliseconds']) ? (int) $row['milliseconds'] : 0;
		if ($milliseconds < 0) {
			$milliseconds = 0;
		}
		$bytes = isset($row['bytes']) ? (int) $row['bytes'] : 0;
		if ($bytes < 0) {
			$bytes = 0;
		}
		return array(
			'time'         => isset($row['time']) ? (int) $row['time'] : time(),
			'result'       => $result,
			'reason'       => $reason,
			'url'          => $url,
			'milliseconds' => $milliseconds,
			'bytes'        => $bytes,
		);
	}

	/**
	 * Non-empty lines currently in the open handle.
	 *
	 * @param resource $handle Open file positioned anywhere.
	 * @return array<int, string>
	 */
	private function read_raw($handle): array {
		rewind($handle);
		$lines = array();
		while (false !== ($line = fgets($handle))) {
			$line = trim($line);
			if ('' !== $line) {
				$lines[] = $line;
			}
		}
		return $lines;
	}
}
