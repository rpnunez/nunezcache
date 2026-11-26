<?php
/**
 * Front controller stand-in.
 *
 * @package NunezCache
 * Purpose: Store a public HTML body on MISS so HTTP tests can observe HIT without WordPress.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

$content = getenv('NUNEZCACHE_CONTENT_DIR');
$early   = getenv('NUNEZCACHE_EARLY_FILE');
if (!is_string($content) || '' === $content || !is_string($early) || '' === $early) {
	http_response_code(500);
	echo 'missing fixture env';
	return;
}

if (!defined('WP_CONTENT_DIR')) {
	define('WP_CONTENT_DIR', $content);
}
require_once $early;

NunezCache_Early_Cache::serve();

$root    = WP_CONTENT_DIR . '/cache/nunezcache';
$request = NunezCache_Request::from_globals();

if (defined('NUNEZCACHE_BYPASS')) {
	header('X-NunezCache: BYPASS');
	header('Content-Type: text/plain; charset=UTF-8');
	echo 'BYPASS';
	return;
}

if (defined('NUNEZCACHE_MISS')) {
	$body    = "<!DOCTYPE html><html><body>public-post</body></html>";
	$state   = ( new NunezCache_State_File($root . '/state.php') )->read();
	$key     = defined('NUNEZCACHE_KEY') ? (string) NUNEZCACHE_KEY : NunezCache_Key::from_request($request, $state['tracking_parameters']);
	$now     = time();
	$ttl     = (int) $state['ttl'];
	$meta    = array(
		'url'            => $request->scheme() . '://' . $request->host() . $request->path(),
		'created'        => $now,
		'expires'        => $now + max(1, $ttl),
		'bytes'          => strlen($body),
		'content_hash'   => hash('sha256', $body),
		'dependency_ids' => array(
			'posts' => array( 1 ),
			'terms' => array(),
			'users' => array(),
		),
	);
	( new NunezCache_File_Store($root) )->write($key, $request->blog_id(), $body, $meta);
	$started = defined('NUNEZCACHE_STARTED_AT') ? (float) NUNEZCACHE_STARTED_AT : microtime(true);
	( new NunezCache_Request_Log($root . '/log/requests.jsonl') )->append(
		array(
			'time'         => $now,
			'result'       => 'MISS',
			'reason'       => '',
			'url'          => $request->path(),
			'milliseconds' => (int) round((microtime(true) - $started) * 1000),
			'bytes'        => strlen($body),
		)
	);
	header('X-NunezCache: MISS');
	header('Content-Type: text/html; charset=UTF-8');
	echo $body;
	return;
}

header('X-NunezCache: IGNORED');
header('Content-Type: text/plain; charset=UTF-8');
echo 'IGNORED';
