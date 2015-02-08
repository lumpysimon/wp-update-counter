<?php
/*
Plugin Name: Update counter
Plugin URI: http://simonblackbourn.net
Description: Keeps count of plugin and theme updates
Author: Simon Blackbourn @ Lumpy Lemon
Version: 0.1
Author URI: http://simonblackbourn.net
*/



/*
TODO: finish basic dashboard widget
TODO: check user capability for dashboard widget visibility
TODO: check if db inserts need to be prepared
TODO: delete data on plugin delete

IDEA: cache total in a transient (flush on update)
IDEA: show update counts on plugins page
IDEA: admin page with stats (last month, all time, etc)
IDEA: ever so pretty graphs
*/



defined( 'ABSPATH' ) or die();



$lluc = new lluc;



class lluc {



	public $db_version = 1;



	function __construct() {

		add_action( 'after_setup_theme',         array( $this, 'register_table_names' ) );
		add_action( 'admin_init',                array( $this, 'create_or_upgrade_tables' ) );
		add_action( 'upgrader_process_complete', array( $this, 'log' ), 10, 2 );
		add_action( 'wp_dashboard_setup',        array( $this, 'add_dashboard_widget' ) );

	}



	function register_table_names() {

		global $wpdb;

		$wpdb->lluc_updates = $wpdb->prefix . 'lluc_updates';
		$wpdb->lluc_items   = $wpdb->prefix . 'lluc_items';

	}



	function create_or_upgrade_tables() {

		global $wpdb;

		$op = 'lluc-dbv';

		if ( get_option( $op ) < $this->db_version ) {

			require_once( ABSPATH . '/wp-admin/includes/upgrade.php' );

			$charset_collate = '';

			if ( ! empty( $wpdb->charset ) ) {
				$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
			}

			if ( ! empty( $wpdb->collate ) ) {
				$charset_collate .= " COLLATE $wpdb->collate";
			}

			$q =
				"
				CREATE TABLE      $wpdb->lluc_updates (
					ID            BIGINT( 20 ) unsigned NOT NULL auto_increment,
					item_id       BIGINT( 20 ) unsigned NOT NULL,
					date          datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
					PRIMARY KEY   ( ID ),
					KEY           ( item_id )
				) $charset_collate;
				";

			dbDelta( $q );

			$q =
				"
				CREATE TABLE      $wpdb->lluc_items (
					ID            BIGINT( 20 ) unsigned NOT NULL auto_increment,
					slug          VARCHAR( 255 ) DEFAULT '' NOT NULL,
					type          VARCHAR( 20 )  DEFAULT '' NOT NULL,
					PRIMARY KEY   ( ID ),
					KEY           ( slug )
				) $charset_collate;
				";

			dbDelta( $q );

			update_option( $op, $this->db_version );

		}

	}



	function get_total() {

		global $wpdb;

		$total = $wpdb->get_var( "SELECT COUNT( * ) FROM $wpdb->lluc_updates" );

		if ( ! is_wp_error( $total ) )
			return $total;

	}



	function get_item_id( $slug ) {

		if ( $item = $this->get_item( $slug ) )
			return $item->ID;

		return false;

	}



	function get_item( $slug ) {

		global $wpdb;

		$q = $wpdb->prepare(
			"
			SELECT     *
			FROM       $wpdb->lluc_items
			WHERE      slug = %s
			",
			$slug
			);

		$item = $wpdb->get_row( $q );

		if ( is_wp_error( $item ) ) {
			if ( defined( 'LLUC_DEBUG' ) and LLUC_DEBUG ) {
				error_log( "LLUC: error getting item" . print_r( $item, true ) );
			}
			return;
		}

		return $item;

	}



	function add_item( $slug, $type ) {

		global $wpdb;

		$ok = $wpdb->insert(
			$wpdb->lluc_items,
			array(
				'slug' => $slug,
				'type' => $type
				),
			array(
				'%s',
				'%s'
				)
			);

		if ( $ok )
			return (int) $wpdb->insert_id;

		return false;

	}



	function add_updates( $slugs, $type ) {

		foreach ( $slugs as $slug ) {
			if ( ! $update_id = $this->add_update( $slug, $type ) ) {
				if ( defined( 'LLUC_DEBUG' ) and LLUC_DEBUG ) {
					error_log( "Lumpy: update error " . print_r( $slug, true ) );
				}
			}
		}

	}



	function add_update( $slug, $type ) {

		global $wpdb;

		if ( ! $item_id = $this->get_item_id( $slug ) ) {
			$item_id = $this->add_item( $slug, $type );
		}

		if ( $item_id ) {

			$ok = $wpdb->insert(
				$wpdb->lluc_updates,
				array(
					'item_id' => $item_id,
					'date'    => current_time( 'mysql' )
					),
				array(
					'%s',
					'%s'
					)
				);

			if ( $ok )
				return (int) $wpdb->insert_id;

		}

		return false;

	}



	function log( $thing, $extra ) {

		if ( ! is_array( $extra ) )
			return;

		if ( 'update' != $extra[ 'action' ] )
			return;

		$type = $extra[ 'type' ];

		if ( isset( $extra[ 'bulk' ] ) and 1 == $extra[ 'bulk' ] ) {
			$slugs = $extra[ $type . 's' ];
		} else {
			$slugs = array( $extra[ $type ] );
		}

		$this->add_updates( $slugs, $type );

	}



	function add_dashboard_widget() {

		wp_add_dashboard_widget(
			'lluc',
			'Update counter',
			array( $this, 'render_dashboard_widget' )
			);

	}



	function render_dashboard_widget() {

		if ( $total = $this->get_total() ) {
			echo sprintf( '<p>Total: %s</p>', $total );
		} else {
			echo '<p>No updates so far</p>';
		}

	}



} // class
