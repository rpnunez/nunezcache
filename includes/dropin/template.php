<?php
/**
 * NunezCache drop-in
 *
 * @package NunezCache
 * Purpose: Load the runtime and call NunezCache_Early_Cache::serve().
 * License: GPL-2.0-or-later
 */

require_once __NUNEZCACHE_EARLY_FILE__;
NunezCache_Early_Cache::serve();
