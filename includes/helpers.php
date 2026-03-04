<?php
/**
 * Shared helper functions.
 *
 * @package NTPUSURegulationSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Predefined remote sources the admin can pull from.
 *
 * @return array<string, array{label: string, url: string}>
 */
function ntpusu_regulation_sync_sources() {
	return array(
		'id'     => array(
			'label' => __( 'Enter regulation ID', 'ntpusu-regulation-sync' ),
		),
		'list'   => array(
			'label' => __( 'Choose from regulation list', 'ntpusu-regulation-sync' ),
			'url'   => NTPUSU_REGULATION_SYNC_BASE_URL . '/api/regulation/list',
		),
		'custom' => array(
			'label' => __( 'Custom page URL', 'ntpusu-regulation-sync' ),
		),
	);
}

/**
 * Checks whether the host belongs to the regulation system.
 *
 * @param string $host Parsed URL host.
 * @return bool
 */
function ntpusu_regulation_sync_is_supported_host( $host ) {
	$host = strtolower( (string) $host );

	if ( '' === $host ) {
		return false;
	}

	$base_parts = wp_parse_url( NTPUSU_REGULATION_SYNC_BASE_URL );
	$base_host  = strtolower( (string) ( $base_parts['host'] ?? '' ) );

	return '' !== $base_host && $host === $base_host;
}

/**
 * Builds the canonical regulation detail URL.
 *
 * @param int $regulation_id Regulation ID.
 * @return string
 */
function ntpusu_regulation_sync_build_regulation_url( $regulation_id ) {
	return trailingslashit( NTPUSU_REGULATION_SYNC_BASE_URL ) . 'regulation/' . absint( $regulation_id );
}

/**
 * Builds the regulation single-item API URL.
 *
 * @param int $regulation_id Regulation ID.
 * @return string
 */
function ntpusu_regulation_sync_build_regulation_api_url( $regulation_id ) {
	return trailingslashit( NTPUSU_REGULATION_SYNC_BASE_URL ) . 'api/regulation/single/' . absint( $regulation_id );
}

/**
 * Extracts a regulation ID from a canonical page URL or API URL.
 *
 * @param string $url Candidate URL.
 * @return int
 */
function ntpusu_regulation_sync_extract_regulation_id( $url ) {
	$url = trim( (string) $url );

	if ( '' === $url ) {
		return 0;
	}

	$parts = wp_parse_url( $url );

	if ( ! empty( $parts['host'] ) && ! ntpusu_regulation_sync_is_supported_host( $parts['host'] ) ) {
		return 0;
	}

	$path = $parts['path'] ?? '';
	if ( '' === $path ) {
		return 0;
	}

	if ( preg_match( '#^/api/regulation/single/(\d+)/?$#', $path, $matches ) ) {
		return absint( $matches[1] );
	}

	if ( preg_match( '#^/regulation/(\d+)(?:/embed)?/?$#', $path, $matches ) ) {
		return absint( $matches[1] );
	}

	return 0;
}

/**
 * Parses a regulation modified date into a Unix timestamp.
 *
 * The API currently returns dates like YYYY<year>MM<month>DD<day> in Chinese.
 *
 * @param string $date_string API date string.
 * @return int|null
 */
function ntpusu_regulation_sync_parse_regulation_modified_date( $date_string ) {
	$date_string = trim( wp_strip_all_tags( (string) $date_string ) );

	if ( '' === $date_string ) {
		return null;
	}

	if ( preg_match( '/^(\d{4})\x{5E74}(\d{1,2})\x{6708}(\d{1,2})\x{65E5}$/u', $date_string, $matches ) ) {
		$local_date = sprintf(
			'%04d-%02d-%02d 00:00:00',
			(int) $matches[1],
			(int) $matches[2],
			(int) $matches[3]
		);

		$gmt_date = get_gmt_from_date( $local_date );
		if ( $gmt_date ) {
			$timestamp = strtotime( $gmt_date . ' UTC' );
			if ( false !== $timestamp ) {
				return $timestamp;
			}
		}
	}

	$timestamp = strtotime( $date_string );

	return false === $timestamp ? null : $timestamp;
}

/**
 * Fetches regulation links from the regulation list API.
 *
 * @return array<int, array{href: string, text: string}>
 */
function ntpusu_regulation_sync_fetch_regulation_links() {
	$response = wp_remote_get(
		trailingslashit( NTPUSU_REGULATION_SYNC_BASE_URL ) . 'api/regulation/list',
		array(
			'timeout' => 15,
		)
	);

	if ( is_wp_error( $response ) ) {
		return array();
	}

	$body = wp_remote_retrieve_body( $response );
	if ( empty( $body ) ) {
		return array();
	}

	$data = json_decode( $body, true );
	if ( ! is_array( $data ) ) {
		return array();
	}

	$links = array();
	foreach ( $data as $entry ) {
		if ( ! is_array( $entry ) || count( $entry ) < 2 ) {
			continue;
		}

		$regulation_id = absint( $entry[0] );
		$label         = sanitize_text_field( (string) $entry[1] );

		if ( ! $regulation_id || '' === $label ) {
			continue;
		}

		$links[] = array(
			'href' => ntpusu_regulation_sync_build_regulation_url( $regulation_id ),
			'text' => $label,
		);
	}

	return $links;
}

/**
 * Returns admin-selectable WordPress content types for regulation mapping.
 *
 * @return array<string, WP_Post_Type>
 */
function ntpusu_regulation_sync_get_selectable_post_types() {
	$post_types = get_post_types(
		array(
			'show_ui' => true,
		),
		'objects'
	);

	$excluded_types = array(
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_navigation',
		'wp_font_face',
		'wp_font_family',
		'wp_global_styles',
	);

	$selectable = array();

	foreach ( $post_types as $post_type => $post_type_object ) {
		if ( in_array( $post_type, $excluded_types, true ) ) {
			continue;
		}

		if ( empty( $post_type_object->public ) && empty( $post_type_object->publicly_queryable ) ) {
			continue;
		}

		$edit_cap = $post_type_object->cap->edit_posts ?? 'edit_posts';
		if ( ! current_user_can( $edit_cap ) && ! current_user_can( 'manage_options' ) ) {
			continue;
		}

		$selectable[ $post_type ] = $post_type_object;
	}

	return $selectable;
}

/**
 * Returns selectable posts/pages/items for the admin mapping dropdown.
 *
 * Tag filters apply to the default WordPress post tag taxonomy (`post_tag`).
 * You may pass a single tag ID, a single tag slug, or a list of either.
 *
 * @param int                 $selected_post_id Currently selected post ID.
 * @param int|string|array    $tag_filter       Optional tag ID, slug, or list of IDs/slugs.
 * @return array<int, array{id:int,title:string,type:string,type_label:string,status:string,status_label:string}>
 */
function ntpusu_regulation_sync_get_selectable_posts( $selected_post_id = 0, $tag_filter = array() ) {
	$post_type_objects = ntpusu_regulation_sync_get_selectable_post_types();

	if ( empty( $post_type_objects ) ) {
		return array();
	}

	$tag_ids   = array();
	$tag_slugs = array();

	foreach ( (array) $tag_filter as $tag_item ) {
		if ( is_numeric( $tag_item ) ) {
			$tag_ids[] = absint( $tag_item );
			continue;
		}

		$tag_item = sanitize_title( (string) $tag_item );
		if ( '' !== $tag_item ) {
			$tag_slugs[] = $tag_item;
		}
	}

	$tag_ids   = array_values( array_filter( array_unique( $tag_ids ) ) );
	$tag_slugs = array_values( array_filter( array_unique( $tag_slugs ) ) );

	$query_args = array(
		'post_type'        => array_keys( $post_type_objects ),
		'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
		'numberposts'      => 500,
		'orderby'          => 'modified',
		'order'            => 'DESC',
		'suppress_filters' => false,
	);

	if ( $tag_ids || $tag_slugs ) {
		$query_args['tax_query'] = array(
			'relation' => 'OR',
		);

		if ( $tag_ids ) {
			$query_args['tax_query'][] = array(
				'taxonomy' => 'post_tag',
				'field'    => 'term_id',
				'terms'    => $tag_ids,
			);
		}

		if ( $tag_slugs ) {
			$query_args['tax_query'][] = array(
				'taxonomy' => 'post_tag',
				'field'    => 'slug',
				'terms'    => $tag_slugs,
			);
		}
	}

	$items = array();
	$posts = get_posts( $query_args );

	foreach ( $posts as $post ) {
		if ( ! ntpusu_regulation_sync_user_can_manage_post( $post->ID ) ) {
			continue;
		}

		$type_object  = $post_type_objects[ $post->post_type ] ?? null;
		$status_object = get_post_status_object( $post->post_status );

		$items[ $post->ID ] = array(
			'id'           => $post->ID,
			'title'        => '' !== $post->post_title ? $post->post_title : sprintf( __( 'Untitled #%d', 'ntpusu-regulation-sync' ), $post->ID ),
			'type'         => $post->post_type,
			'type_label'   => $type_object ? $type_object->labels->singular_name : $post->post_type,
			'status'       => $post->post_status,
			'status_label' => $status_object && ! empty( $status_object->label ) ? $status_object->label : $post->post_status,
		);
	}

	$selected_post_id = absint( $selected_post_id );
	if ( $selected_post_id && ! isset( $items[ $selected_post_id ] ) ) {
		$selected_post = get_post( $selected_post_id );
		if ( $selected_post && ntpusu_regulation_sync_user_can_manage_post( $selected_post_id ) ) {
			$type_object   = $post_type_objects[ $selected_post->post_type ] ?? null;
			$status_object = get_post_status_object( $selected_post->post_status );

			$items[ $selected_post_id ] = array(
				'id'           => $selected_post_id,
				'title'        => '' !== $selected_post->post_title ? $selected_post->post_title : sprintf( __( 'Untitled #%d', 'ntpusu-regulation-sync' ), $selected_post_id ),
				'type'         => $selected_post->post_type,
				'type_label'   => $type_object ? $type_object->labels->singular_name : $selected_post->post_type,
				'status'       => $selected_post->post_status,
				'status_label' => $status_object && ! empty( $status_object->label ) ? $status_object->label : $selected_post->post_status,
			);
		}
	}

	$items = array_values( $items );

	usort(
		$items,
		static function ( $left, $right ) {
			$type_compare = strcasecmp( $left['type_label'], $right['type_label'] );
			if ( 0 !== $type_compare ) {
				return $type_compare;
			}

			return strcasecmp( $left['title'], $right['title'] );
		}
	);

	return $items;
}

/**
 * Checks whether the current user may manage a mapping for a given post.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function ntpusu_regulation_sync_user_can_manage_post( $post_id ) {
	return current_user_can( 'manage_options' ) || current_user_can( 'edit_post', $post_id );
}

/**
 * Saves an admin notice message that will be displayed on the plugin page.
 *
 * @param string $type    success|error|warning|info.
 * @param string $message Message body.
 */
function ntpusu_regulation_sync_store_notice( $type, $message ) {
	set_transient(
		NTPUSU_REGULATION_SYNC_NOTICE_TRANSIENT,
		array(
			'type'    => $type,
			'message' => $message,
		),
		30
	);
}

/**
 * Sanitizes the stored HTML before rendering on the front end.
 *
 * @param string $html Stored markup.
 * @return string
 */
function ntpusu_regulation_sync_render_html( $html ) {
	$allowed = wp_kses_allowed_html( 'post' );

	$structural_tags = array( 'div', 'span', 'p', 'section', 'article', 'header', 'footer', 'ol', 'ul', 'li', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'sup', 'sub' );
	foreach ( $structural_tags as $tag ) {
		if ( ! isset( $allowed[ $tag ] ) ) {
			$allowed[ $tag ] = array();
		}

		$allowed[ $tag ]['class'] = true;
	}

	if ( ! isset( $allowed['a'] ) ) {
		$allowed['a'] = array();
	}
	$allowed['a']['target'] = true;
	$allowed['a']['rel']    = true;

	// Allow safe script/link tags so Nuxt embed assets load correctly.
	$allowed['script'] = array(
		'type'           => true,
		'src'            => true,
		'async'          => true,
		'defer'          => true,
		'crossorigin'    => true,
		'referrerpolicy' => true,
		'data-nuxt-data' => true,
	);

	$allowed['link'] = array(
		'rel'         => true,
		'href'        => true,
		'crossorigin' => true,
		'type'        => true,
		'as'          => true,
		'sizes'       => true,
	);

	$allowed['style'] = array(
		'type' => true,
	);

	$allowed['noscript'] = array();

	return wp_kses( $html, $allowed );
}

/**
 * Returns the admin URL for the plugin page.
 *
 * @return string
 */
function ntpusu_regulation_sync_admin_page_url() {
	return admin_url( 'admin.php?page=ntpusu-regulation-sync' );
}

if ( ! function_exists( 'str_starts_with' ) ) {
	/**
	 * Polyfill for PHP < 8 str_starts_with.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @return bool
	 */
	function str_starts_with( $haystack, $needle ) {
		return 0 === strncmp( $haystack, $needle, strlen( $needle ) );
	}
}
