<?php

/**
 * Plugin Name: Moylgrove Forms
 * Description: Take details of event bookings
 * Version: 1.0.1
 * Author: Alan Cameron Wills
 * Licence: GPLv2
 * Requires Plugins: wp-paypal
 */


// https://codex.wordpress.org/Creating_Tables_with_Plugins
// https://developer.wordpress.org/reference/classes/wpdb/

global $moylgrove_forms_version;
$moylgrove_forms_version = '1.0.1';


	const MG_BOOK_STATUS = 1;
	const MG_BOOKED_STATUS = 2;
	const MG_CANCELLED_STATUS = 3;
	const MG_PAY_STATUS = 4;

function moylgrove_forms_install() {
	global $wpdb;
	global $moylgrove_forms_version;

	$table_name = $wpdb->prefix . 'moylgrove_forms';
	
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		name tinytext NOT NULL,
		text text NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql ); // preserve if already exists

	add_option( 'moylgrove_forms_version', $moylgrove_forms_version );
	
	// Hook to purchase button, which should be a wp_paypal_checkout
	add_action('wp_paypal_checkout_order_processed', 'moylgrove_forms_checkout_processed', 10, 2);
	add_filter('wppaypal_checkout_custom_input', 'moylgrove_forms_getOrderIdInBuyButton', 10, 3 );
	add_action('wp_paypal_checkout_process_order', 'moylgrove_forms_checkout_process' , 10, 1);
}
register_activation_hook( __FILE__, 'moylgrove_forms_install' );

// Update when version changes 
function myplugin_update_db_check() {
    global $moylgrove_forms_version;
    if ( get_site_option( '$moylgrove_forms_version' ) != $moylgrove_forms_version ) {
        moylgrove_forms_install();
    }
}
add_action( 'plugins_loaded', 'myplugin_update_db_check' );

// Deactivation hook
function moylgrove_forms_deactivate() {
	remove_action('wp_paypal_checkout_order_processed', 'moylgrove_forms_checkout_processed');
	remove_filter('wppaypal_checkout_custom_input', 'moylgrove_forms_getOrderIdInBuyButton');
	remove_action('wp_paypal_checkout_process_order', 'moylgrove_forms_checkout_process');
}
register_deactivation_hook( __FILE__, 'moylgrove_forms_deactivate' );

function moylgrove_forms_uninstall() {
	delete_option('moylgrove_forms_version');

	// drop our database table
	global $wpdb;
	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}moylgrove_forms");
}
register_uninstall_hook(__FILE__, 'moylgrove_forms_uninstall');

function moylgrove_forms_checkout_process($post_data) {
	// error_log("PayPal response \n" . print_r($post_data, true), 1, "alan@cameronwills.org");
}

function moylgrove_forms_checkout_processed($payment_data, $details) {
	global $wpdb;
	$table_name = $wpdb->prefix . 'moylgrove_forms';
	
	$id = $payment_data['custom'];
	
	// error_log("moylgrove_forms_checkout_processed id=" . print_r($id));

	$rows = isset($id) ? $wpdb->get_results($wpdb->prepare(
		"SELECT * FROM $table_name WHERE id = %s", $id) )
		: [];

    $data = isset($rows[0]) ? json_decode($rows[0]->text, true) : [];

	$previous= isset($data['paid']) ? (float)$data['paid'] : 0;
	
	$name = isset($rows[0]) ?  $rows[0]->name : "";
	
	// error_log("moylgrove_forms_checkout_processed name=" . $name);

	$data['paid'] = number_format(round((float)$payment_data['mc_gross'] + $previous,2),2);
                  
	$text = json_encode($data);	

	$wpdb->replace($table_name, [
		'id'=>$id,
		'time' => current_time( 'mysql' ), 
		'name' => $name, 
		'text' => $text
	]);
	
	// error_log("Moylgrove checkout_processed " . print_r($payment_data, true));
}

function moylgrove_forms_getOrderIdInBuyButton($custom_input_code, $button_code, $atts){
	$customValue = isset($atts['custom']) ? esc_attr($atts['custom']) : "0";
	return '<input class="wppaypal_checkout_custom_input" type="hidden" name="custom" value="'.$customValue.'" required>';
}

/********************************************/
$mgfdir = plugin_dir_path( __FILE__ );
include_once($mgfdir . 'moylgrove-forms-dump.php');
include_once($mgfdir . 'moylgrove-forms-table.php');
include_once($mgfdir . 'moylgrove-forms-layout.php');
include_once($mgfdir . 'moylgrove-forms-custom-form.php');
include_once($mgfdir . 'moylgrove-forms-standard-form.php');
if(is_admin()){
	include_once($mgfdir . 'moylgrove-forms-admin.php');
}

/********************************************/


?>