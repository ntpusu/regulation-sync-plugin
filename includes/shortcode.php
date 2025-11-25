<?php
/**
 * Shortcode implementation for rendering synced regulation content.
 *
 * @package NTPUSURegulationSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'ntpusu_regulation', 'ntpusu_regulation_sync_render_shortcode' );

/**
 * Shortcode callback for [ntpusu_regulation].
 *
 * Attributes:
 * - post_id: Optional. Falls back to the current post if omitted.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function ntpusu_regulation_sync_render_shortcode( $atts = array() ) {
	$atts = shortcode_atts(
		array(
			'post_id' => 0,
		),
		$atts,
		'ntpusu_regulation'
	);

	$post_id = absint( $atts['post_id'] );

	if ( ! $post_id ) {
		$current_post = get_post();
		if ( $current_post instanceof WP_Post ) {
			$post_id = $current_post->ID;
		}
	}

	if ( $post_id ) {
		$post_html = ntpusu_regulation_sync_get_mapped_html( $post_id );
		if ( $post_html ) {
			return '<div class="ntpusu-regulation">' . ntpusu_regulation_sync_render_html( $post_html ) . '</div>';
		}
	}

	$html = get_option( NTPUSU_REGULATION_SYNC_OPTION_HTML );
	if ( empty( $html ) ) {
		return '';
	}

	return '<div class="ntpusu-regulation">' . ntpusu_regulation_sync_render_html( $html ) . '</div>';
}
