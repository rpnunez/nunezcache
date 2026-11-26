<?php
/**
 * Status screen.
 *
 * @package NunezCache
 * Purpose: Render Off, On, or Paused, the last 50 requests, and the cache actions.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH') && 'cli' !== PHP_SAPI && 'phpdbg' !== PHP_SAPI && 'cli-server' !== PHP_SAPI) {
	exit;
}

/**
 * Markup for the NunezCache status screen. It does not hash keys or delete files.
 */
class NunezCache_Status_Screen {

	/**
	 * Render the screen from data the admin class already gathered.
	 *
	 * @param array<string, mixed> $data Screen fields.
	 */
	public function render(array $data): void {
		$status = isset($data['status_word']) ? (string) $data['status_word'] : 'Off';
		$notice = isset($data['notice']) ? (string) $data['notice'] : '';
		$count  = isset($data['purge_count']) ? (int) $data['purge_count'] : 0;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__('NunezCache', 'nunezcache') . '</h1>';
		$this->notice($notice, $count);

		if (!empty($data['multisite'])) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__('NunezCache does not enable on a multisite network. One advanced-cache.php is shared by every site, so this plugin stays off.', 'nunezcache');
			echo '</p></div>';
		}

		echo '<p>' . esc_html__('State:', 'nunezcache') . ' <strong>' . esc_html($status) . '</strong></p>';
		echo '<p>';
		$this->primary_actions($data, $status);
		echo '</p>';

		if (empty($data['wp_cache']) && empty($data['multisite'])) {
			$this->wp_cache_prompt($data);
		}
		if (!empty($data['enabled']) && !empty($data['wp_cache']) && empty($data['dropin_active']) && empty($data['multisite'])) {
			$this->restore_prompt();
		}

		echo '<h2>' . esc_html__('This site, last requests', 'nunezcache') . '</h2>';
		if (empty($data['writable'])) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: cache directory path, 2: PHP user name */
					__('Cannot write %1$s. The PHP user is %2$s. NunezCache cannot be enabled until that directory is writable.', 'nunezcache'),
					isset($data['cache_path']) ? (string) $data['cache_path'] : '',
					isset($data['php_user']) ? (string) $data['php_user'] : ''
				)
			);
			echo '</p></div>';
		} else {
			$this->request_table(isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : array());
		}
		echo '</div>';
	}

	/**
	 * Admin notice for a redirect code.
	 *
	 * @param string $notice Notice code from the query string.
	 * @param int    $count  Files removed, used by the purged notice.
	 */
	private function notice(string $notice, int $count): void {
		$success = array(
			'enabled'           => __('Cache is on.', 'nunezcache'),
			'paused'            => __('Cache is paused. Stored pages were kept.', 'nunezcache'),
			'purged'            => sprintf(
				/* translators: %d: number of files removed */
				__('Purge all removed %d files.', 'nunezcache'),
				$count
			),
			'wp_config_updated' => __('WP_CACHE is now true in wp-config.php. Enable the cache when you are ready.', 'nunezcache'),
			'dropin_restored'   => __('Drop-in restored.', 'nunezcache'),
		);
		$error = array(
			'not_writable'      => __('The cache directory is not writable. NunezCache was not enabled.', 'nunezcache'),
			'wp_cache_missing'  => __('NunezCache was not turned on. wp-config.php must define WP_CACHE as true.', 'nunezcache'),
			'wp_config_failed'  => __('wp-config.php could not be edited. Add the WP_CACHE line yourself.', 'nunezcache'),
			'multisite'         => __('NunezCache was not enabled. Multisite is not supported.', 'nunezcache'),
			'foreign_dropin'    => __('advanced-cache.php belongs to another plugin. NunezCache did not replace it.', 'nunezcache'),
			'dropin_failed'     => __('The drop-in could not be written.', 'nunezcache'),
		);
		if (isset($success[ $notice ])) {
			echo '<div class="notice notice-success"><p>' . esc_html($success[ $notice ]) . '</p></div>';
			return;
		}
		if (isset($error[ $notice ])) {
			echo '<div class="notice notice-error"><p>' . esc_html($error[ $notice ]) . '</p></div>';
		}
	}

	/**
	 * Enable or Pause, plus Purge all.
	 *
	 * @param array<string, mixed> $data   Screen fields.
	 * @param string               $status Off, On, or Paused.
	 */
	private function primary_actions(array $data, string $status): void {
		$blocked = !empty($data['multisite']) || empty($data['writable']);
		if ('On' === $status) {
			$this->post_form('nunezcache_pause', __('Pause cache', 'nunezcache'), 'primary', false);
		} else {
			$this->post_form('nunezcache_enable', __('Enable cache', 'nunezcache'), 'primary', $blocked);
		}
		$this->post_form('nunezcache_purge_all', __('Purge all', 'nunezcache'), 'secondary', false);
	}

	/**
	 * Explain WP_CACHE and offer the edit when the file is writable.
	 *
	 * @param array<string, mixed> $data Screen fields.
	 */
	private function wp_cache_prompt(array $data): void {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__('NunezCache is off until wp-config.php contains this line:', 'nunezcache');
		echo '</p><p><code>' . esc_html("define( 'WP_CACHE', true );") . '</code></p>';
		if (!empty($data['config_writable'])) {
			$this->post_form('nunezcache_allow_wp_config', __('Allow wp-config.php edit', 'nunezcache'), 'secondary', false);
		}
		echo '</div>';
	}

	/**
	 * Offer to write the drop-in again when state is on and the file is missing.
	 */
	private function restore_prompt(): void {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__('The drop-in is missing while NunezCache is on.', 'nunezcache');
		echo '</p><p>';
		$this->post_form('nunezcache_restore_dropin', __('Restore drop-in', 'nunezcache'), 'primary', false);
		echo '</p></div>';
	}

	/**
	 * Last requests, or the empty-state sentence.
	 *
	 * @param array<int, mixed> $rows Log rows, newest first.
	 */
	private function request_table(array $rows): void {
		if (array() === $rows) {
			echo '<p>' . esc_html__('No requests recorded yet. Open the site in a private window, then reload this screen.', 'nunezcache') . '</p>';
			return;
		}
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__('Time', 'nunezcache') . '</th>';
		echo '<th scope="col">' . esc_html__('Result', 'nunezcache') . '</th>';
		echo '<th scope="col">' . esc_html__('Reason', 'nunezcache') . '</th>';
		echo '<th scope="col">' . esc_html__('URL path', 'nunezcache') . '</th>';
		echo '<th scope="col">' . esc_html__('Milliseconds', 'nunezcache') . '</th>';
		echo '<th scope="col">' . esc_html__('Kilobytes', 'nunezcache') . '</th>';
		echo '</tr></thead><tbody>';
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$time = isset($row['time']) ? (int) $row['time'] : 0;
			$when = function_exists('wp_date') ? wp_date('Y-m-d H:i:s', $time) : gmdate('Y-m-d H:i:s', $time);
			$bytes = isset($row['bytes']) ? (int) $row['bytes'] : 0;
			$kb    = $bytes / 1024;
			$kb_s  = function_exists('number_format_i18n') ? number_format_i18n($kb, 1) : number_format($kb, 1);
			echo '<tr>';
			echo '<td>' . esc_html((string) $when) . '</td>';
			echo '<td>' . esc_html(isset($row['result']) ? (string) $row['result'] : '') . '</td>';
			echo '<td>' . esc_html(isset($row['reason']) ? (string) $row['reason'] : '') . '</td>';
			echo '<td>' . esc_html(isset($row['url']) ? (string) $row['url'] : '') . '</td>';
			echo '<td>' . esc_html((string) (isset($row['milliseconds']) ? (int) $row['milliseconds'] : 0)) . '</td>';
			echo '<td>' . esc_html((string) $kb_s) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * One POST form for an admin-post action.
	 *
	 * @param string $action   admin-post action and nonce name.
	 * @param string $label    Button label.
	 * @param string $class    submit_button type.
	 * @param bool   $disabled Whether the button is disabled.
	 */
	private function post_form(string $action, string $label, string $class, bool $disabled): void {
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px">';
		wp_nonce_field($action);
		echo '<input type="hidden" name="action" value="' . esc_attr($action) . '" />';
		submit_button(
			$label,
			$class,
			'submit',
			false,
			$disabled ? array( 'disabled' => 'disabled' ) : array()
		);
		echo '</form>';
	}
}
