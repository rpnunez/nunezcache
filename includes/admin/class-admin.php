<?php
/**
 * Admin controller.
 *
 * @package NunezCache
 * Purpose: Register the menu and handle enable, pause, purge all, and the wp-config consent.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH') && 'cli' !== PHP_SAPI && 'phpdbg' !== PHP_SAPI && 'cli-server' !== PHP_SAPI) {
	exit;
}

/**
 * Status actions. Deletes go through the store. The screen only renders.
 */
class NunezCache_Admin {

	/**
	 * Drop-in state.
	 */
	private NunezCache_State $state;

	/**
	 * Page store.
	 */
	private NunezCache_Store $store;

	/**
	 * Request log.
	 */
	private NunezCache_Logger $log;

	/**
	 * Drop-in and wp-config writer.
	 */
	private NunezCache_Dropin_Installer $installer;

	/**
	 * Status markup.
	 */
	private NunezCache_Status_Screen $screen;

	/**
	 * Wire the status screen collaborators.
	 *
	 * @param NunezCache_State            $state     State file.
	 * @param NunezCache_Store            $store     Page store.
	 * @param NunezCache_Logger           $log       Request log.
	 * @param NunezCache_Dropin_Installer $installer Drop-in installer.
	 * @param NunezCache_Status_Screen    $screen    Status markup.
	 */
	public function __construct(
		NunezCache_State $state,
		NunezCache_Store $store,
		NunezCache_Logger $log,
		NunezCache_Dropin_Installer $installer,
		NunezCache_Status_Screen $screen
	) {
		$this->state     = $state;
		$this->store     = $store;
		$this->log       = $log;
		$this->installer = $installer;
		$this->screen    = $screen;
	}

	/**
	 * Register the menu and the POST handlers.
	 */
	public function hooks(): void {
		add_action('admin_menu', array( $this, 'menu' ));
		add_action('admin_post_nunezcache_enable', array( $this, 'handle_enable' ));
		add_action('admin_post_nunezcache_pause', array( $this, 'handle_pause' ));
		add_action('admin_post_nunezcache_purge_all', array( $this, 'handle_purge_all' ));
		add_action('admin_post_nunezcache_allow_wp_config', array( $this, 'handle_allow_wp_config' ));
		add_action('admin_post_nunezcache_restore_dropin', array( $this, 'handle_restore_dropin' ));
	}

	/**
	 * Top-level NunezCache menu.
	 */
	public function menu(): void {
		add_menu_page(
			__('NunezCache', 'nunezcache'),
			__('NunezCache', 'nunezcache'),
			'manage_options',
			'nunezcache',
			array( $this, 'render_page' ),
			'dashicons-performance',
			81
		);
	}

	/**
	 * Render Status for a user who can manage options.
	 */
	public function render_page(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Sorry, you are not allowed to manage NunezCache.', 'nunezcache'), '', array( 'response' => 403 ));
		}
		$state    = $this->state->read();
		$enabled  = !empty($state['enabled']);
		$paused   = !empty($state['paused']);
		$wp_cache = defined('WP_CACHE') && WP_CACHE;
		$writable = $this->directory_is_writable();
		$notice   = isset($_GET['nunezcache_notice']) ? sanitize_key(wp_unslash((string) $_GET['nunezcache_notice'])) : '';
		$count    = isset($_GET['nunezcache_count']) ? absint(wp_unslash((string) $_GET['nunezcache_count'])) : 0;

		$this->screen->render(
			array(
				'status_word'     => self::status_word($paused, $enabled, $wp_cache),
				'rows'            => $writable ? $this->log->recent(50) : array(),
				'writable'        => $writable,
				'cache_path'      => NunezCache_Plugin::cache_root(),
				'php_user'        => $this->php_user(),
				'multisite'       => function_exists('is_multisite') && is_multisite(),
				'wp_cache'        => $wp_cache,
				'config_writable' => $this->installer->config_is_writable(),
				'dropin_active'   => $this->installer->is_active(),
				'enabled'         => $enabled,
				'paused'          => $paused,
				'notice'          => $notice,
				'purge_count'     => $count,
			)
		);
	}

	/**
	 * Enable caching when the directory is writable, the site is not multisite, and WP_CACHE is true.
	 *
	 * Does not turn the cache on when WP_CACHE is missing, and does not replace a foreign drop-in.
	 */
	public function handle_enable(): void {
		$this->authorize('nunezcache_enable');
		$reason = self::enable_block_reason(
			function_exists('is_multisite') && is_multisite(),
			$this->directory_is_writable(),
			defined('WP_CACHE') && WP_CACHE
		);
		if ('' !== $reason) {
			$this->redirect($reason);
		}
		if (!$this->installer->install()) {
			$this->redirect($this->installer->is_foreign() ? 'foreign_dropin' : 'dropin_failed');
		}
		$this->persist(true, false);
		$this->redirect('enabled');
	}

	/**
	 * Pause serving and storing. Stored HTML is kept and the drop-in stays in place.
	 */
	public function handle_pause(): void {
		$this->authorize('nunezcache_pause');
		$this->persist(false, true);
		$this->redirect('paused');
	}

	/**
	 * Delete page and index files and leave state.php.
	 */
	public function handle_purge_all(): void {
		$this->authorize('nunezcache_purge_all');
		$count = $this->store->purge_all();
		do_action('nunezcache_purged', array(), 'manual');
		$this->redirect('purged', array( 'nunezcache_count' => $count ));
	}

	/**
	 * Write the WP_CACHE define. This does not enable the cache.
	 */
	public function handle_allow_wp_config(): void {
		$this->authorize('nunezcache_allow_wp_config');
		if (function_exists('is_multisite') && is_multisite()) {
			$this->redirect('multisite');
		}
		$this->redirect($this->installer->allow_wp_config_edit() ? 'wp_config_updated' : 'wp_config_failed');
	}

	/**
	 * Write the drop-in again. Refuses a foreign file and refuses multisite.
	 */
	public function handle_restore_dropin(): void {
		$this->authorize('nunezcache_restore_dropin');
		if (function_exists('is_multisite') && is_multisite()) {
			$this->redirect('multisite');
		}
		if (!$this->installer->install()) {
			$this->redirect($this->installer->is_foreign() ? 'foreign_dropin' : 'dropin_failed');
		}
		$this->redirect('dropin_restored');
	}

	/**
	 * Status word. Missing WP_CACHE is Off even when the state file says enabled.
	 *
	 * @param bool $paused   State file paused flag.
	 * @param bool $enabled  State file enabled flag.
	 * @param bool $wp_cache Whether WP_CACHE is true in this request.
	 * @return string Off, On, or Paused.
	 */
	public static function status_word(bool $paused, bool $enabled, bool $wp_cache): string {
		if ($paused) {
			return 'Paused';
		}
		if ($enabled && $wp_cache) {
			return 'On';
		}
		return 'Off';
	}

	/**
	 * Why enable must stop, or an empty string when it may write the drop-in.
	 *
	 * Does not throw.
	 *
	 * @param bool $is_multisite Multisite network.
	 * @param bool $writable     Cache directory is writable.
	 * @param bool $wp_cache     WP_CACHE is true.
	 * @return string `multisite`, `not_writable`, `wp_cache_missing`, or `''`.
	 */
	public static function enable_block_reason(bool $is_multisite, bool $writable, bool $wp_cache): string {
		if ($is_multisite) {
			return 'multisite';
		}
		if (!$writable) {
			return 'not_writable';
		}
		if (!$wp_cache) {
			return 'wp_cache_missing';
		}
		return '';
	}

	/**
	 * Capability and nonce check for a POST action.
	 *
	 * @param string $action Nonce action.
	 */
	private function authorize(string $action): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Sorry, you are not allowed to manage NunezCache.', 'nunezcache'), '', array( 'response' => 403 ));
		}
		check_admin_referer($action);
	}

	/**
	 * Save enabled and paused to state.php and to the admin option.
	 *
	 * @param bool $enabled Whether serving and storing are allowed.
	 * @param bool $paused  Whether the cache is paused.
	 */
	private function persist(bool $enabled, bool $paused): void {
		$state              = $this->state->read();
		$state['enabled']   = $enabled;
		$state['paused']    = $paused;
		$state['generation'] = (int) $state['generation'] + 1;
		if ('' === $state['bypass_token']) {
			$state['bypass_token'] = bin2hex(random_bytes(32));
		}
		$this->state->write($state);
		if (null === get_option(NunezCache_Plugin::OPTION, null)) {
			add_option(NunezCache_Plugin::OPTION, $state, '', false);
		} else {
			update_option(NunezCache_Plugin::OPTION, $state, false);
		}
	}

	/**
	 * Create the cache root when needed and report whether it and the log directory are writable.
	 *
	 * @return bool True when both directories exist and are writable.
	 */
	private function directory_is_writable(): bool {
		$root = NunezCache_Plugin::cache_root();
		$log  = $root . '/log';
		if (!is_dir($root)) {
			wp_mkdir_p($root);
		}
		if (!is_dir($log)) {
			wp_mkdir_p($log);
		}
		return is_dir($root) && is_writable($root) && is_dir($log) && is_writable($log);
	}

	/**
	 * Name of the PHP process user, for the writable-directory error.
	 *
	 * @return string User name, or a uid label.
	 */
	private function php_user(): string {
		if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
			$info = posix_getpwuid(posix_geteuid());
			if (is_array($info) && isset($info['name']) && is_string($info['name']) && '' !== $info['name']) {
				return $info['name'];
			}
		}
		$user = getenv('USER');
		if (is_string($user) && '' !== $user) {
			return $user;
		}
		return 'uid ' . (string) getmyuid();
	}

	/**
	 * Redirect back to Status.
	 *
	 * @param string               $notice Notice code.
	 * @param array<string, mixed> $extra  Extra query arguments.
	 */
	private function redirect(string $notice, array $extra = array()): void {
		$url = add_query_arg(
			array_merge(
				array(
					'page'              => 'nunezcache',
					'nunezcache_notice' => $notice,
				),
				$extra
			),
			admin_url('admin.php')
		);
		wp_safe_redirect($url);
		exit;
	}
}
