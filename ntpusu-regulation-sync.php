<?php
/**
 * Plugin Name: NTPUSU Regulation Sync
 * Description: Fetches the latest regulation HTML from regsys.ntpusu.org and exposes it via a shortcode so editors can update a section with one click.
 * Version:     1.0.0
 * Author:      AnJen Wu with help of Codex
 * License:     GPL-2.0-or-later
 *
 * @package NTPUSURegulationSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NTPUSU_REGULATION_SYNC_PATH', plugin_dir_path( __FILE__ ) );

require_once NTPUSU_REGULATION_SYNC_PATH . 'includes/constants.php';
require_once NTPUSU_REGULATION_SYNC_PATH . 'includes/helpers.php';
require_once NTPUSU_REGULATION_SYNC_PATH . 'includes/sync-service.php';
require_once NTPUSU_REGULATION_SYNC_PATH . 'includes/admin.php';
require_once NTPUSU_REGULATION_SYNC_PATH . 'includes/shortcode.php';

add_action( NTPUSU_REGULATION_SYNC_CRON_HOOK, 'ntpusu_regulation_sync_run_cron' );
