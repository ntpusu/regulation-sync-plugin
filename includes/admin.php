<?php
/**
 * Admin menus, forms, and handlers.
 *
 * @package NTPUSURegulationSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'ntpusu_regulation_sync_register_admin_page' );
add_action( 'admin_post_ntpusu_regulation_sync', 'ntpusu_regulation_sync_handle_request' );
add_action( 'admin_post_ntpusu_regulation_sync_remove_map', 'ntpusu_regulation_sync_handle_remove_map' );
add_action( 'admin_post_ntpusu_regulation_sync_sync_one', 'ntpusu_regulation_sync_handle_sync_one' );
add_action( 'admin_post_ntpusu_regulation_sync_sync_all', 'ntpusu_regulation_sync_handle_sync_all' );
add_action( 'admin_post_ntpusu_regulation_sync_toggle_schedule', 'ntpusu_regulation_sync_handle_toggle_schedule' );

/**
 * Adds the top-level admin page.
 */
function ntpusu_regulation_sync_register_admin_page() {
	add_menu_page(
		__( '法規同步', 'ntpusu-regulation-sync' ),
		__( '法規同步', 'ntpusu-regulation-sync' ),
		'edit_posts',
		'ntpusu-regulation-sync',
		'ntpusu_regulation_sync_render_admin_page',
		'dashicons-update',
		81
	);
}

/**
 * Handles the POST request triggered by the admin "Sync" button.
 */
function ntpusu_regulation_sync_handle_request() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( '你沒有權限執行此操作。', 'ntpusu-regulation-sync' ) );
	}

	check_admin_referer( 'ntpusu_regulation_sync_action' );

	$source_key  = isset( $_POST['ntpusu_regulation_source'] ) ? sanitize_text_field( wp_unslash( $_POST['ntpusu_regulation_source'] ) ) : 'id';
	$target_post = 0;
	if ( ! empty( $_POST['ntpusu_regulation_target_post_manual'] ) ) {
		$target_post = absint( wp_unslash( $_POST['ntpusu_regulation_target_post_manual'] ) );
	} elseif ( isset( $_POST['ntpusu_regulation_target_post'] ) ) {
		$target_post = absint( wp_unslash( $_POST['ntpusu_regulation_target_post'] ) );
	}

	if ( $target_post ) {
		if ( ! ntpusu_regulation_sync_user_can_manage_post( $target_post ) ) {
			ntpusu_regulation_sync_store_notice(
				'error',
				__( '你沒有權限更新此對應。', 'ntpusu-regulation-sync' )
			);
			wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
			exit;
		}
	} elseif ( ! current_user_can( 'manage_options' ) ) {
		ntpusu_regulation_sync_store_notice(
			'error',
			__( '你必須選擇一個可編輯的內容項目，才能儲存同步內容。', 'ntpusu-regulation-sync' )
		);
		wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
		exit;
	}

	$sources      = ntpusu_regulation_sync_sources();
	$selected_key = array_key_exists( $source_key, $sources ) ? $source_key : 'id';
	$source_url   = '';

	if ( 'id' === $selected_key ) {
		$reg_id = isset( $_POST['ntpusu_regulation_id'] ) ? absint( wp_unslash( $_POST['ntpusu_regulation_id'] ) ) : 0;
		if ( ! $reg_id ) {
			ntpusu_regulation_sync_store_notice(
				'error',
				__( '請輸入法規編號。', 'ntpusu-regulation-sync' )
			);
			wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
			exit;
		}
		$source_url = ntpusu_regulation_sync_build_regulation_url( $reg_id );
		update_option( NTPUSU_REGULATION_SYNC_OPTION_LAST_CHOICE, 'id' );
		delete_option( NTPUSU_REGULATION_SYNC_OPTION_CUSTOM_URL );
	} elseif ( 'list' === $selected_key ) {
		$list_url = isset( $_POST['ntpusu_regulation_list_url'] ) ? esc_url_raw( wp_unslash( $_POST['ntpusu_regulation_list_url'] ) ) : '';
		if ( empty( $list_url ) ) {
			ntpusu_regulation_sync_store_notice(
				'error',
				__( '請從清單中選擇法規。', 'ntpusu-regulation-sync' )
			);
			wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
			exit;
		}
		if ( str_starts_with( $list_url, '/' ) ) {
			$list_url = NTPUSU_REGULATION_SYNC_BASE_URL . $list_url;
		}
		$source_url = $list_url;
		update_option( NTPUSU_REGULATION_SYNC_OPTION_LAST_CHOICE, 'list' );
		delete_option( NTPUSU_REGULATION_SYNC_OPTION_CUSTOM_URL );
	} elseif ( 'custom' === $selected_key ) {
		$custom_url = isset( $_POST['ntpusu_regulation_custom_url'] ) ? esc_url_raw( wp_unslash( $_POST['ntpusu_regulation_custom_url'] ) ) : '';
		if ( empty( $custom_url ) ) {
			ntpusu_regulation_sync_store_notice(
				'error',
				__( '請輸入要擷取的自訂來源網址。', 'ntpusu-regulation-sync' )
			);
			wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
			exit;
		}
		$source_url = $custom_url;
		update_option( NTPUSU_REGULATION_SYNC_OPTION_LAST_CHOICE, 'custom' );
		update_option( NTPUSU_REGULATION_SYNC_OPTION_CUSTOM_URL, $source_url );
	}

	if ( empty( $source_url ) ) {
		ntpusu_regulation_sync_store_notice(
			'error',
			__( '指定的來源不可用。', 'ntpusu-regulation-sync' )
		);
		wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
		exit;
	}

	$source_url = ntpusu_regulation_sync_normalize_source_url( $source_url );

	if ( 'custom' === $selected_key ) {
		update_option( NTPUSU_REGULATION_SYNC_OPTION_CUSTOM_URL, $source_url );
	}

	$result = ntpusu_regulation_sync_fetch_html( $source_url );

	if ( is_wp_error( $result ) ) {
		ntpusu_regulation_sync_store_notice(
			'error',
			sprintf(
				/* translators: %s: error message */
				__( '無法取得 HTML：%s', 'ntpusu-regulation-sync' ),
				$result->get_error_message()
			)
		);
		wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
		exit;
	}

	$payload            = is_array( $result ) ? $result : array( 'html' => (string) $result, 'modified' => null );
	$source_modified_at = $payload['modified'] ?? null;

	update_option( NTPUSU_REGULATION_SYNC_OPTION_HTML, $payload['html'] );
	update_option( NTPUSU_REGULATION_SYNC_OPTION_LAST_SOURCE, $source_url );
	update_option( NTPUSU_REGULATION_SYNC_OPTION_LAST_CHOICE, $selected_key );
	update_option( NTPUSU_REGULATION_SYNC_OPTION_UPDATED_AT, time() );

	$extra_message = '';
	if ( $target_post ) {
		$post_to_map = get_post( $target_post );
		if ( $post_to_map instanceof WP_Post ) {
			ntpusu_regulation_sync_save_post_payload( $target_post, $source_url, $payload['html'], $source_modified_at );
			$extra_message = ' ' . sprintf(
				/* translators: %s: post title */
				__( '已將同步內容對應到「%s」。', 'ntpusu-regulation-sync' ),
				$post_to_map->post_title ? esc_html( $post_to_map->post_title ) : sprintf( __( '內容 #%d', 'ntpusu-regulation-sync' ), $target_post )
			);
		}
	}

	ntpusu_regulation_sync_store_notice(
		'success',
		sprintf(
			/* translators: %s: URL */
			__( '已成功自 %s 同步內容。', 'ntpusu-regulation-sync' ),
			esc_url_raw( $source_url )
		) . $extra_message
	);

	wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
	exit;
}

/**
 * Removes a stored mapping between a WordPress post and a regulation source.
 */
function ntpusu_regulation_sync_handle_remove_map() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( '你沒有權限執行此操作。', 'ntpusu-regulation-sync' ) );
	}

	check_admin_referer( 'ntpusu_regulation_sync_remove_map' );

	$post_id = isset( $_POST['ntpusu_regulation_remove_post'] ) ? absint( wp_unslash( $_POST['ntpusu_regulation_remove_post'] ) ) : 0;

	if ( $post_id ) {
		if ( ! ntpusu_regulation_sync_user_can_manage_post( $post_id ) ) {
			ntpusu_regulation_sync_store_notice(
				'error',
				__( '你沒有權限移除此對應。', 'ntpusu-regulation-sync' )
			);
			wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
			exit;
		}
		ntpusu_regulation_sync_delete_post_payload( $post_id );
		ntpusu_regulation_sync_store_notice(
			'success',
			sprintf(
				/* translators: %d: Post ID */
				__( '已移除內容 ID %d 的對應。', 'ntpusu-regulation-sync' ),
				$post_id
			)
		);
	} else {
		ntpusu_regulation_sync_store_notice(
			'error',
			__( '未提供內容 ID，無法移除對應。', 'ntpusu-regulation-sync' )
		);
	}

	wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
	exit;
}

/**
 * Handles syncing a single mapped post.
 */
function ntpusu_regulation_sync_handle_sync_one() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( '你沒有權限執行此操作。', 'ntpusu-regulation-sync' ) );
	}

	check_admin_referer( 'ntpusu_regulation_sync_sync_one' );

	$post_id = isset( $_POST['ntpusu_regulation_sync_post'] ) ? absint( wp_unslash( $_POST['ntpusu_regulation_sync_post'] ) ) : 0;
	if ( ! $post_id ) {
		ntpusu_regulation_sync_store_notice( 'error', __( '尚未選擇要同步的對應。', 'ntpusu-regulation-sync' ) );
		wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
		exit;
	}

	$result = ntpusu_regulation_sync_refresh_post( $post_id, true );
	if ( is_wp_error( $result ) ) {
		ntpusu_regulation_sync_store_notice(
			'error',
			sprintf(
				/* translators: %s: error message */
				__( '無法同步此對應：%s', 'ntpusu-regulation-sync' ),
				$result->get_error_message()
			)
		);
		wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
		exit;
	}

	ntpusu_regulation_sync_store_notice(
		'success',
		sprintf(
			/* translators: %d: post ID */
			__( '已同步內容 %d 的對應。', 'ntpusu-regulation-sync' ),
			$post_id
		)
	);

	wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
	exit;
}

/**
 * Handles syncing all mapped posts.
 */
function ntpusu_regulation_sync_handle_sync_all() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( '你沒有權限執行此操作。', 'ntpusu-regulation-sync' ) );
	}

	check_admin_referer( 'ntpusu_regulation_sync_sync_all' );

	$results = ntpusu_regulation_sync_sync_all_mappings( true );

	$message = sprintf(
		/* translators: 1: synced count, 2: skipped count */
		__( '已同步 %1$d 筆對應；略過 %2$d 筆。', 'ntpusu-regulation-sync' ),
		$results['synced'],
		$results['skipped']
	);

	if ( ! empty( $results['errors'] ) ) {
		$message .= ' ' . implode( ' ', $results['errors'] );
		ntpusu_regulation_sync_store_notice( 'error', $message );
	} else {
		ntpusu_regulation_sync_store_notice( 'success', $message );
	}

	wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
	exit;
}

/**
 * Handles toggling the scheduled sync.
 */
function ntpusu_regulation_sync_handle_toggle_schedule() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '只有管理員可以變更同步排程。', 'ntpusu-regulation-sync' ) );
	}

	check_admin_referer( 'ntpusu_regulation_sync_toggle_schedule' );

	$enable = ! empty( $_POST['ntpusu_regulation_sync_schedule'] );
	ntpusu_regulation_sync_update_schedule( $enable );

	ntpusu_regulation_sync_store_notice(
		'success',
		$enable
			? __( '已啟用排程同步。', 'ntpusu-regulation-sync' )
			: __( '已停用排程同步。', 'ntpusu-regulation-sync' )
	);

	wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
	exit;
}

/**
 * Outputs the plugin admin page UI.
 */
function ntpusu_regulation_sync_render_admin_page() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$notice = get_transient( NTPUSU_REGULATION_SYNC_NOTICE_TRANSIENT );
	if ( $notice ) {
		delete_transient( NTPUSU_REGULATION_SYNC_NOTICE_TRANSIENT );
		?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
		<?php
	}

	$stored_html    = get_option( NTPUSU_REGULATION_SYNC_OPTION_HTML, '' );
	$last_source    = get_option( NTPUSU_REGULATION_SYNC_OPTION_LAST_SOURCE, '' );
	$updated_at     = get_option( NTPUSU_REGULATION_SYNC_OPTION_UPDATED_AT );
	$last_choice    = get_option( NTPUSU_REGULATION_SYNC_OPTION_LAST_CHOICE, 'id' );
	$custom_url     = get_option( NTPUSU_REGULATION_SYNC_OPTION_CUSTOM_URL, '' );
	$reg_links      = ntpusu_regulation_sync_fetch_regulation_links();
	$schedule_on    = (bool) get_option( NTPUSU_REGULATION_SYNC_OPTION_SCHEDULED, false );
	$next_scheduled = wp_next_scheduled( NTPUSU_REGULATION_SYNC_CRON_HOOK );

	$edit_map_id   = isset( $_GET['edit_map'] ) ? absint( $_GET['edit_map'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	$edit_map_post = $edit_map_id ? get_post( $edit_map_id ) : null;
	if ( $edit_map_post && ntpusu_regulation_sync_user_can_manage_post( $edit_map_id ) ) {
		$mapped_source = ntpusu_regulation_sync_get_mapped_source( $edit_map_id );
		if ( $mapped_source ) {
			$last_source = $mapped_source;
			$mapped_regulation_id = ntpusu_regulation_sync_extract_regulation_id( $mapped_source );
			if ( $mapped_regulation_id ) {
				$_REQUEST['ntpusu_regulation_id'] = $mapped_regulation_id; // phpcs:ignore WordPress.Security.NonceVerification
				$last_choice                      = 'id';
			} else {
				$custom_url  = $mapped_source;
				$last_choice = 'custom';
			}
		}
	}

	$last_source_regulation_id = ntpusu_regulation_sync_extract_regulation_id( $last_source );
	$selectable_posts          = ntpusu_regulation_sync_get_selectable_posts( $edit_map_id ?: 0, '法律層級法規' );

	?>
	<div class="wrap">
		<h1><?php esc_html_e( '法規同步', 'ntpusu-regulation-sync' ); ?></h1>
		<h1><?php esc_html_e( '測試中！請在取得通知前先避免依賴此外掛', 'ntpusu-regulation-sync' ); ?></h1>
		<p><?php esc_html_e( '抓取最新法規 HTML，並對應到指定內容或儲存為全域副本。可使用 [ntpusu_regulation] 短代碼顯示同步內容。', 'ntpusu-regulation-sync' ); ?></p>

		<div class="postbox" style="padding:16px;">
			<h2><?php esc_html_e( '同步設定', 'ntpusu-regulation-sync' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ntpusu_regulation_sync_action' ); ?>
				<input type="hidden" name="action" value="ntpusu_regulation_sync" />

				<div style="display:flex;flex-wrap:wrap;gap:16px;">
					<div style="flex:1;min-width:280px;">
						<h3><?php esc_html_e( '選擇來源', 'ntpusu-regulation-sync' ); ?></h3>

						<div style="margin-bottom:12px;">
							<label>
								<input type="radio" name="ntpusu_regulation_source" value="id" <?php checked( $last_choice, 'id' ); ?> />
								<strong><?php esc_html_e( '輸入法規編號', 'ntpusu-regulation-sync' ); ?></strong>
							</label>
							<div style="margin-top:6px;">
								<input type="number" min="1" class="small-text" name="ntpusu_regulation_id" placeholder="e.g. 7" value="<?php echo isset( $_REQUEST['ntpusu_regulation_id'] ) ? esc_attr( wp_unslash( $_REQUEST['ntpusu_regulation_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification ?>" />
								<span class="description"><?php esc_html_e( '將使用 ' . NTPUSU_REGULATION_SYNC_BASE_URL . '/regulation/{id}', 'ntpusu-regulation-sync' ); ?></span>
							</div>
						</div>

						<div style="margin-bottom:12px;">
							<label>
								<input type="radio" name="ntpusu_regulation_source" value="list" <?php checked( $last_choice, 'list' ); ?> />
								<strong><?php esc_html_e( '從法規清單選擇', 'ntpusu-regulation-sync' ); ?></strong>
							</label>
							<div style="margin-top:6px;">
								<?php if ( $reg_links ) : ?>
									<select name="ntpusu_regulation_list_url">
										<option value=""><?php esc_html_e( '請選擇法規', 'ntpusu-regulation-sync' ); ?></option>
										<?php foreach ( $reg_links as $link ) : ?>
											<?php $link_regulation_id = ntpusu_regulation_sync_extract_regulation_id( $link['href'] ); ?>
											<option value="<?php echo esc_attr( $link['href'] ); ?>" <?php selected( $last_source === $link['href'] || ( $last_source_regulation_id && $link_regulation_id && $last_source_regulation_id === $link_regulation_id ), true ); ?>><?php echo esc_html( $link['text'] ); ?></option>
										<?php endforeach; ?>
									</select>
									<span class="description"><?php esc_html_e( '清單來源：' . NTPUSU_REGULATION_SYNC_BASE_URL . '/api/regulation/list', 'ntpusu-regulation-sync' ); ?></span>
								<?php else : ?>
									<span class="description"><?php esc_html_e( '目前無法載入法規清單，仍可輸入法規編號或自訂來源網址。', 'ntpusu-regulation-sync' ); ?></span>
								<?php endif; ?>
							</div>
						</div>

						<div style="margin-bottom:12px;">
							<label>
								<input type="radio" name="ntpusu_regulation_source" value="custom" <?php checked( $last_choice, 'custom' ); ?> />
								<strong><?php esc_html_e( '自訂來源網址', 'ntpusu-regulation-sync' ); ?></strong>
							</label>
							<div style="margin-top:6px;">
								<input type="url" class="regular-text" name="ntpusu_regulation_custom_url" placeholder="https://example.com/path" value="<?php echo esc_attr( $custom_url ); ?>" />
							</div>
						</div>
					</div>

					<div style="flex:1;min-width:280px;">
						<h3><?php esc_html_e( '選擇要更新的內容', 'ntpusu-regulation-sync' ); ?></h3>
						<p><?php esc_html_e( '選擇要接收同步 HTML 的 WordPress 內容項目。', 'ntpusu-regulation-sync' ); ?></p>
						<?php if ( $selectable_posts ) : ?>
							<select name="ntpusu_regulation_target_post" id="ntpusu_regulation_target_post">
								<option value="0"><?php esc_html_e( '不對應到特定內容', 'ntpusu-regulation-sync' ); ?></option>
								<?php
								$current_type_label = '';

								foreach ( $selectable_posts as $selectable_post ) :
									if ( $current_type_label !== $selectable_post['type_label'] ) :
										if ( '' !== $current_type_label ) :
											?>
											</optgroup>
											<?php
										endif;
										$current_type_label = $selectable_post['type_label'];
										?>
										<optgroup label="<?php echo esc_attr( $current_type_label ); ?>">
									<?php endif; ?>
									<?php
									$option_label = sprintf(
										'%1$s (#%2$d)%3$s',
										$selectable_post['title'],
										$selectable_post['id'],
										'publish' !== $selectable_post['status'] ? ' - ' . $selectable_post['status_label'] : ''
									);
									?>
									<option value="<?php echo esc_attr( $selectable_post['id'] ); ?>" <?php selected( $edit_map_id ?: 0, $selectable_post['id'] ); ?>>
										<?php echo esc_html( $option_label ); ?>
									</option>
								<?php endforeach; ?>
								<?php if ( '' !== $current_type_label ) : ?>
									</optgroup>
								<?php endif; ?>
							</select>
						<?php else : ?>
							<select id="ntpusu_regulation_target_post" disabled="disabled">
								<option><?php esc_html_e( '找不到可編輯內容', 'ntpusu-regulation-sync' ); ?></option>
							</select>
						<?php endif; ?>
						<p style="margin-top:12px;">
							<label for="ntpusu_regulation_target_post_manual">
								<?php esc_html_e( '或手動輸入內容 ID：', 'ntpusu-regulation-sync' ); ?>
							</label>
							<input type="number" name="ntpusu_regulation_target_post_manual" id="ntpusu_regulation_target_post_manual" class="small-text" min="0" value="<?php echo $edit_map_id ? esc_attr( $edit_map_id ) : ''; ?>" />
						</p>
						<p class="description"><?php esc_html_e( '若留空，外掛只會更新全域副本。', 'ntpusu-regulation-sync' ); ?></p>
					</div>
				</div>

				<p>
					<?php submit_button( __( '立即同步', 'ntpusu-regulation-sync' ), 'primary', 'ntpusu_regulation_sync_submit', false ); ?>
				</p>
			</form>
		</div>

		<?php if ( $updated_at ) : ?>
			<p>
				<?php
				$date_string = esc_html(
					wp_date(
						get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
						(int) $updated_at
					)
				);

				if ( $last_source ) {
					$source_markup = sprintf(
						'<a href="%1$s" target="_blank" rel="noopener">%1$s</a>',
						esc_url( $last_source )
					);
				} else {
					$source_markup = esc_html__( '未知來源', 'ntpusu-regulation-sync' );
				}

				echo wp_kses_post(
					sprintf(
						/* translators: 1: human-readable date, 2: URL */
						__( '最近同步時間：%1$s，來源：%2$s。', 'ntpusu-regulation-sync' ),
						$date_string,
						$source_markup
					)
				);
				?>
			</p>
		<?php endif; ?>
		<?php
		$mapped_post_ids = ntpusu_regulation_sync_get_mapped_post_ids();
		if ( ! empty( $mapped_post_ids ) ) :
			?>
			<h2 style="margin-top:32px; display:flex; align-items:center; gap:12px;"><?php esc_html_e( '已對應內容', 'ntpusu-regulation-sync' ); ?></h2>
			<div style="display:flex;gap:12px;align-items:center;margin-bottom:8px;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ntpusu_regulation_sync_sync_all' ); ?>
					<input type="hidden" name="action" value="ntpusu_regulation_sync_sync_all" />
					<?php submit_button( __( '全部同步', 'ntpusu-regulation-sync' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ntpusu_regulation_sync_toggle_schedule' ); ?>
					<input type="hidden" name="action" value="ntpusu_regulation_sync_toggle_schedule" />
					<label style="display:flex;align-items:center;gap:6px;">
						<input type="checkbox" name="ntpusu_regulation_sync_schedule" value="1" <?php checked( $schedule_on ); ?> <?php disabled( ! current_user_can( 'manage_options' ) ); ?> />
						<?php esc_html_e( '啟用排程同步（每日兩次）', 'ntpusu-regulation-sync' ); ?>
					</label>
					<?php
					$schedule_btn_attrs = current_user_can( 'manage_options' ) ? array() : array( 'disabled' => 'disabled' );
					submit_button( __( '儲存排程', 'ntpusu-regulation-sync' ), 'secondary', 'submit', false, $schedule_btn_attrs );
					?>
					<?php if ( $next_scheduled ) : ?>
						<span class="description" style="margin-left:8px;"><?php printf( esc_html__( '下次執行：%s', 'ntpusu-regulation-sync' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_scheduled ) ) ); ?></span>
					<?php endif; ?>
				</form>
			</div>
			<p><?php esc_html_e( '這些內容各自保存一份同步 HTML 副本。', 'ntpusu-regulation-sync' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( '內容', 'ntpusu-regulation-sync' ); ?></th>
						<th><?php esc_html_e( '來源網址', 'ntpusu-regulation-sync' ); ?></th>
						<th><?php esc_html_e( '權限', 'ntpusu-regulation-sync' ); ?></th>
						<th><?php esc_html_e( '最近同步', 'ntpusu-regulation-sync' ); ?></th>
						<th><?php esc_html_e( '短代碼', 'ntpusu-regulation-sync' ); ?></th>
						<th><?php esc_html_e( '操作', 'ntpusu-regulation-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$printed = false;
					foreach ( $mapped_post_ids as $mapped_id ) :
						$post_obj = get_post( $mapped_id );
						if ( ! $post_obj ) {
							ntpusu_regulation_sync_delete_post_payload( $mapped_id );
							continue;
						}
						$printed      = true;
						$source       = ntpusu_regulation_sync_get_mapped_source( $mapped_id );
						$mapped_date  = ntpusu_regulation_sync_get_mapped_updated_at( $mapped_id );
						$shortcode          = sprintf( '[ntpusu_regulation post_id="%d"]', $mapped_id );
						$can_manage         = ntpusu_regulation_sync_user_can_manage_post( $mapped_id );
						$action_btn_attrs   = $can_manage ? array() : array( 'disabled' => 'disabled' );
						?>
						<tr>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $mapped_id ) ); ?>">
									<?php echo esc_html( get_the_title( $mapped_id ) ); ?>
								</a>
							</td>
							<td>
								<?php if ( $source ) : ?>
									<a href="<?php echo esc_url( $source ); ?>" target="_blank" rel="noopener">
										<?php echo esc_html( $source ); ?>
									</a>
								<?php else : ?>
									<em><?php esc_html_e( '未知來源', 'ntpusu-regulation-sync' ); ?></em>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $can_manage ) : ?>
									<span class="dashicons dashicons-yes" style="color:#46b450"></span>
								<?php else : ?>
									<span class="dashicons dashicons-no" style="color:#dc3232"></span>
								<?php endif; ?>
							</td>
							<td>
								<?php
								echo $mapped_date
									? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $mapped_date ) )
									: esc_html__( '尚未同步', 'ntpusu-regulation-sync' );
								?>
							</td>
							<td>
								<code><?php echo esc_html( $shortcode ); ?></code>
							</td>
							<td>
								<div style="display:flex;gap:8px;flex-wrap:wrap;">
									<a class="button<?php echo $can_manage ? '' : ' disabled'; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ntpusu-regulation-sync', 'edit_map' => $mapped_id ), admin_url( 'admin.php' ) ) ); ?>" <?php disabled( ! $can_manage ); ?>>
										<?php esc_html_e( '編輯', 'ntpusu-regulation-sync' ); ?>
									</a>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php wp_nonce_field( 'ntpusu_regulation_sync_sync_one' ); ?>
										<input type="hidden" name="action" value="ntpusu_regulation_sync_sync_one" />
										<input type="hidden" name="ntpusu_regulation_sync_post" value="<?php echo esc_attr( $mapped_id ); ?>" />
										<?php submit_button( __( '同步', 'ntpusu-regulation-sync' ), 'secondary', 'submit', false, $action_btn_attrs ); ?>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php wp_nonce_field( 'ntpusu_regulation_sync_remove_map' ); ?>
										<input type="hidden" name="action" value="ntpusu_regulation_sync_remove_map" />
										<input type="hidden" name="ntpusu_regulation_remove_post" value="<?php echo esc_attr( $mapped_id ); ?>" />
										<?php submit_button( __( '移除', 'ntpusu-regulation-sync' ), 'delete', 'submit', false, $action_btn_attrs ); ?>
									</form>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $printed ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( '目前沒有已對應內容。', 'ntpusu-regulation-sync' ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php if ( $stored_html ) : ?>
			<h2><?php esc_html_e( '已儲存內容預覽', 'ntpusu-regulation-sync' ); ?></h2>
			<div style="border:1px solid #ccd0d4;padding:1em;background:#fff;max-height:400px;overflow:auto;">
				<?php echo ntpusu_regulation_sync_render_html( $stored_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}
