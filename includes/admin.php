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
add_action( 'admin_post_ntpusu_regulation_sync_replace_content', 'ntpusu_regulation_sync_handle_replace_content' );
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
 * Replaces a mapped post's content with its shortcode.
 */
function ntpusu_regulation_sync_handle_replace_content() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( '你沒有權限執行此操作。', 'ntpusu-regulation-sync' ) );
	}

	check_admin_referer( 'ntpusu_regulation_sync_replace_content' );

	$post_id = isset( $_POST['ntpusu_regulation_replace_post'] ) ? absint( wp_unslash( $_POST['ntpusu_regulation_replace_post'] ) ) : 0;
	if ( ! $post_id ) {
		ntpusu_regulation_sync_store_notice( 'error', __( '尚未選擇要套用短代碼的內容。', 'ntpusu-regulation-sync' ) );
		wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
		exit;
	}

	if ( ! ntpusu_regulation_sync_user_can_manage_post( $post_id ) ) {
		ntpusu_regulation_sync_store_notice( 'error', __( '你沒有權限修改這篇內容。', 'ntpusu-regulation-sync' ) );
		wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
		exit;
	}

	$post_obj = get_post( $post_id );
	if ( ! $post_obj instanceof WP_Post ) {
		ntpusu_regulation_sync_store_notice( 'error', __( '找不到指定內容，無法套用短代碼。', 'ntpusu-regulation-sync' ) );
		wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
		exit;
	}

	$source = ntpusu_regulation_sync_get_mapped_source( $post_id );
	if ( '' === $source ) {
		ntpusu_regulation_sync_store_notice( 'error', __( '這篇內容尚未建立同步對應，無法直接套用短代碼。', 'ntpusu-regulation-sync' ) );
		wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
		exit;
	}

	$shortcode = sprintf( '[ntpusu_regulation post_id="%d"]', $post_id );
	$result    = wp_update_post(
		array(
			'ID'           => $post_id,
			'post_content' => $shortcode,
		),
		true
	);

	if ( is_wp_error( $result ) ) {
		ntpusu_regulation_sync_store_notice(
			'error',
			sprintf(
				/* translators: %s: error message */
				__( '無法將內容改為短代碼：%s', 'ntpusu-regulation-sync' ),
				$result->get_error_message()
			)
		);
		wp_safe_redirect( ntpusu_regulation_sync_admin_page_url() );
		exit;
	}

	ntpusu_regulation_sync_store_notice(
		'success',
		sprintf(
			/* translators: %s: post title */
			__( '已將「%s」的內容替換為同步短代碼。', 'ntpusu-regulation-sync' ),
			$post_obj->post_title ? esc_html( $post_obj->post_title ) : sprintf( __( '內容 #%d', 'ntpusu-regulation-sync' ), $post_id )
		)
	);

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
	$mapped_post_ids           = ntpusu_regulation_sync_get_mapped_post_ids();
	$mapped_count              = count( $mapped_post_ids );
	$manageable_mapped_post_ids = array_values(
		array_filter(
			$mapped_post_ids,
			static function ( $mapped_id ) {
				return ntpusu_regulation_sync_user_can_manage_post( $mapped_id );
			}
		)
	);
	$manageable_mapped_count   = count( $manageable_mapped_post_ids );
	$show_manageable_only      = isset( $_GET['manageable_only'] ) && '1' === wp_unslash( $_GET['manageable_only'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$visible_mapped_post_ids   = array_values(
		array_filter(
			$mapped_post_ids,
			static function ( $mapped_id ) use ( $show_manageable_only ) {
				return ! $show_manageable_only || ntpusu_regulation_sync_user_can_manage_post( $mapped_id );
			}
		)
	);
	$visible_mapped_count      = count( $visible_mapped_post_ids );
	$should_show_onboarding    = 0 === $manageable_mapped_count;
	$admin_post_url            = admin_url( 'admin-post.php' );
	$table_page_url            = add_query_arg( array( 'page' => 'ntpusu-regulation-sync' ), admin_url( 'admin.php' ) );
	$toggle_manageable_url     = $show_manageable_only
		? $table_page_url
		: add_query_arg(
			array(
				'page'            => 'ntpusu-regulation-sync',
				'manageable_only' => '1',
			),
			admin_url( 'admin.php' )
		);
	$is_editing_map            = $edit_map_post && ntpusu_regulation_sync_user_can_manage_post( $edit_map_id );
	$editing_title             = $is_editing_map ? get_the_title( $edit_map_id ) : '';
	$formatted_updated_at      = $updated_at
		? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $updated_at )
		: '';
	$formatted_next_run        = $next_scheduled
		? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_scheduled )
		: '';

	?>
	<div class="wrap ntpusu-regulation-admin">
		<style>
			.ntpusu-regulation-admin .ntpusu-layout { display:grid; grid-template-columns:minmax(0, 1.7fr) minmax(320px, 1fr); gap:20px; align-items:start; margin-top:20px; }
			.ntpusu-regulation-admin .ntpusu-main, .ntpusu-regulation-admin .ntpusu-sidebar { display:grid; gap:20px; }
			.ntpusu-regulation-admin .ntpusu-sidebar { position:sticky; top:32px; }
			.ntpusu-regulation-admin .ntpusu-card { margin:0; border:1px solid #d0d7de; border-radius:10px; overflow:hidden; box-shadow:0 1px 2px rgba(15, 23, 42, 0.06); }
			.ntpusu-regulation-admin .ntpusu-card .inside { margin:0; padding:18px 20px 20px; }
			.ntpusu-regulation-admin .ntpusu-lead { max-width:88ch; margin-bottom:0; }
			.ntpusu-regulation-admin .ntpusu-section-header, .ntpusu-regulation-admin .ntpusu-toolbar { display:flex; flex-wrap:wrap; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:14px; }
			.ntpusu-regulation-admin .ntpusu-section-header h2, .ntpusu-regulation-admin .ntpusu-section-header h3, .ntpusu-regulation-admin .ntpusu-toolbar h2 { margin:0; }
			.ntpusu-regulation-admin .ntpusu-section-header p, .ntpusu-regulation-admin .ntpusu-toolbar p { margin:4px 0 0; color:#50575e; }
			.ntpusu-regulation-admin .ntpusu-badge { display:inline-flex; align-items:center; padding:4px 10px; border-radius:999px; background:#e7f5ea; color:#166534; font-size:12px; font-weight:600; line-height:1.4; }
			.ntpusu-regulation-admin .ntpusu-badge-muted { background:#eef2ff; color:#334155; }
			.ntpusu-regulation-admin .ntpusu-badge-warning { background:#fff7ed; color:#9a3412; }
			.ntpusu-regulation-admin .ntpusu-steps { margin:0; padding-left:20px; }
			.ntpusu-regulation-admin .ntpusu-steps li { margin-bottom:12px; }
			.ntpusu-regulation-admin .ntpusu-steps li:last-child { margin-bottom:0; }
			.ntpusu-regulation-admin .ntpusu-steps strong { display:block; margin-bottom:4px; }
			.ntpusu-regulation-admin .ntpusu-table-wrap { overflow-x:hidden; }
			.ntpusu-regulation-admin .ntpusu-table-wrap table { width:100%; table-layout:fixed; }
			.ntpusu-regulation-admin .ntpusu-table-wrap th,
			.ntpusu-regulation-admin .ntpusu-table-wrap td { vertical-align:top; overflow-wrap:anywhere; word-break:break-word; }
			.ntpusu-regulation-admin .ntpusu-table-wrap th:nth-child(1),
			.ntpusu-regulation-admin .ntpusu-table-wrap td:nth-child(1) { width:18%; }
			.ntpusu-regulation-admin .ntpusu-table-wrap th:nth-child(2),
			.ntpusu-regulation-admin .ntpusu-table-wrap td:nth-child(2) { width:28%; }
			.ntpusu-regulation-admin .ntpusu-table-wrap th:nth-child(3),
			.ntpusu-regulation-admin .ntpusu-table-wrap td:nth-child(3) { width:8%; text-align:center; }
			.ntpusu-regulation-admin .ntpusu-table-wrap th:nth-child(4),
			.ntpusu-regulation-admin .ntpusu-table-wrap td:nth-child(4) { width:14%; }
			.ntpusu-regulation-admin .ntpusu-table-wrap th:nth-child(5),
			.ntpusu-regulation-admin .ntpusu-table-wrap td:nth-child(5) { width:14%; }
			.ntpusu-regulation-admin .ntpusu-table-wrap th:nth-child(6),
			.ntpusu-regulation-admin .ntpusu-table-wrap td:nth-child(6) { width:18%; }
			.ntpusu-regulation-admin .ntpusu-table-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
			.ntpusu-regulation-admin .ntpusu-toolbar-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
			.ntpusu-regulation-admin .ntpusu-source-link,
			.ntpusu-regulation-admin .ntpusu-shortcode { display:block; overflow-wrap:anywhere; word-break:break-word; white-space:normal; }
			.ntpusu-regulation-admin .ntpusu-source-link { font-size:12px; line-height:1.5; }
			.ntpusu-regulation-admin .ntpusu-shortcode { font-size:12px; line-height:1.5; }
			.ntpusu-regulation-admin .ntpusu-empty { padding:24px; border:1px dashed #cbd5e1; border-radius:10px; background:#f8fafc; text-align:center; }
			.ntpusu-regulation-admin .ntpusu-preview { border:1px solid #d0d7de; border-radius:8px; background:#fff; max-height:420px; overflow:auto; padding:16px; }
			.ntpusu-regulation-admin .ntpusu-form { display:grid; gap:18px; }
			.ntpusu-regulation-admin .ntpusu-form-section { padding:16px; border:1px solid #e5e7eb; border-radius:10px; background:#fbfcfd; }
			.ntpusu-regulation-admin .ntpusu-form-section h3 { margin:0 0 6px; }
			.ntpusu-regulation-admin .ntpusu-form-section > p:first-of-type { margin-top:0; }
			.ntpusu-regulation-admin .ntpusu-choice { display:block; margin-bottom:14px; }
			.ntpusu-regulation-admin .ntpusu-choice:last-child { margin-bottom:0; }
			.ntpusu-regulation-admin .ntpusu-choice label { display:flex; align-items:center; gap:8px; font-weight:600; }
			.ntpusu-regulation-admin .ntpusu-choice-input { margin-top:8px; padding-left:24px; }
			.ntpusu-regulation-admin .ntpusu-choice-input input[type="url"], .ntpusu-regulation-admin .ntpusu-choice-input input[type="number"], .ntpusu-regulation-admin .ntpusu-choice-input select, .ntpusu-regulation-admin .ntpusu-target-inputs select, .ntpusu-regulation-admin .ntpusu-target-inputs input[type="number"] { width:100%; max-width:100%; }
			.ntpusu-regulation-admin .ntpusu-inline-help { display:block; margin-top:6px; }
			.ntpusu-regulation-admin .ntpusu-target-inputs, .ntpusu-regulation-admin .ntpusu-manual-target { display:grid; gap:12px; }
			.ntpusu-regulation-admin .ntpusu-form-actions { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; }
			.ntpusu-regulation-admin .ntpusu-status-line { margin:0; color:#50575e; }
			.ntpusu-regulation-admin .ntpusu-meta-list { display:grid; grid-template-columns:112px minmax(0, 1fr); gap:10px 14px; margin:0; }
			.ntpusu-regulation-admin .ntpusu-meta-list dt { margin:0; font-weight:600; color:#1f2937; }
			.ntpusu-regulation-admin .ntpusu-meta-list dd { margin:0; color:#374151; word-break:break-word; }
			.ntpusu-regulation-admin .ntpusu-aside-note { margin-top:14px; padding:12px 14px; border-radius:8px; background:#f8fafc; color:#334155; }
			@media (max-width:1080px) { .ntpusu-regulation-admin .ntpusu-layout { grid-template-columns:1fr; } .ntpusu-regulation-admin .ntpusu-sidebar { position:static; top:auto; } }
		</style>
		<h1><?php esc_html_e( '法規同步', 'ntpusu-regulation-sync' ); ?></h1>
		<h1><?php esc_html_e( '測試中！請在取得通知前先避免依賴此外掛', 'ntpusu-regulation-sync' ); ?></h1>
		
		<div class="ntpusu-layout">
			<div class="ntpusu-main">
				<?php if ( $should_show_onboarding ) : ?>
					<div class="postbox ntpusu-card">
						<div class="inside">
							<div class="ntpusu-section-header">
								<div>
									<h2><?php esc_html_e( '第一次使用', 'ntpusu-regulation-sync' ); ?></h2>
									<p><?php esc_html_e( '照著這幾步走，就能完成第一筆同步。', 'ntpusu-regulation-sync' ); ?></p>
								</div>
								<span class="ntpusu-badge"><?php esc_html_e( '建議流程', 'ntpusu-regulation-sync' ); ?></span>
							</div>
							<ol class="ntpusu-steps">
								<li><strong><?php esc_html_e( '先決定法規來源', 'ntpusu-regulation-sync' ); ?></strong><?php esc_html_e( '最簡單的方式是輸入法規編號；若不確定編號，也可以直接從法規清單選擇。', 'ntpusu-regulation-sync' ); ?></li>
								<li><strong><?php esc_html_e( '指定要更新的內容', 'ntpusu-regulation-sync' ); ?></strong><?php esc_html_e( '右側只會列出有法規標籤的內容；若你已經知道其他內容 ID，也可以直接手動輸入。', 'ntpusu-regulation-sync' ); ?></li>
								<li><strong><?php esc_html_e( '按下立即同步', 'ntpusu-regulation-sync' ); ?></strong><?php esc_html_e( '外掛會抓取最新法規 HTML，儲存同步副本，並更新你指定的內容。', 'ntpusu-regulation-sync' ); ?></li>
								<li><strong><?php esc_html_e( '回到左側確認結果', 'ntpusu-regulation-sync' ); ?></strong><?php esc_html_e( '同步完成後，可在下方查看對應清單、最近同步時間、短代碼與內容預覽。', 'ntpusu-regulation-sync' ); ?></li>
								<li><strong><?php esc_html_e( '需要時直接套用短代碼', 'ntpusu-regulation-sync' ); ?></strong><?php esc_html_e( '如果目標內容還沒有放入短代碼，可在表格操作欄按下「內容改為短代碼」（警告：將把原本文章內容刪除），將會把文章內容直接替換成對應的同步短代碼。', 'ntpusu-regulation-sync' ); ?></li>
							</ol>
						</div>
					</div>
				<?php endif; ?>

				<div class="postbox ntpusu-card">
					<div class="inside">
						<div class="ntpusu-toolbar">
							<div>
								<h2><?php esc_html_e( '已對應內容', 'ntpusu-regulation-sync' ); ?></h2>
								<p>
									<?php if ( $show_manageable_only ) : ?>
										<?php
										printf(
											/* translators: 1: total count, 2: visible count */
											esc_html__( '目前共有 %1$d 筆內容，顯示其中你有權限的 %2$d 筆。', 'ntpusu-regulation-sync' ),
											(int) $mapped_count,
											(int) $visible_mapped_count
										);
										?>
									<?php else : ?>
										<?php
										printf(
											/* translators: %d: mapped count */
											esc_html__( '目前共有 %d 筆內容會跟著法規同步。', 'ntpusu-regulation-sync' ),
											(int) $mapped_count
										);
										?>
									<?php endif; ?>
								</p>
							</div>
							<?php if ( $mapped_count > 0 ) : ?>
								<div class="ntpusu-toolbar-actions">
									<a class="button button-secondary" href="<?php echo esc_url( $toggle_manageable_url ); ?>">
										<?php echo esc_html( $show_manageable_only ? __( '顯示全部', 'ntpusu-regulation-sync' ) : __( '僅顯示有權限', 'ntpusu-regulation-sync' ) ); ?>
									</a>
									<form method="post" action="<?php echo esc_url( $admin_post_url ); ?>">
										<?php wp_nonce_field( 'ntpusu_regulation_sync_sync_all' ); ?>
										<input type="hidden" name="action" value="ntpusu_regulation_sync_sync_all" />
										<?php submit_button( __( '全部同步', 'ntpusu-regulation-sync' ), 'secondary', 'submit', false ); ?>
									</form>
								</div>
							<?php endif; ?>
						</div>

						<?php if ( $mapped_count > 0 ) : ?>
							<?php if ( $visible_mapped_count > 0 ) : ?>
							<div class="ntpusu-table-wrap">
								<table class="widefat striped">
									<thead>
										<tr>
											<th><?php esc_html_e( '內容', 'ntpusu-regulation-sync' ); ?></th>
											<th><?php esc_html_e( '同步來源', 'ntpusu-regulation-sync' ); ?></th>
											<th><?php esc_html_e( '權限', 'ntpusu-regulation-sync' ); ?></th>
											<th><?php esc_html_e( '最近同步', 'ntpusu-regulation-sync' ); ?></th>
											<th><?php esc_html_e( 'shortcode', 'ntpusu-regulation-sync' ); ?></th>
											<th><?php esc_html_e( '操作', 'ntpusu-regulation-sync' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php
										$printed = false;
										foreach ( $visible_mapped_post_ids as $mapped_id ) :
											$post_obj = get_post( $mapped_id );
											if ( ! $post_obj ) {
												ntpusu_regulation_sync_delete_post_payload( $mapped_id );
												continue;
											}
											$printed          = true;
											$source           = ntpusu_regulation_sync_get_mapped_source( $mapped_id );
											$mapped_date      = ntpusu_regulation_sync_get_mapped_updated_at( $mapped_id );
											$shortcode        = sprintf( '[ntpusu_regulation post_id="%d"]', $mapped_id );
											$can_manage       = ntpusu_regulation_sync_user_can_manage_post( $mapped_id );
											$shortcode_applied = trim( (string) $post_obj->post_content ) === $shortcode;
											$action_btn_attrs  = $can_manage ? array() : array( 'disabled' => 'disabled' );
											$replace_btn_attrs = $action_btn_attrs;
											if ( $shortcode_applied ) {
												$replace_btn_attrs['disabled'] = 'disabled';
											}
											?>
											<tr>
												<td>
													<a href="<?php echo esc_url( get_edit_post_link( $mapped_id ) ); ?>">
														<?php echo esc_html( get_the_title( $mapped_id ) ); ?>
													</a>
												</td>
												<td>
													<?php if ( $source ) : ?>
														<a class="ntpusu-source-link" href="<?php echo esc_url( $source ); ?>" target="_blank" rel="noopener">
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
													<code class="ntpusu-shortcode"><?php echo esc_html( $shortcode ); ?></code>
												</td>
												<td>
													<div class="ntpusu-table-actions">
														<a class="button<?php echo $can_manage ? '' : ' disabled'; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ntpusu-regulation-sync', 'edit_map' => $mapped_id ), admin_url( 'admin.php' ) ) ); ?>" <?php disabled( ! $can_manage ); ?>>
															<?php esc_html_e( '編輯', 'ntpusu-regulation-sync' ); ?>
														</a>
														<form method="post" action="<?php echo esc_url( $admin_post_url ); ?>">
															<?php wp_nonce_field( 'ntpusu_regulation_sync_sync_one' ); ?>
															<input type="hidden" name="action" value="ntpusu_regulation_sync_sync_one" />
															<input type="hidden" name="ntpusu_regulation_sync_post" value="<?php echo esc_attr( $mapped_id ); ?>" />
															<?php submit_button( __( '同步', 'ntpusu-regulation-sync' ), 'secondary', 'submit', false, $action_btn_attrs ); ?>
														</form>
														<form method="post" action="<?php echo esc_url( $admin_post_url ); ?>">
															<?php wp_nonce_field( 'ntpusu_regulation_sync_replace_content' ); ?>
															<input type="hidden" name="action" value="ntpusu_regulation_sync_replace_content" />
															<input type="hidden" name="ntpusu_regulation_replace_post" value="<?php echo esc_attr( $mapped_id ); ?>" />
															<?php submit_button( $shortcode_applied ? __( '已套用短代碼', 'ntpusu-regulation-sync' ) : __( '內容改為短代碼', 'ntpusu-regulation-sync' ), 'secondary', 'submit', false, $replace_btn_attrs ); ?>
														</form>
														<form method="post" action="<?php echo esc_url( $admin_post_url ); ?>">
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
												<td colspan="6"><?php esc_html_e( '目前沒有已對應內容。', 'ntpusu-regulation-sync' ); ?></td>
											</tr>
										<?php endif; ?>
									</tbody>
								</table>
							</div>
							<?php else : ?>
								<div class="ntpusu-empty">
									<h3><?php esc_html_e( '目前沒有符合篩選條件的內容', 'ntpusu-regulation-sync' ); ?></h3>
									<p><?php esc_html_e( '已開啟「僅顯示有權限」篩選，但目前沒有任何已對應內容是你可以管理的。', 'ntpusu-regulation-sync' ); ?></p>
								</div>
							<?php endif; ?>
						<?php else : ?>
							<div class="ntpusu-empty">
								<h3><?php esc_html_e( '目前還沒有同步對應', 'ntpusu-regulation-sync' ); ?></h3>
								<p><?php esc_html_e( '第一次使用時，先從右側設定來源與目標內容，再按一次「立即同步」即可建立第一筆對應。', 'ntpusu-regulation-sync' ); ?></p>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( $stored_html ) : ?>
					<div class="postbox ntpusu-card">
						<div class="inside">
							<div class="ntpusu-section-header">
								<div>
									<h2><?php esc_html_e( '最新同步預覽', 'ntpusu-regulation-sync' ); ?></h2>
									<p><?php esc_html_e( '顯示最近一次儲存的 HTML 內容，方便快速檢查同步結果。', 'ntpusu-regulation-sync' ); ?></p>
								</div>
								<span class="ntpusu-badge ntpusu-badge-muted"><?php esc_html_e( '全域副本', 'ntpusu-regulation-sync' ); ?></span>
							</div>
							<div class="ntpusu-preview">
								<?php echo ntpusu_regulation_sync_render_html( $stored_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<div class="ntpusu-sidebar">
				<div class="postbox ntpusu-card">
					<div class="inside">
						<div class="ntpusu-section-header">
							<div>
								<h2><?php esc_html_e( '立即同步', 'ntpusu-regulation-sync' ); ?></h2>
								<p><?php esc_html_e( '右側設定完成後，送出一次就會抓取法規並更新目標內容。', 'ntpusu-regulation-sync' ); ?></p>
							</div>
							<?php if ( $is_editing_map ) : ?>
								<span class="ntpusu-badge ntpusu-badge-warning">
									<?php
									printf(
										/* translators: %s: post title */
										esc_html__( '正在編輯：%s', 'ntpusu-regulation-sync' ),
										esc_html( wp_strip_all_tags( $editing_title ) )
									);
									?>
								</span>
							<?php else : ?>
								<span class="ntpusu-badge ntpusu-badge-muted"><?php esc_html_e( '新增或覆寫同步', 'ntpusu-regulation-sync' ); ?></span>
							<?php endif; ?>
						</div>

						<form method="post" action="<?php echo esc_url( $admin_post_url ); ?>" class="ntpusu-form">
							<?php wp_nonce_field( 'ntpusu_regulation_sync_action' ); ?>
							<input type="hidden" name="action" value="ntpusu_regulation_sync" />

							<div class="ntpusu-form-section">
								<h3><?php esc_html_e( '1. 選擇法規來源', 'ntpusu-regulation-sync' ); ?></h3>
								<p><?php esc_html_e( 'https://cloud.ntpusu.org/regulation/', 'ntpusu-regulation-sync' ); ?></p>

								<div class="ntpusu-choice">
									<label>
										<input type="radio" name="ntpusu_regulation_source" value="id" <?php checked( $last_choice, 'id' ); ?> />
										<span><?php esc_html_e( '輸入法規編號', 'ntpusu-regulation-sync' ); ?></span>
									</label>
									<div class="ntpusu-choice-input">
										<input type="number" min="1" name="ntpusu_regulation_id" placeholder="四位數字" value="<?php echo isset( $_REQUEST['ntpusu_regulation_id'] ) ? esc_attr( wp_unslash( $_REQUEST['ntpusu_regulation_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification ?>" />
									</div>
								</div>

								<div class="ntpusu-choice">
									<label>
										<input type="radio" name="ntpusu_regulation_source" value="list" <?php checked( $last_choice, 'list' ); ?> />
										<span><?php esc_html_e( '從法規清單選擇', 'ntpusu-regulation-sync' ); ?></span>
									</label>
									<div class="ntpusu-choice-input">
										<?php if ( $reg_links ) : ?>
											<select name="ntpusu_regulation_list_url">
												<option value=""><?php esc_html_e( '請選擇法規', 'ntpusu-regulation-sync' ); ?></option>
												<?php foreach ( $reg_links as $link ) : ?>
													<?php $link_regulation_id = ntpusu_regulation_sync_extract_regulation_id( $link['href'] ); ?>
													<option value="<?php echo esc_attr( $link['href'] ); ?>" <?php selected( $last_source === $link['href'] || ( $last_source_regulation_id && $link_regulation_id && $last_source_regulation_id === $link_regulation_id ), true ); ?>><?php echo esc_html( $link['text'] ); ?></option>
												<?php endforeach; ?>
											</select>
										<?php else : ?>
											<span class="description ntpusu-inline-help"><?php esc_html_e( '目前無法載入法規清單，請改用法規編號或自訂來源網址。', 'ntpusu-regulation-sync' ); ?></span>
										<?php endif; ?>
									</div>
								</div>

								<div class="ntpusu-choice">
									<label>
										<input type="radio" name="ntpusu_regulation_source" value="custom" <?php checked( $last_choice, 'custom' ); ?> />
										<span><?php esc_html_e( '自訂來源網址', 'ntpusu-regulation-sync' ); ?></span>
									</label>
									<div class="ntpusu-choice-input">
										<input type="url" name="ntpusu_regulation_custom_url" placeholder="https://example.com/path" value="<?php echo esc_attr( $custom_url ); ?>" />
										<span class="description ntpusu-inline-help"><?php esc_html_e( '若輸入的是法規頁網址，系統會自動轉成對應 API；</br>其他網址則直接抓取該頁內容。', 'ntpusu-regulation-sync' ); ?></span>
									</div>
								</div>
							</div>

							<div class="ntpusu-form-section">
								<h3><?php esc_html_e( '2. 選擇要更新的內容', 'ntpusu-regulation-sync' ); ?></h3>
								<p><?php esc_html_e( '這裡會列出符合條件的內容。若只想更新全域副本，可以保留不對應。', 'ntpusu-regulation-sync' ); ?></p>
								<div class="ntpusu-target-inputs">
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

									<div class="ntpusu-manual-target">
										<label for="ntpusu_regulation_target_post_manual"><?php esc_html_e( '或手動輸入內容 ID', 'ntpusu-regulation-sync' ); ?></label>
										<input type="number" name="ntpusu_regulation_target_post_manual" id="ntpusu_regulation_target_post_manual" min="0" value="<?php echo $edit_map_id ? esc_attr( $edit_map_id ) : ''; ?>" />
									</div>
								</div>
								<p class="description"><?php esc_html_e( '留空時只更新外掛的全域 HTML 副本，不會綁定到特定內容。', 'ntpusu-regulation-sync' ); ?></p>
							</div>

							<div class="ntpusu-form-actions">
								<p class="ntpusu-status-line">
									<?php if ( $formatted_updated_at ) : ?>
										<?php
										printf(
											/* translators: %s: last sync time */
											esc_html__( '最近同步：%s', 'ntpusu-regulation-sync' ),
											esc_html( $formatted_updated_at )
										);
										?>
									<?php else : ?>
										<?php esc_html_e( '目前尚未執行過同步。', 'ntpusu-regulation-sync' ); ?>
									<?php endif; ?>
								</p>
								<?php submit_button( __( '立即同步', 'ntpusu-regulation-sync' ), 'primary', 'ntpusu_regulation_sync_submit', false ); ?>
							</div>
						</form>
					</div>
				</div>

				<div class="postbox ntpusu-card">
					<div class="inside">
						<div class="ntpusu-section-header">
							<div>
								<h2><?php esc_html_e( '同步狀態', 'ntpusu-regulation-sync' ); ?></h2>
							</div>
							<span class="ntpusu-badge ntpusu-badge-muted"><?php esc_html_e( '摘要', 'ntpusu-regulation-sync' ); ?></span>
						</div>

						<dl class="ntpusu-meta-list">
							<dt><?php esc_html_e( '最近同步', 'ntpusu-regulation-sync' ); ?></dt>
							<dd><?php echo $formatted_updated_at ? esc_html( $formatted_updated_at ) : esc_html__( '尚未同步', 'ntpusu-regulation-sync' ); ?></dd>
							<dt><?php esc_html_e( '目前來源', 'ntpusu-regulation-sync' ); ?></dt>
							<dd>
								<?php if ( $last_source ) : ?>
									<a href="<?php echo esc_url( $last_source ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $last_source ); ?></a>
								<?php else : ?>
									<?php esc_html_e( '未知來源', 'ntpusu-regulation-sync' ); ?>
								<?php endif; ?>
							</dd>
							<dt><?php esc_html_e( '已對應數量', 'ntpusu-regulation-sync' ); ?></dt>
							<dd><?php echo esc_html( (string) $mapped_count ); ?></dd>
							<dt><?php esc_html_e( '預覽內容', 'ntpusu-regulation-sync' ); ?></dt>
							<dd><?php echo $stored_html ? esc_html__( '已有最新副本', 'ntpusu-regulation-sync' ) : esc_html__( '尚未儲存副本', 'ntpusu-regulation-sync' ); ?></dd>
						</dl>
					</div>
				</div>

				<div class="postbox ntpusu-card">
					<div class="inside">
						<div class="ntpusu-section-header">
							<div>
								<h2><?php esc_html_e( '排程同步', 'ntpusu-regulation-sync' ); ?></h2>
							</div>
							<span class="ntpusu-badge ntpusu-badge-muted"><?php esc_html_e( '管理設定', 'ntpusu-regulation-sync' ); ?></span>
						</div>

						<form method="post" action="<?php echo esc_url( $admin_post_url ); ?>" class="ntpusu-form">
							<?php wp_nonce_field( 'ntpusu_regulation_sync_toggle_schedule' ); ?>
							<input type="hidden" name="action" value="ntpusu_regulation_sync_toggle_schedule" />
							<label>
								<input type="checkbox" name="ntpusu_regulation_sync_schedule" value="1" <?php checked( $schedule_on ); ?> <?php disabled( ! current_user_can( 'manage_options' ) ); ?> />
								<?php esc_html_e( '啟用排程同步（每日兩次）', 'ntpusu-regulation-sync' ); ?>
							</label>
							<?php
							$schedule_btn_attrs = current_user_can( 'manage_options' ) ? array() : array( 'disabled' => 'disabled' );
							submit_button( __( '儲存排程', 'ntpusu-regulation-sync' ), 'secondary', 'submit', false, $schedule_btn_attrs );
							?>
						</form>

						<dl class="ntpusu-meta-list" style="margin-top:16px;">
							<dt><?php esc_html_e( '目前狀態', 'ntpusu-regulation-sync' ); ?></dt>
							<dd><?php echo $schedule_on ? esc_html__( '已啟用', 'ntpusu-regulation-sync' ) : esc_html__( '未啟用', 'ntpusu-regulation-sync' ); ?></dd>
							<dt><?php esc_html_e( '下次執行', 'ntpusu-regulation-sync' ); ?></dt>
							<dd><?php echo $formatted_next_run ? esc_html( $formatted_next_run ) : esc_html__( '尚未排入佇列', 'ntpusu-regulation-sync' ); ?></dd>
						</dl>

						<div class="ntpusu-aside-note">
							<?php if ( current_user_can( 'manage_options' ) ) : ?>
								<?php esc_html_e( '建議先用手動同步確認來源與發布日期都正確，再開啟排程同步。', 'ntpusu-regulation-sync' ); ?>
							<?php else : ?>
								<?php esc_html_e( '只有管理員可以調整排程同步；若你需要開啟排程，請聯繫網站管理員。', 'ntpusu-regulation-sync' ); ?>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php
}
