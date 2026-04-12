<?php

/**
 * Plugin Name: Moylgrove Forms
 * Description: Take details of event bookings
 * Version: 1.0.1
 * Author: Alan Cameron Wills
 * Licence: GPLv2
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

register_deactivation_hook( __FILE__, '__return_false' );

function moylgrove_forms_uninstall() {
	delete_option('moylgrove_forms_version');

	// drop our database table
	global $wpdb;
	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}moylgrove_forms");
}
register_uninstall_hook(__FILE__, 'moylgrove_forms_uninstall');

// ---- PayPal REST API endpoints ----
add_action('rest_api_init', 'moylgrove_register_rest_routes');

function moylgrove_register_rest_routes() {
	register_rest_route('moylgrove/v1', '/create-order', [
		'methods'             => 'POST',
		'callback'            => 'moylgrove_create_paypal_order',
		'permission_callback' => '__return_true',
	]);
	register_rest_route('moylgrove/v1', '/capture-order', [
		'methods'             => 'POST',
		'callback'            => 'moylgrove_capture_paypal_order',
		'permission_callback' => '__return_true',
	]);
}

function moylgrove_paypal_base_url() {
	$mode = get_option('moylgrove_paypal_mode', 'sandbox');
	return $mode === 'live'
		? 'https://api-m.paypal.com'
		: 'https://api-m.sandbox.paypal.com';
}

function moylgrove_get_paypal_token() {
	$mode      = get_option('moylgrove_paypal_mode', 'sandbox');
	$client_id = get_option("moylgrove_paypal_client_id_$mode", '');
	$secret    = get_option("moylgrove_paypal_secret_$mode", '');

	if (!$client_id || !$secret) {
		return new WP_Error('no_credentials', 'PayPal credentials not configured', ['status' => 500]);
	}

	$base_url = moylgrove_paypal_base_url();
	$response = wp_remote_post("$base_url/v1/oauth2/token", [
		'headers' => [
			'Authorization' => 'Basic ' . base64_encode("$client_id:$secret"),
			'Content-Type'  => 'application/x-www-form-urlencoded',
		],
		'body' => 'grant_type=client_credentials',
	]);

	if (is_wp_error($response)) return $response;
	$body = json_decode(wp_remote_retrieve_body($response), true);
	if (empty($body['access_token'])) {
		return new WP_Error('token_error', 'Failed to get PayPal access token', ['status' => 500]);
	}
	return $body['access_token'];
}

function moylgrove_create_paypal_order(WP_REST_Request $request) {
	global $wpdb;
	$id = intval($request->get_param('id'));
	if (!$id) return new WP_Error('no_id', 'Missing booking id', ['status' => 400]);

	$table = $wpdb->prefix . 'moylgrove_forms';
	$row   = $wpdb->get_row($wpdb->prepare("SELECT text FROM $table WHERE id = %d", $id));
	if (!$row) return new WP_Error('not_found', 'Booking not found', ['status' => 404]);

	$data       = json_decode($row->text, true);
	$price      = (float)($data['totalPrice'] ?? 0);
	$paid       = (float)($data['paid']       ?? 0);
	$amount_due = round($price - $paid, 2);

	if ($amount_due <= 0) return new WP_Error('nothing_due', 'Nothing to pay', ['status' => 400]);

	$token = moylgrove_get_paypal_token();
	if (is_wp_error($token)) return $token;

	$base_url = moylgrove_paypal_base_url();
	$response = wp_remote_post("$base_url/v2/checkout/orders", [
		'headers' => [
			'Content-Type'  => 'application/json',
			'Authorization' => "Bearer $token",
		],
		'body' => json_encode([
			'intent'         => 'CAPTURE',
			'purchase_units' => [[
				'amount'      => [
					'currency_code' => 'GBP',
					'value'         => number_format($amount_due, 2, '.', ''),
				],
				'description' => 'Tickets at Moylgrove Old School Hall',
				'custom_id'   => (string)$id,
			]],
		]),
	]);

	if (is_wp_error($response)) return $response;
	$body = json_decode(wp_remote_retrieve_body($response), true);
	if (empty($body['id'])) {
		return new WP_Error('paypal_error', 'Failed to create PayPal order', ['status' => 500]);
	}
	return rest_ensure_response(['id' => $body['id']]);
}

function moylgrove_capture_paypal_order(WP_REST_Request $request) {
	global $wpdb;
	$order_id = sanitize_text_field($request->get_param('orderID'));
	$id       = intval($request->get_param('id'));
	if (!$order_id || !$id) {
		return new WP_Error('missing_params', 'Missing orderID or id', ['status' => 400]);
	}

	$token = moylgrove_get_paypal_token();
	if (is_wp_error($token)) return $token;

	$base_url = moylgrove_paypal_base_url();
	$response = wp_remote_post("$base_url/v2/checkout/orders/$order_id/capture", [
		'headers' => [
			'Content-Type'  => 'application/json',
			'Authorization' => "Bearer $token",
		],
		'body' => '{}',
	]);

	if (is_wp_error($response)) return $response;
	$body = json_decode(wp_remote_retrieve_body($response), true);

	if (($body['status'] ?? '') !== 'COMPLETED') {
		return new WP_Error('capture_failed', 'Payment capture failed', ['status' => 500]);
	}

	// Sum captured amounts
	$amount = 0;
	foreach (($body['purchase_units'] ?? []) as $unit) {
		foreach (($unit['payments']['captures'] ?? []) as $capture) {
			$amount += (float)($capture['amount']['value'] ?? 0);
		}
	}

	// Update paid amount in DB
	$table = $wpdb->prefix . 'moylgrove_forms';
	$row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
	if ($row) {
		$data             = json_decode($row->text, true);
		$previous         = (float)($data['paid'] ?? 0);
		$data['paid']     = number_format(round($amount + $previous, 2), 2);
		$wpdb->replace($table, [
			'id'   => $id,
			'time' => current_time('mysql'),
			'name' => $row->name,
			'text' => json_encode($data),
		]);

		// Cancel the pending "please pay" delayed email
		wp_clear_scheduled_hook('moylgrove_delayed_email', [$id]);
		$pending = get_transient("moylgrove_pending_email_$id");
		delete_transient("moylgrove_pending_email_$id");

		// Send paid confirmation using the template stored in the transient
		if ($pending) {
			$updated_state                     = $pending['state'];
			$updated_state['fields']['paid']   = $data['paid'];
			$updated_state['paid']             = (float)$data['paid'];
			$price                             = (float)($data['totalPrice'] ?? 0);
			$updated_state['bookingMode']      = ($updated_state['paid'] >= $price && $price > 0)
				? MG_BOOKED_STATUS : MG_PAY_STATUS;

			moylgrove_send_email(
				$pending['email_template'],
				$updated_state,
				$pending['post_title'],
				$pending['post_link'],
				$pending['place'],
				$pending['bcc']
			);
		}
	}

	return rest_ensure_response(['success' => true]);
}

// ---- Delayed email: fires 5 minutes after a booking when payment has not yet been received.
add_action('moylgrove_delayed_email', 'moylgrove_send_delayed_email');

function moylgrove_send_delayed_email($id) {
	$pending = get_transient("moylgrove_pending_email_$id");
	if (!$pending) return; // Transient already deleted — payment was received in time.

	delete_transient("moylgrove_pending_email_$id");

	// Double-check the DB in case payment arrived but the ?paid redirect didn't run
	// (e.g. user closed the browser after PayPal).
	global $wpdb;
	$table_name = $wpdb->prefix . 'moylgrove_forms';
	$row = $wpdb->get_row($wpdb->prepare("SELECT text FROM $table_name WHERE id = %s", $id));
	if ($row) {
		$data  = json_decode($row->text, true);
		$paid  = isset($data['paid'])       ? (float)$data['paid']       : 0;
		$price = isset($data['totalPrice']) ? (float)$data['totalPrice'] : 0;
		if ($price > 0 && $paid >= $price) return; // Already paid — skip.
	}

	moylgrove_send_email(
		$pending['email_template'],
		$pending['state'],
		$pending['post_title'],
		$pending['post_link'],
		$pending['place'],
		$pending['bcc']
	);
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