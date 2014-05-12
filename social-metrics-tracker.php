<?php
/*
Plugin Name: Social Metrics Tracker
Plugin URI: https://github.com/ChapmanU/wp-social-metrics-tracker
Description: Collect and display social network shares, likes, tweets, and view counts of posts.
Version: 1.0.2
Author: Ben Cole, Chapman University
Author URI: http://www.bencole.net
License: GPLv2+
*/

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2 or, at
 * your discretion, any later version, as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 */


// Class Dependancies
require_once('MetricsUpdater.class.php');
require_once('data-sources/sharedcount.com.php');
require_once('data-sources/google_analytics.php');
include_once('SocialMetricsSettings.class.php');
include_once('SocialMetricsTrackerWidget.class.php');

class SocialMetricsTracker {

	private $version = '1.0.2'; // for db upgrade comparison
	private $updater;
	private $options;

	public function __construct() {

		// Set up options
		$this->options = get_option('smt_settings');

		// Plugin activation hooks
		register_activation_hook( __FILE__, array($this, 'activate') );
		register_deactivation_hook( __FILE__, array($this, 'deactivate') );
		register_uninstall_hook( __FILE__, array($this, 'uninstall') );

		if (is_admin()) {
			add_action('admin_menu', array($this,'adminMenuSetup'));
			add_action('admin_enqueue_scripts', array($this, 'adminHeaderScripts'));
			add_action('plugins_loaded', array($this, 'version_check'));
		}

		// Check if we can enable data syncing
		if (defined('WP_ENV') && strtolower(WP_ENV) != 'production' || $_SERVER['REMOTE_ADDR'] == '127.0.0.1') {
			add_action('admin_notices', array($this, 'developmentServerNotice'));

		} else if (is_array($this->options)) {
			$this->updater = new MetricsUpdater($this->options);
		}

		// Manual data update for a post
		if (is_admin() && $this->updater && $_REQUEST['smt_sync_now']) {
			$this->updater->updatePostStats($_REQUEST['smt_sync_now']);
			header("Location: ".remove_query_arg('smt_sync_now'));
		}

	} // end constructor

	public function developmentServerNotice() {
		if (!current_user_can('manage_options')) return false;

		$screen = get_current_screen();

		if (!in_array($screen->base, array('social-metrics_page_social-metrics-tracker-settings', 'toplevel_page_social-metrics-tracker', 'social-metrics-tracker_page_social-metrics-tracker-debug'))) {
			return false;
		}

		$message = '<h3 style="margin-top:0;">Social Metrics data syncing is disabled</h3> You are on a development server; Social Network share data cannot be retrieved for private development URLs. <ul>';

		if ($_SERVER['REMOTE_ADDR'] == '127.0.0.1') {
			$message .= "<li>The server IP address appears to be set to 127.0.0.1 which is a local address. </li>";
		}

		if (defined('WP_ENV') && strtolower(WP_ENV) != 'production') {
			$message .= "<li>The PHP constant <b>WP_ENV</b> must be set to <b>production</b> or be undefined. WP_ENV is currently set to: <b>".WP_ENV."</b>. </li>";
		}

		$message .= '</ul>';

		printf( '<div class="error"> <p> %s </p> </div>', $message);

	}

	public function adminHeaderScripts() {
		wp_register_style( 'smc_social_metrics_css', plugins_url( 'css/social_metrics.css' , __FILE__ ), false, '11-15-13' );
		wp_enqueue_style( 'smc_social_metrics_css' );
	} // end adminHeaderScripts()

	public function adminMenuSetup() {

		// Add Social Metrics Tracker menu
		$visibility = ($this->options['smt_options_report_visibility']) ? $this->options['smt_options_report_visibility'] : 'manage_options';
		add_menu_page( 'Social Metrics Tracker', 'Social Metrics', $visibility, 'social-metrics-tracker', array($this, 'render_view_Dashboard'), 'dashicons-chart-area', '30.597831' );

		// Add advanced stats menu
		if ($this->options['smt_options_debug_mode']) {
			$debug_visibility = ($this->options['smt_options_debug_report_visibility']) ? $this->options['smt_options_debug_report_visibility'] : 'manage_options';
			add_submenu_page('social-metrics-tracker', 'Relevancy Rank', 'Debug Info', $debug_visibility, 'social-metrics-tracker-debug',  array($this, 'render_view_AdvancedDashboard'));
		}

		new socialMetricsSettings();
		new SocialMetricsTrackerWidget();

	} // end adminMenuSetup()

	public function render_view_Dashboard() {
		require('smt-dashboard.php');
		smt_render_dashboard_view($this->options);
	} // end render_view_Dashboard()

	public function render_view_AdvancedDashboard() {
		require('smt-dashboard-debug.php');
		smt_render_dashboard_debug_view($this->options);
	} // end render_view_AdvancedDashboard()

	public function render_view_Settings() {
		require('smc-settings-view.php');
		smc_render_settings_view();
	} // end render_view_Settings()

	public static function timeago($time) {
		$periods = array("second", "minute", "hour", "day", "week", "month", "year", "decade");
		$lengths = array("60","60","24","7","4.35","12","10");

		$now = time();

			$difference     = $now - $time;
			$tense         = "ago";

		for($j = 0; $difference >= $lengths[$j] && $j < count($lengths)-1; $j++) {
			$difference /= $lengths[$j];
		}

		$difference = round($difference);

		if($difference != 1) {
			$periods[$j].= "s";
		}

		return "$difference $periods[$j] ago";
	}

	/***************************************************
	* Check the version of the plugin and perform upgrade tasks if necessary 
	***************************************************/
	public function version_check() {
		$installed_version = get_option( "smt_version" );

		if( $installed_version != $this->version ) {
			update_option( "smt_version", $this->version );

			// Do upgrade tasks
			$this->db_setup();
		}
	}

	/***************************************************
	* Creates a custom table in the MySQL database for this plugin
	* Can run each time the plugin version needs to be updated. 
	***************************************************/
	private function db_setup () {
	   global $wpdb;
	   require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

	   $table_name = $wpdb->prefix . "social_metrics_log"; 

	   $sql = "CREATE TABLE $table_name (
	     id int(11) unsigned NOT NULL AUTO_INCREMENT,
	     post_id bigint(20) NOT NULL,
	     time_retrieved datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	     facebook int(11) DEFAULT NULL,
	     facebook_shares int(11) DEFAULT NULL,
	     facebook_comments int(11) DEFAULT NULL,
	     facebook_likes int(11) DEFAULT NULL,
	     twitter int(11) DEFAULT NULL,
	     googleplus int(11) DEFAULT NULL,
	     linkedin int(11) DEFAULT NULL,
	     pinterest int(11) DEFAULT NULL,
	     diggs int(11) DEFAULT NULL,
	     delicious int(11) DEFAULT NULL,
	     reddit int(11) DEFAULT NULL,
	     stumbleupon int(11) DEFAULT NULL,
	     socialcount_TOTAL int(11) DEFAULT NULL,
	     UNIQUE KEY id (id)
	   );";
	   
	   dbDelta( $sql );

	}

	public function activate() {
		// Add default settings

		if (get_option('smt_settings') === false) {

			require('settings/smt-general.php');

			global $wpsf_settings;

			// $defaults = array("hello" => "test");

			foreach ($wpsf_settings[0]['fields'] as $setting) {
				$defaults['smt_options_'.$setting['id']] = $setting['std'];
			}

			add_option('smt_settings', $defaults);
		}


		if (defined('WP_ENV') && strtolower(WP_ENV) != 'production' || $_SERVER['REMOTE_ADDR'] == '127.0.0.1') {
			// Do not schedule update
		} else {
			// Sync all data
			MetricsUpdater::scheduleFullDataSync();
		}

		$this->version_check();

	}

	public function deactivate() {

		// Remove Queued Updates
		MetricsUpdater::removeAllQueuedUpdates();

	}

	public function uninstall() {

		// Delete options
		delete_option('smt_settings');

	}

} // END SocialMetricsTracker

// Run plugin
$SocialMetricsTracker = new SocialMetricsTracker();
