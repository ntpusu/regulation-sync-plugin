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
			'label' => __( 'Enter regulation ID (uses embed URL)', 'ntpusu-regulation-sync' ),
		),
		'list'   => array(
			'label' => __( 'Choose from regulation list', 'ntpusu-regulation-sync' ),
			'url'   => 'https://regsys.ntpusu.org/regulation/',
		),
		'custom' => array(
			'label' => __( 'Custom page URL', 'ntpusu-regulation-sync' ),
		),
	);
}

/**
 * Fetches regulation links from the public listing page.
 *
 * @return array<int, array{href: string, text: string}>
 */
function ntpusu_regulation_sync_fetch_regulation_links() {
	$response = wp_remote_get(
		'https://regsys.ntpusu.org/regulation/',
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

	if ( ! class_exists( 'DOMDocument' ) ) {
		return array();
	}

	$dom            = new DOMDocument();
	$previous_state = libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $body );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous_state );

	$base = 'https://regsys.ntpusu.org';
	$links = array();
	foreach ( $dom->getElementsByTagName( 'a' ) as $anchor ) {
		$href = $anchor->getAttribute( 'href' );
		if ( ! $href ) {
			continue;
		}

		
		if ( preg_match( '/https:\/\/ntpusu\.org\/\?p=(\d+)/', $href ) ) {
			$text = trim( $anchor->textContent );
		}

		if ( preg_match( '#^/regulation/(\d+)(?:/embed)?/?$#', $href ) ) {
			$links[] = array(
				'href' => ntpusu_regulation_sync_absolute_url( $href, $base ),
				'text' => $text ?: $href,
			);
		}
	}

	return $links;
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
