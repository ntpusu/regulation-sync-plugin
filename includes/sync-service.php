<?php
/**
 * Handles fetching, processing, and persisting regulation content.
 *
 * @package NTPUSURegulationSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determines the best strategy for fetching HTML from the remote regulation site.
 *
 * @param string $url URL entered or selected by the admin.
 * @return string|WP_Error
 */
function ntpusu_regulation_sync_fetch_html( $url ) {
	$api_attempt = ntpusu_regulation_sync_try_regulation_api( $url );
	if ( $api_attempt instanceof WP_Error ) {
		return $api_attempt;
	}

	if ( null !== $api_attempt ) {
		return $api_attempt;
	}

	return ntpusu_regulation_sync_fetch_remote_html( $url );
}

/**
 * When a regulation detail page is requested, pull the same payload the SPA uses.
 *
 * @param string $url Candidate URL.
 * @return string|WP_Error|null String when fetched, WP_Error on failure, null when URL is not recognised.
 */
function ntpusu_regulation_sync_try_regulation_api( $url ) {
	$api_url = ntpusu_regulation_sync_resolve_regulation_api_url( $url );

	if ( empty( $api_url ) ) {
		return null;
	}

	$response = wp_remote_get(
		$api_url,
		array(
			'timeout' => 20,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code >= 400 ) {
		return new WP_Error(
			'ntpusu_regulation_api_http_error',
			sprintf(
				/* translators: 1: HTTP status code, 2: URL */
				__( 'The regulation API returned HTTP %1$d while requesting %2$s.', 'ntpusu-regulation-sync' ),
				$code,
				esc_url_raw( $api_url )
			)
		);
	}

	$body = wp_remote_retrieve_body( $response );
	if ( empty( $body ) ) {
		return new WP_Error(
			'ntpusu_regulation_api_empty',
			__( 'The regulation API responded without any content.', 'ntpusu-regulation-sync' )
		);
	}

	$data = json_decode( $body, true );
	if ( ! is_array( $data ) ) {
		return new WP_Error(
			'ntpusu_regulation_api_json_error',
			__( 'Unable to decode the regulation API response.', 'ntpusu-regulation-sync' )
		);
	}

	$full_text = $data['fullText'] ?? '';
	if ( empty( $full_text ) ) {
		return new WP_Error(
			'ntpusu_regulation_api_missing_fulltext',
			__( 'The regulation API did not include any HTML content.', 'ntpusu-regulation-sync' )
		);
	}

	$parts = array();

	if ( ! empty( $data['titleFull'] ) ) {
		$parts[] = sprintf(
			'<h2 class="regulation-title wp-block-heading">%s</h2>',
			esc_html( $data['titleFull'] )
		);
	}

	$meta_line_bits = array();
	if ( ! empty( $data['modifiedType'] ) ) {
		$meta_line_bits[] = sanitize_text_field( $data['modifiedType'] );
	}
	if ( ! empty( $data['modifiedDate'] ) ) {
		$meta_line_bits[] = sanitize_text_field( $data['modifiedDate'] );
	}

	if ( ! empty( $meta_line_bits ) ) {
		$parts[] = sprintf(
			'<p class="regulation-meta">%s</p>',
			esc_html( implode( ' · ', $meta_line_bits ) )
		);
	}

	$parts[] = $full_text;

	if ( ! empty( $data['history'] ) && is_array( $data['history'] ) ) {
		$history_items = '';
		foreach ( $data['history'] as $history ) {
			$history_items .= sprintf(
				'<li>%s</li>',
				wp_kses_post( $history )
			);
		}

		if ( $history_items ) {
			$parts[] = sprintf(
				'<div class="regulation-history"><h2 class="wp-block-heading">%s</h2><ul>%s</ul></div>',
				esc_html__( '沿革', 'ntpusu-regulation-sync' ),
				$history_items
			);
		}
	}

	return implode( '', $parts );
}

/**
 * Generates the API endpoint that exposes the fully rendered regulation markup.
 *
 * @param string $url Admin-provided URL.
 * @return string Empty string when not supported.
 */
function ntpusu_regulation_sync_resolve_regulation_api_url( $url ) {
	$parts = wp_parse_url( $url );

	if ( empty( $parts['host'] ) ) {
		return '';
	}

	$host = strtolower( $parts['host'] );
	if ( false === strpos( $host, 'regsys.ntpusu.org' ) ) {
		return '';
	}

	$path = $parts['path'] ?? '';
	if ( preg_match( '#^/api/regulation/(\d+)/?#', $path, $matches ) ) {
		return NTPUSU_REGULATION_SYNC_BASE_URL . '/api/regulation/' . $matches[1];
	}

	if ( preg_match( '#^/regulation/(\d+)(?:/embed)?/?$#', $path, $matches ) ) {
		return NTPUSU_REGULATION_SYNC_BASE_URL . '/api/regulation/' . $matches[1];
	}

	return '';
}

/**
 * Fetches HTML from the remote endpoint using the WordPress HTTP API.
 *
 * @param string $url URL to request.
 * @return string|WP_Error
 */
function ntpusu_regulation_sync_fetch_remote_html( $url ) {
	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 20,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code >= 400 ) {
		return new WP_Error(
			'ntpusu_regulation_http_error',
			sprintf(
				/* translators: %d: HTTP status code */
				__( 'The remote server returned HTTP %d.', 'ntpusu-regulation-sync' ),
				$code
			)
		);
	}

	$body = wp_remote_retrieve_body( $response );
	if ( empty( $body ) ) {
		return new WP_Error(
			'ntpusu_regulation_empty',
			__( 'The remote site responded without any content.', 'ntpusu-regulation-sync' )
		);
	}

	return ntpusu_regulation_sync_prepare_html( $body, $url );
}

/**
 * Extracts the body HTML and normalises resource URLs so assets load from the original host.
 *
 * @param string $html Raw remote HTML.
 * @param string $source_url Source URL for absolute path rewrites.
 * @return string
 */
function ntpusu_regulation_sync_prepare_html( $html, $source_url ) {
	if ( false === stripos( $html, '<body' ) ) {
		return $html;
	}

	if ( ! class_exists( 'DOMDocument' ) ) {
		return $html;
	}

	$dom            = new DOMDocument();
	$previous_state = libxml_use_internal_errors( true );

	// Prepend XML header so UTF-8 content renders properly.
	$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous_state );

	$base_url = ntpusu_regulation_sync_base_url( $source_url );
	if ( $base_url ) {
		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( '//*[@href or @src]' );
		if ( $nodes instanceof DOMNodeList ) {
			foreach ( $nodes as $node ) {
				if ( $node->hasAttribute( 'href' ) ) {
					$node->setAttribute(
						'href',
						ntpusu_regulation_sync_absolute_url( $node->getAttribute( 'href' ), $base_url )
					);
				}
				if ( $node->hasAttribute( 'src' ) ) {
					$node->setAttribute(
						'src',
						ntpusu_regulation_sync_absolute_url( $node->getAttribute( 'src' ), $base_url )
					);
				}
			}
		}
	}

	$body = $dom->getElementsByTagName( 'body' )->item( 0 );
	if ( ! $body ) {
		return $html;
	}

	$buffer = '';
	foreach ( $body->childNodes as $child ) {
		$buffer .= $dom->saveHTML( $child );
	}

	return $buffer;
}

/**
 * Builds an absolute URL relative to the provided base host.
 *
 * @param string $url  Possibly relative URL.
 * @param string $base Host + scheme to prefix when needed.
 * @return string
 */
function ntpusu_regulation_sync_absolute_url( $url, $base ) {
	$url = trim( $url );

	if ( '' === $url || str_starts_with( $url, 'data:' ) || str_starts_with( $url, 'mailto:' ) ) {
		return $url;
	}

	if (
		preg_match( '#^(https?:)?//#i', $url ) ||
		str_starts_with( $url, '#' )
	) {
		return $url;
	}

	if ( str_starts_with( $url, '/' ) ) {
		return rtrim( $base, '/' ) . $url;
	}

	return rtrim( $base, '/' ) . '/' . ltrim( $url, '/' );
}

/**
 * Returns a base URL (scheme + host + optional port) for rewriting asset URLs.
 *
 * @param string $source_url Remote source.
 * @return string
 */
function ntpusu_regulation_sync_base_url( $source_url ) {
	$parts = wp_parse_url( $source_url );

	if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}

	$base = $parts['scheme'] . '://' . $parts['host'];

	if ( ! empty( $parts['port'] ) ) {
		$base .= ':' . $parts['port'];
	}

	return $base;
}

/**
 * Persists the synced HTML and metadata against a WordPress post.
 *
 * @param int    $post_id    Post ID.
 * @param string $source_url Remote source URL.
 * @param string $html       Synced HTML.
 */
function ntpusu_regulation_sync_save_post_payload( $post_id, $source_url, $html ) {
	update_post_meta( $post_id, NTPUSU_REGULATION_SYNC_META_HTML, $html );
	update_post_meta( $post_id, NTPUSU_REGULATION_SYNC_META_SOURCE, esc_url_raw( $source_url ) );
	update_post_meta( $post_id, NTPUSU_REGULATION_SYNC_META_UPDATED_AT, time() );
	ntpusu_regulation_sync_add_mapped_post_id( $post_id );
}

/**
 * Deletes the synced data for a WordPress post.
 *
 * @param int $post_id Post ID.
 */
function ntpusu_regulation_sync_delete_post_payload( $post_id ) {
	delete_post_meta( $post_id, NTPUSU_REGULATION_SYNC_META_HTML );
	delete_post_meta( $post_id, NTPUSU_REGULATION_SYNC_META_SOURCE );
	delete_post_meta( $post_id, NTPUSU_REGULATION_SYNC_META_UPDATED_AT );
	ntpusu_regulation_sync_remove_mapped_post_id( $post_id );
}

/**
 * Stores the list of mapped post IDs in an option so the admin screen can surface them quickly.
 *
 * @return int[]
 */
function ntpusu_regulation_sync_get_mapped_post_ids() {
	$ids = get_option( NTPUSU_REGULATION_SYNC_OPTION_MAPPED_POST_IDS, array() );
	$ids = array_filter( array_map( 'absint', (array) $ids ) );

	return array_values( array_unique( $ids ) );
}

/**
 * Registers a post ID as mapped.
 *
 * @param int $post_id Post ID.
 */
function ntpusu_regulation_sync_add_mapped_post_id( $post_id ) {
	$post_id = absint( $post_id );

	if ( ! $post_id ) {
		return;
	}

	$ids = ntpusu_regulation_sync_get_mapped_post_ids();

	if ( in_array( $post_id, $ids, true ) ) {
		return;
	}

	$ids[] = $post_id;
	update_option( NTPUSU_REGULATION_SYNC_OPTION_MAPPED_POST_IDS, $ids );
}

/**
 * Removes a post ID from the mapping list.
 *
 * @param int $post_id Post ID.
 */
function ntpusu_regulation_sync_remove_mapped_post_id( $post_id ) {
	$post_id = absint( $post_id );

	if ( ! $post_id ) {
		return;
	}

	$ids = ntpusu_regulation_sync_get_mapped_post_ids();
	$ids = array_values( array_diff( $ids, array( $post_id ) ) );

	update_option( NTPUSU_REGULATION_SYNC_OPTION_MAPPED_POST_IDS, $ids );
}

/**
 * Returns the saved HTML for a mapped post.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function ntpusu_regulation_sync_get_mapped_html( $post_id ) {
	return (string) get_post_meta( $post_id, NTPUSU_REGULATION_SYNC_META_HTML, true );
}

/**
 * Returns the stored source URL for a mapped post.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function ntpusu_regulation_sync_get_mapped_source( $post_id ) {
	return (string) get_post_meta( $post_id, NTPUSU_REGULATION_SYNC_META_SOURCE, true );
}

/**
 * Returns the last updated timestamp for a mapped post or zero.
 *
 * @param int $post_id Post ID.
 * @return int
 */
function ntpusu_regulation_sync_get_mapped_updated_at( $post_id ) {
	return (int) get_post_meta( $post_id, NTPUSU_REGULATION_SYNC_META_UPDATED_AT, true );
}

/**
 * Refreshes a single mapped post from its stored source.
 *
 * @param int  $post_id              Post ID.
 * @param bool $respect_permissions  Whether to enforce user capability checks.
 * @return true|WP_Error
 */
function ntpusu_regulation_sync_refresh_post( $post_id, $respect_permissions = true ) {
	$post_id = absint( $post_id );
	if ( ! $post_id || ! get_post( $post_id ) ) {
		return new WP_Error( 'ntpusu_regulation_invalid_post', __( 'Invalid post for sync.', 'ntpusu-regulation-sync' ) );
	}

	if ( $respect_permissions && ! ntpusu_regulation_sync_user_can_manage_post( $post_id ) ) {
		return new WP_Error( 'ntpusu_regulation_no_permission', __( 'You do not have permission to sync this mapping.', 'ntpusu-regulation-sync' ) );
	}

	$source = ntpusu_regulation_sync_get_mapped_source( $post_id );
	if ( ! $source ) {
		return new WP_Error( 'ntpusu_regulation_missing_source', __( 'No source URL is stored for this mapping.', 'ntpusu-regulation-sync' ) );
	}

	$result = ntpusu_regulation_sync_fetch_html( $source );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	ntpusu_regulation_sync_save_post_payload( $post_id, $source, $result );
	return true;
}

/**
 * Refreshes all mapped posts. Optionally respects current-user permissions.
 *
 * @param bool $respect_permissions Whether to enforce user capability checks.
 * @return array{synced:int, skipped:int, errors:string[]}
 */
function ntpusu_regulation_sync_sync_all_mappings( $respect_permissions = true ) {
	$ids    = ntpusu_regulation_sync_get_mapped_post_ids();
	$result = array(
		'synced'  => 0,
		'skipped' => 0,
		'errors'  => array(),
	);

	foreach ( $ids as $id ) {
		if ( $respect_permissions && ! ntpusu_regulation_sync_user_can_manage_post( $id ) ) {
			$result['skipped']++;
			continue;
		}

		$sync = ntpusu_regulation_sync_refresh_post( $id, false );
		if ( is_wp_error( $sync ) ) {
			$result['errors'][] = sprintf(
				/* translators: 1: post ID, 2: error message */
				__( 'Post %1$d: %2$s', 'ntpusu-regulation-sync' ),
				$id,
				$sync->get_error_message()
			);
			continue;
		}

		$result['synced']++;
	}

	return $result;
}

/**
 * Cron callback to sync all mappings.
 */
function ntpusu_regulation_sync_run_cron() {
	ntpusu_regulation_sync_sync_all_mappings( false );
}

/**
 * Ensures the scheduled event matches the toggle state.
 *
 * @param bool $enable Whether to enable scheduled syncs.
 */
function ntpusu_regulation_sync_update_schedule( $enable ) {
	$enabled = (bool) $enable;
	update_option( NTPUSU_REGULATION_SYNC_OPTION_SCHEDULED, $enabled ? 1 : 0 );

	$scheduled = wp_next_scheduled( NTPUSU_REGULATION_SYNC_CRON_HOOK );
	if ( $enabled && ! $scheduled ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS * 5, 'twicedaily', NTPUSU_REGULATION_SYNC_CRON_HOOK );
	} elseif ( ! $enabled && $scheduled ) {
		wp_clear_scheduled_hook( NTPUSU_REGULATION_SYNC_CRON_HOOK );
	}
}
