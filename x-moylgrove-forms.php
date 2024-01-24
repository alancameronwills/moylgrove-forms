<?php

/**
 * Plugin Name: Moylgrove Forms
 * Description: Take details of event bookings
 * Version: 1.0.1
 * Author: Alan Cameron Wills
 * Licence: GPLv2
 * 
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

	$rows = isset($id) ? $wpdb->get_results($wpdb->prepare(
		"SELECT * FROM $table_name WHERE id = %s", $id) )
		: [];

    $data = json_decode($rows[0]->text, true);

	$previous= isset($data['paid']) ? (float)$data['paid'] : 0;

	$data['paid'] = number_format(round((float)$payment_data['mc_gross'] + $previous,2),2);
                  
	$text = json_encode($data);	

	$wpdb->replace($table_name, [
		'id'=>$id,
		'time' => current_time( 'mysql' ), 
		'name' => $rows[0]->name, 
		'text' => $text
	]);
	
	// error_log("Moylgrove checkout_processed " . print_r($payment_data, true));
}

function moylgrove_forms_getOrderIdInBuyButton($custom_input_code, $button_code, $atts){
	$customValue = isset($atts['custom']) ? esc_attr($atts['custom']) : "0";
	return '<input class="wppaypal_checkout_custom_input" type="hidden" name="amount" value="'.$customValue.'" required>';
}

/********************************************/
$mgfdir = plugin_dir_path( __FILE__ );
include_once($mgfdir . 'moylgrove-forms-dump.php');
include_once($mgfdir . 'moylgrove-forms-table.php');
if(is_admin()){
	include_once($mgfdir . 'moylgrove-forms-admin.php');
}

/********************************************/

function rewrite_longs($template) {
	$template2 = preg_replace_callback(
		'#\{state\}(.*?)(?:\|\|(.*?)(?:\|\|(.*?)(?:\|\|(.*?))?)?)?\{/state\}#',
		function($matches) {
			global $moylgrove_booking_mode;
			global $moylgrove_status_message;
			if ($moylgrove_booking_mode>=1 && $moylgrove_booking_mode <count($matches)) 
				return $matches[$moylgrove_booking_mode];
			else
				return $moylgrove_status_message;
		},
		$template);
	$moylgrove_forms_fields[0]=0;
	$template2 = preg_replace_callback(
		'#\{ *([^ {}]+) +gt +([^ ]+) *\}(.*?){/gt\}#',
		function($matches) {
			global $moylgrove_forms_fields;
			if (count($matches) == 4) 
			{
				$a = $moylgrove_forms_fields[$matches[1]]?? 0;
				$b = $moylgrove_forms_fields[$matches[2]]?? 0;
				if ($a > $b)
					return $matches[3];
				else 
					return "";
			}
		},
		$template2);
	return $template2;
}

function moylgrove_print_form($template, $name, $id, $row, $seats="adults kids", $submit="Book", $full='', $prices='') 
{	
	global $moylgrove_forms_fields;
	global $moylgrove_forms_done_submit;
	$moylgrove_forms_done_submit = false;
	global $moylgrove_forms_prices;
	$moylgrove_forms_prices = $prices;
	
	$seatsBooked = 0;
	$paid = 0;
	$price = 0;
	
	if ($row != null) {

		$seatCounters = explode(' ', $seats);
		foreach ($seatCounters as $counter) {
			if (isset($moylgrove_forms_fields[$counter])
				&& is_numeric($moylgrove_forms_fields[$counter])) {
				$seatsBooked += $moylgrove_forms_fields[$counter];
			}
		}
		$paid = $moylgrove_forms_fields["paid"] ?? 0;
		if(!is_numeric($paid)) $paid=0;
		$price = $moylgrove_forms_fields["totalPrice"] ?? 0;
		if(!is_numeric($price)) $price=0;
		//echo ("$row->text");
	}
	//echo ("<-- XXXX $paid || $price ZZZ || $seatsBooked || $prices-->");
	
	global $moylgrove_booking_mode;
	$moylgrove_booking_mode = !isset($id) ? MG_BOOK_STATUS : 
		($seatsBooked == 0 ? MG_CANCELLED_STATUS : 
		 ($paid < $price ? MG_PAY_STATUS : MG_BOOKED_STATUS));
	
	if (!empty($full)) {
		if ($seatsBooked == 0) {
			echo ("<h2>$full</h2>");
			return;
		} else {
			echo ("<p>$full</p>");
		}
	}
	if (!get_post_meta(get_the_ID(), "wp-paypal-custom-field")) {
		add_post_meta(get_the_ID(), 'wp-paypal-custom-field', '[wp_paypal_checkout]', true);
	}

	?><a name="form"></a>
      <form action='./#form' method='post' class='moylgrove-form' onsubmit="jQuery('#submit')[0].disabled=true;jQuery('#submit').val('...');">
	     <style>
			 .moylgrove-form input[type=submit], .moylgrove-form button{
				 padding:4px 10px;margin:10px 0px;
				 border-radius: 6px;
			 }
			 .moylgrove-form input[type=submit] {
				 color:black !important;
				 background-color:lightblue !important;
				 float:right;
			 }
			 .readonly input {
				 background-color:transparent;
				 border-width:0px;
				 cursor:default;
			 }
			 .readonly input[type=submit] {
				 display: none;
			 }
			 .moylgrove-form button{
				 color:#060606 !important;
				 background-color:rgba(200,255,255,0.4) !important;
			 }
			.moylgrove-form .row {
  				display: flex;
				flex-wrap: wrap;
				justify-content: space-between;
 				width:100%;
  				grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
  				grid-gap: 1rem;
 				margin-bottom:10px;
 				padding: 4px;
  				background-color:rgba(200,255,255,0.4);
  				margin-bottom:10px;
				user-select:none;
			}
			 .moylgrove-form .row>div:first-child {
			 }
			.moylgrove-form .row label {
				display:inline-block;
				margin: 0 10px;
			 }
			 .moylgrove-form.readonly input[type=number]::-webkit-outer-spin-button,
			 .moylgrove-form.readonly input[type=number]::-webkit-inner-spin-button {
    			-webkit-appearance: none;
    			margin: 0;
			}

			.moylgrove-form.readonly input[type=number] {
    			-moz-appearance:textfield;
			}
			 
			.moylgrove-form input {
    			max-width: calc(100% - 10px);
			}
			 .bookings-table tr:last-child {
				 font-weight:bold;
			 }
			 .calculated {
				 background-color: transparent;
				 border: 0 !important;
				 user-select: none;
			 }
			 label div {display:inline;}
		  </style>
		  <script>
			  function recalculate() {
				  let prices = "<?=$prices?>";
				  let calculation = "";
				  let total = 0;
				  try {
				  if (prices) {
					  let psplit = prices.split(/\s+/);
					  let items = [];
					  for (let i = 0; i<psplit.length; i += 2) {
						  let fieldName = psplit[i];
						  let fieldPrice = +(psplit?.[i+1]);
						  if (isNaN(fieldPrice)) fieldPrice = 0;
						  let fieldValue = Number(jQuery('#'+fieldName).prop('value')); // not attr()!
						  let fieldGross = fieldValue * fieldPrice;
						  if (fieldValue < 0 || fieldValue > 10 || fieldPrice < 0 || fieldPrice > 100) {
							  throw ("some mistake in the calculation");
						  }
						  total += fieldGross;
						  items.push(`${fieldValue} x ${fieldName} @ ${fieldPrice.toFixed(2)}`);
					  }
					  calculation = items.join(' + ');
				  } else {
					  calculation = "0";
				  } 
					  
				}  catch (e) {calculation = e;}
				jQuery("#calculation").attr("value",calculation);
				jQuery("#totalPrice").attr("value",total.toFixed(2));
			  }
			  function makeChanges() {
			  	jQuery('.moylgrove-form input:not(.calculated)').attr('readonly', false);
				jQuery('.moylgrove-form').removeClass('readonly');
				jQuery('#makeChangesButton').hide();
				jQuery('#formHeader').text("Change your booking");
				jQuery('#submit').attr("value", "Submit your changes");
				jQuery('#submit').prop('disabled',false);
				jQuery('#submit').show();
				payPalButtons().hide();
			  }
			  function payPalButtons() {
				  return jQuery('.moylgrove-paypal');
			  }
			  jQuery(() => {
				  let amountDue = "<?= number_format($price - $paid, 2)?>";
				payPalButtons().<?=$moylgrove_booking_mode == MG_PAY_STATUS ? "show" : "hide"?>();
			  	jQuery('.wppaypal_checkout_description_input')
					.attr("value", 'Tickets for <?=$name?> at Moylgrove Old School Hall');
				jQuery('.wppaypal_checkout_amount_input')
				  .attr("value",  amountDue );
				jQuery('.amount_due').html(amountDue);
				recalculate();
				window.scrollBy(0,-80);
			  });
		  </script>

<?php
			
	if (isset($id)) {
		// Existing row: keep the id for making updates
		?>
		  <script>
			  jQuery(() =>{
			  	jQuery('.moylgrove-form input').attr('readonly', true);
			  	jQuery('.moylgrove-form').addClass("readonly");
			  	jQuery('.wppaypal_checkout_custom_input').attr("value", '<?=$id?>');
			  });
		  </script>
		  
		<input type='hidden' id='id' name='id' value='<?=$id?>' />
		<script>
			window.id = "<?=$id?>";
			document.cookie= "id=<?=$id?>; Max-Age=6000000"; // <3 months
		</script>
		<?php
	} else {
		?>
		<script>
			jQuery(() =>{
				let amountField = jQuery('.wppaypal_checkout_amount_input');
				let buttons = amountField.parent();
				buttons.hide();
			});
		</script>
		  <?php
	}
	
			global $moylgrove_booking_mode;
	global $moylgrove_status_message;
	$moylgrove_status_message = Array(
		"",
		"Book your seat",
		"You've booked your seat",
		"You've cancelled your booking",
		"Pay for your seat"
	)[$moylgrove_booking_mode];
	
	$template2 = rewrite_longs($template);
	
	$timestamp=time();
	$moylgrove_forms_fields['paypal'] = "
		<div class='moylgrove-paypal-buttons'>
			[wp_paypal_checkout 
				description='Tickets for event at Moylgrove Old School Hall' 
				amount='0' no_shipping='1' custom='444' return_url='./?paid=$timestamp#form']
		</div>";
	
	echo(preg_replace_callback(
		'/\{ *([a-zA-Z0-9]+) *([a-zA-Z0-9]+)? *([0-9]+)? *([0-9]+)? *(\?)? *\}/',
		function ($matches) {
			global $moylgrove_forms_fields;
			global $moylgrove_forms_prices;
			global $moylgrove_forms_done_submit;
			
			if (count($matches)<2) return "";
			
			$field = $matches[1];
			$typecode = "t";
			$type = "text";
			$modifier = "";
			$value = "";
			
			if ($moylgrove_forms_fields != null && isset($moylgrove_forms_fields[$field])) {
				$value = "$moylgrove_forms_fields[$field]";
			}
			if (strpos($value, '<') === false) {
				$typecode = isset($matches[2]) ? substr($matches[2], 0, 1) : "t";
				switch ($typecode) {
					case "e":
					case "t": 
						if (isset($matches[3])) $modifier = "size = '$matches[3]'";
						if (!isset($matches[5]) || $matches[5]!='?') $modifier .= " required";
						$type = $typecode =='t' ? "text" : "email";
						break;
					case "n": 
						if (isset($matches[3])) $modifier = "min='$matches[3]'";
						if (isset($matches[4])) $modifier .= " max='$matches[4]'";
						if (!isset($matches[5]) || $matches[5]!='?') $modifier .= " required";
						if (strpos($moylgrove_forms_prices, $field)!==false) $modifier .= " onchange='recalculate()'";
						else $modifier .= " x='" .$moylgrove_forms_prices . "|" . $field ."'";
						$type = "number";
						break;
					case "c":
						$type = "text";
						if (isset($matches[3])) $modifier = "size='$matches[3]'";
						$modifier .= " class='calculated'";
						if(is_numeric($value)) $value = number_format($value,2);
						break;
					case "s": $type = "submit";
						$moylgrove_forms_done_submit = true;
						break;
				} 
				return "<input type='$type' $modifier id='$field' name='$field' value='$value' />";
			} else {
				return $value;
			}
		},
		$template2));
	if (!$moylgrove_forms_done_submit) {
		?>
		    <input type='submit' id='submit' value='<?= $submit ?>'/>
		<?php
	}
	?>	
		<button onclick="makeChanges()" id="makeChangesButton" type="button">Want to change your booking? Click here</button>
		<p style='clear:both;text-align:center;font-size:small'>Need a hand with this? <a href='mailto:info@moylgrove.wales'>info@moylgrove.wales</a></p>
	</form>
	<?php
	return $moylgrove_booking_mode;
}

// Shortcode for showing form
function moylgrove_form_shortcode($attributes = [], $content = null)
{
	// Body of shortcode - sanitize slightly and split to web form and response email:
	$contentparts = preg_split("/====*/",str_replace(array("&#8216;","&#8217;", "&#8220;", "&#8221;"),array("'","'", '"', '"'),$content));
	$template = $contentparts[0];
	$email_template = $contentparts[1];
	$current_post = get_queried_object();
	
    // Shortcode attributes:
    $attributes = array_change_key_case( (array) $attributes, CASE_LOWER );
  	extract(
    	shortcode_atts(
      	[
        	'name' => $current_post->post_name ?? "test form", // name of form & template
			'id' => $_GET['id'] ?? $_COOKIE['id'] ?? NULL,
			'seats' => "adults kids", // numeric columns - both zero implies cancellation
			'prices' => "", // e.g. "adults 10 kids 0"
			'submit' => "Book seats", // button label
			'full' => '', // e.g. "Fully booked" - show instead of content unless already booked
			'bcc' => '', // in addition to info@moylgrove.wales
        	'debug' => ''
      	],
      	$attributes
    	)
  	);
	//echo ("===" . get_queried_object()->post_name . "===");
	global $wpdb;
	$table_name = $wpdb->prefix . 'moylgrove_forms';
	
	if (!empty($_POST)) {
		// Submitted form
		//echo "==POST==$name==$id==";

		$text = json_encode($_POST);		
		$wpdb->replace($table_name, [
			'id' => $id,
			'time' => current_time( 'mysql' ), 
			'name' => $name, 
			'text' => $text
			]);
		// Get the auto-incremented ID:
		if(!isset($id)) $id = $wpdb->insert_id;
	} else if (isset($_GET['paid'])) {
		?>
<script>location.replace("./#form");</script>
		<?php
	}
	
	//echo "==GET==$name==$id==";
	
	$rows = isset($id) ? $wpdb->get_results($wpdb->prepare(
		"SELECT * FROM $table_name WHERE id = %s AND name = %s", $id, $name) )
		: [];
	//echo "==A==";
	//var_dump($rows);
	//echo "==" . count($rows) . "==";
	
	ob_start();
		
	global $moylgrove_forms_fields;
	$moylgrove_forms_fields = NULL;
	if (count($rows)==0) {
		moylgrove_print_form($template, $name, NULL, NULL, $seats, $submit, $full, $prices);
	} else {
		foreach($rows as $row) {	
			$moylgrove_forms_fields = json_decode($row->text, true);
			$status = moylgrove_print_form($template, $name, $id, $row, $seats, $submit, $full, $prices);
			if (!empty($_POST) && ($status == MG_BOOKED_STATUS || $status==MG_CANCELLED_STATUS) 
				|| isset($_GET['paid'])) {
				moylgrove_send_email($email_template, 
									 get_the_title($current_post), 
									 get_permalink($current_post) . "?id=$id", 
									 $row, 
									 "Moylgrove Old School Hall",
									 $bcc);
				//print_r($moylgrove_forms_fields);
			}
		}
	}
	
  	return do_shortcode(ob_get_clean());
}


function moylgrove_send_email($template, $post_title, $post_link, $row, $place_date_time="Moylgrove Old School Hall", $bcc='') {
	global $moylgrove_forms_fields;
	if (!isset($moylgrove_forms_fields['email'])) return;
	$message = "";
	if (!isset($template) || strlen($template) == 0) {
		$message = "Thank you for booking seats at $place_date_time for '$post_title'.\n\n";
		foreach($moylgrove_forms_fields as $k => $v) {
			$message .= "$k : $v \n";
		}
		$message .= "\nClick to see or adjust your booking: $post_link\n";
	} else {
		$moylgrove_forms_fields['title'] = preg_replace("/&#.*?;/", " ", $post_title);
		$moylgrove_forms_fields['home'] = get_home_url();
		$moylgrove_forms_fields['url'] = $post_link;
		$template = rewrite_longs($template);
		$message .= preg_replace_callback (
			'/\\{([^ }]+) *([^ }]*)\\}/', 
			function ($matches) {
				global $moylgrove_forms_fields;
				$subs = $moylgrove_forms_fields[$matches[1]] ?? $matches[0] ?? "0";
				if ($subs=="") $subs = $matches[2] ?? "";
				return $subs ;
			},
			$template);
		$message = str_replace(["<br />", "<p>", "</p>"], ["\n", "\n", ""], $message);
	}
	$headers = array("From: Moylgrove <info@moylgrove.wales>", "Bcc: info@moylgrove.wales");
	$copies = explode(',', $bcc);
	foreach ($copies as $copy) {
		if (strlen($copy)>0) {
			//echo ("=== $copy ===");
			$headers[] = "Bcc: $copy";
		}
	}
	//echo ("==mail:<pre> " . $message . "</pre>==");
	$x = wp_mail($moylgrove_forms_fields['email'], "Booking at Moylgrove Hall", $message, $headers);
}


add_shortcode("moylgrove-form", "moylgrove_form_shortcode");

function moylgrove_standard_form($attributes = []) 
{
    $attributes = array_change_key_case( $attributes, CASE_LOWER );
  	extract(
    	shortcode_atts(
      	[
        	'date' => '',
			'meals' => '',
			'bcc' => '',
			'full' => '',
			'prices' => 'adults 10 kids 0'
      	],
      	$attributes
    	)
  	);
	ob_start();
	?>
		  
<!-- wp:columns {"style":{"color":{"background":"#78d3f2"}},"textColor":"dark-gray"} -->
<div class="wp-block-columns has-dark-gray-color has-text-color has-background" style="background-color:#78d3f2"><!-- wp:column -->
<div class="wp-block-column">
<!-- wp:shortcode -->
[moylgrove-form bcc='<?=$bcc?>' <?=empty($full)?'':"full='$full'"?> prices='<?=$prices?>']
	<h2 id='formHeader'>{state}Book your seat||You've booked your seat||You've cancelled your booking||Pay for your seat{/state}</h2>
	<?php
	if (strlen($prices)) {
		?>
	<style>
		.moylgrove-paypal {
			background-color: dodgerblue !important;
    		justify-content: space-around !important;
    		align-items: center;
			text-align: center;
    		font-weight: bold;
    		font-size: min(10vw,72pt);
    		color: white;
		}
		.moylgrove-paypal-buttons {
			/*width:min(300px,30vw);*/
		}
		.moylgrove-paypal-buttons>div>div {
			display:flex;
		    align-items: center;
			margin-top:2vw;
		}
	</style>

	<div class="row moylgrove-paypal" style="display:none">
		<div class="moylgrove-amount-due">
			<div style="font-size:min(3vw,18pt)">Please pay</div>
			£ <span class="amount_due"></span>
		</div>
		{paypal}
	</div>
	<?php
	}
	?>
	<div class="row"><div>Number of seats (including you):</div><div><label>Adults {adults n 0 10}</label> <label>Under-16s {kids n 0 10 ?}</label></div></div>
<?php 
	if (strlen($meals)) {
		?>
	<div class="row"><div><label><?=$meals?></label> </div><div>{meals n 0 10} </div><div>Diet...? {diet? t 30} </div></div>
	<?php
	}
	?>
<div class="row"><div>Your name:</div><div><label>first<br>{first}</label><label>last<br>{last?}</label></div></div>
<div class="row"><div>Address:</div><div><label>house<br>{address}</label><label>post code<br>{postcode text 10}</label></div></div>
	<div class="row"><div>Contact:</div>
		<div><label>phone<br>{phone text 14}</label><label>email<br>{email t 35}</label></div>
	</div>
	<?php
	if (strlen($prices)) {
		?>
	<div class="row"><div>Price:</div>
		<div><label>calculation<br>{calculation c 40}</label>
			<label>amount<br>£ {totalPrice c 6}</label>
			<label>paid<br>£ {paid c 6}</label>
		</div>
		{paid gt totalPrice}<p>We owe you some money. We will refund it soon.</p>{/gt}
	</div>
	<?php
	}
	?>
======
Dear {first} {last},

{state}Seats||Thank you for booking seats||You've cancelled your booking||Awaiting payment for seats booked{/state} at Moylgrove Old School Hall for "{title}" on <?=$date?>.

You've booked: 
     Adults:   {adults}         Under-16s: {kids}
<?php 
	if (strlen($meals)) {
		?>
	<?=$meals?>: {meals} {diet}
	<?php
	}
	?>
	
{totalPrice gt 0}We'll have your name on a list when you come to the event. You can bring a copy of this just in case.{/gt}

<?php 
	if (strlen($prices)) {
		?>
    Ticket price: £{totalPrice}
    You've paid:  £{paid}
	{paid gt totalPrice}We owe you some money. We will refund it soon.{/gt}
	<?php
	}
	?>

	
We've got your address as: {address} {postcode}

If we need to contact you in a hurry, we'll use: {phone}

If you need to adjust your booking, find it here: {url}


Thanks
Cymdeithas Trewyddel
{home}

[/moylgrove-form]
<!-- /wp:shortcode -->

<!-- wp:shortcode -->
[moylgrove-forms style=html sum="adults kids meals"]
<!-- /wp:shortcode --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
	<?php
	
	return do_shortcode(ob_get_clean());
}
add_shortcode("moylgrove-standard-form", "moylgrove_standard_form");
?>