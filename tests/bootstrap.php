<?php
/**
 * PHPUnit bootstrap.
 *
 * @package NunezCache
 * Purpose: Load the runtime classes with no WordPress install.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

$nunezcache_root = dirname(__DIR__);

$nunezcache_runtime = array(
	'includes/runtime/class-state-file.php',
	'includes/runtime/class-request.php',
	'includes/runtime/class-bypass.php',
	'includes/runtime/class-key.php',
	'includes/runtime/class-file-store.php',
	'includes/runtime/class-request-log.php',
	'includes/runtime/class-early-cache.php',
);

foreach ($nunezcache_runtime as $nunezcache_file) {
	$nunezcache_path = $nunezcache_root . '/' . $nunezcache_file;
	if (is_file($nunezcache_path)) {
		require_once $nunezcache_path;
	}
}

require_once __DIR__ . '/class-test-case.php';
