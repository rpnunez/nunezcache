<?php
/**
 * File store tests.
 *
 * @package NunezCache
 * Purpose: Prove atomic HTML writes, partial-file refusal, and purge-all.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Covers the disk store without WordPress.
 */
class FileStoreTest extends NunezCache_Test_Case {

	/**
	 * Sample metadata written beside the body.
	 *
	 * @return array<string, mixed>
	 */
	private function meta(string $body = '<html></html>'): array {
		return array(
			'url'            => 'https://example.test/hello/',
			'created'        => 100,
			'expires'        => 200,
			'bytes'          => strlen($body),
			'content_hash'   => hash('sha256', $body),
			'dependency_ids' => array(
				'posts' => array( 42 ),
				'terms' => array(),
				'users' => array(),
			),
		);
	}

	/**
	 * A 64-character key, distinct when a salt is passed.
	 */
	private function key(string $salt = 'a'): string {
		return hash('sha256', $salt);
	}

	/**
	 * Write then read returns the body and the dependency stub.
	 */
	public function test_write_and_read(): void {
		$root = $this->temp_dir();
		$store = new NunezCache_File_Store($root);
		$body  = '<!DOCTYPE html><html><body>Hi</body></html>';
		$key   = $this->key();

		$this->assertTrue($store->write($key, 1, $body, $this->meta($body)));
		$read = $store->read($key, 1);

		$this->assertIsArray($read);
		$this->assertSame($body, $read['body']);
		$this->assertSame(array( 42 ), $read['meta']['dependency_ids']['posts']);
		$html = $root . '/pages/1/' . $key . '.html';
		$this->assertFileExists($html);
		$this->assertStringContainsString('/pages/1/', $html);
		$this->assertStringNotContainsString($this->plugin_root() . '/pages', $html);
	}

	/**
	 * Rename leaves no temporary files beside the pair.
	 */
	public function test_atomic_write_leaves_no_temp_files(): void {
		$root  = $this->temp_dir();
		$store = new NunezCache_File_Store($root);
		$store->write($this->key(), 1, '<html></html>', $this->meta());

		$left = glob($root . '/pages/1/*');
		$this->assertIsArray($left);
		$this->assertCount(2, $left);
		foreach ($left as $path) {
			$this->assertDoesNotMatchRegularExpression('/\.tmp\z/', $path);
		}
	}

	/**
	 * HTML without meta, meta without HTML, an empty body, and a bad meta file are misses.
	 */
	public function test_partial_files_are_refused(): void {
		$root  = $this->temp_dir();
		$store = new NunezCache_File_Store($root);
		$key   = $this->key();
		$dir   = $root . '/pages/1';
		mkdir($dir, 0777, true);
		$html = $dir . '/' . $key . '.html';
		$meta = $dir . '/' . $key . '.meta.php';

		file_put_contents($html, '<html></html>');
		$this->assertNull($store->read($key, 1));

		unlink($html);
		file_put_contents($meta, "<?php\nreturn " . var_export($this->meta(), true) . ";\n");
		$this->assertNull($store->read($key, 1));

		file_put_contents($html, '');
		$this->assertNull($store->read($key, 1));

		file_put_contents($html, '<html></html>');
		file_put_contents($meta, "<?php\nreturn 'torn';\n");
		$this->assertNull($store->read($key, 1));

		file_put_contents($meta, "<?php\nthis is not php\n");
		$this->assertNull($store->read($key, 1));
	}

	/**
	 * Purge all deletes page and index files and leaves state.php and the log.
	 */
	public function test_purge_all_keeps_state_and_log(): void {
		$root  = $this->temp_dir();
		$store = new NunezCache_File_Store($root);
		$key   = $this->key();
		$store->write($key, 1, '<html></html>', $this->meta());
		$index = $root . '/index/1';
		mkdir($index, 0777, true);
		file_put_contents($index . '/post-42.php', "<?php\nreturn array();\n");
		file_put_contents($root . '/state.php', "<?php\nreturn array('enabled' => true);\n");
		mkdir($root . '/log', 0777, true);
		file_put_contents($root . '/log/requests.jsonl', "{}\n");

		$removed = $store->purge_all();

		$this->assertSame(3, $removed);
		$this->assertFileDoesNotExist($root . '/pages/1/' . $key . '.html');
		$this->assertFileDoesNotExist($root . '/index/1/post-42.php');
		$this->assertFileExists($root . '/state.php');
		$this->assertFileExists($root . '/log/requests.jsonl');
		$this->assertNull($store->read($key, 1));
	}

	/**
	 * Purge one key removes that pair only.
	 */
	public function test_purge_key_removes_one_pair(): void {
		$root  = $this->temp_dir();
		$store = new NunezCache_File_Store($root);
		$keep  = $this->key('keep');
		$drop  = $this->key('drop');
		$store->write($keep, 1, '<html>keep</html>', $this->meta());
		$store->write($drop, 1, '<html>drop</html>', $this->meta());

		$this->assertTrue($store->purge_key($drop, 1));
		$this->assertNull($store->read($drop, 1));
		$this->assertSame('<html>keep</html>', $store->read($keep, 1)['body']);
	}

	/**
	 * Keys that could escape the cache directory are rejected.
	 */
	public function test_rejects_unsafe_keys(): void {
		$root  = $this->temp_dir();
		$store = new NunezCache_File_Store($root);

		$this->assertFalse($store->write('../state', 1, '<html></html>', $this->meta()));
		$this->assertFalse($store->write($this->key(), 0, '<html></html>', $this->meta()));
		$this->assertNull($store->read('../state.php', 1));
		$this->assertFileDoesNotExist($root . '/state.php');
	}
}
