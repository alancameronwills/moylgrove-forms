<?php
/// Shortcode for showing form
///
/// Use this shortcode to do your own shape of form
/// (Or use the easier standard form shortcode, which uses this one)
///
/// See the description on the admin page
///
/// Shortcode attributes:
///   full - Set this only when you're sold out. A message such as "Sorry, we're sold out!"
/// - prices - Required if you want to charge via PayPal or card as part of the booking process
///			List of pairs of <field-name price> all space-separated.
///			Field-name is a numeric field used in your form. Prices in £, e.g. 10 or 5.67
///			The price charged will be sum of field_value * price
///   seats - e.g. "adults kids meals". Numeric fields you want counted up in the table of bookings.
///			If the customer sets all of these to 0, booking is regarded as cancelled.
/// - name - the WP slug of the page to get title etc from. Default = the containing page
/// - id - the ID of the booking.
///			Default = URL parameter 'id' || cookie for the current user = the booking they made on the previous visit.
/// - bcc - Comma-separated emails to send a notification of each booking to.
/// - submit - Text of the Submit button. Default "Book and pay"
///
/// Body content:
/// Within the body of the shortcode, provide two parts separated by a line of "====".
/// The first part is the HTML template of the web page form; the second is the plain text acknowledgement email
/// In the HTML form body, you can use all the usual HTML and a <style> section.
/// Other shortcodes within the body will typically work.
/// Within the form, you can use:
///		{fieldName x 1 10} - Defines a field that will be stored in the database, generally input by the user
///			x = numeric | text | email | calculated | submit
///			If you don't include your own submit button, we'll provide one
///			calculated = readonly. Used for fields with specific reserved names
/// 		1 10 = range for numeric fields; or first number is size in characters for text fields
///		{paid c 6} The amount received from the user for this booking.
/// 	{totalPrice c 6} The sum of the priced items
///		{calculation c 30} The computation of the totalPrice
///		{amountDue} How much the user owes us = totalPrice - paid.
///		{state}Book your seats || You've booked! || You've cancelled || Please pay{/state}
///			- message depending on state of booking
///		{paid gt totalPrice}We owe you{/gt} - conditional message. You can use a number as the second field
///		{paypal} - Put the PayPal buttons here

function moylgrove_form_shortcode($attributes = [], $content = null)
{
  // Body of shortcode - sanitize slightly and split to web form and response email:
  $contentparts = preg_split(
    "/====*/",
    str_replace(
      ["&#8216;", "&#8217;", "&#8220;", "&#8221;"],
      ["'", "'", '"', '"'],
      $content
    )
  );
  $template = $contentparts[0];
  $email_template = $contentparts[1];
  $current_post = get_queried_object();

  // Shortcode attributes:
  $attributes = array_change_key_case((array) $attributes, CASE_LOWER);
  extract(
    shortcode_atts(
      [
        'name' => $current_post->post_name ?? "test form", // name of form & template
        'id' => $_GET['id'] ?? ($_COOKIE['id'] ?? null),
        'seats' => "adults kids", // numeric columns - both zero implies cancellation
        'prices' => "", // e.g. "adults 10 kids 0"
        'submit' => "Book and pay", // button label
        'full' => '', // e.g. "Fully booked" - show instead of content unless already booked
        'bcc' => '', // in addition to info@moylgrove.wales
        'debug' => '',
      ],
      $attributes
    )
  );
  //echo ("===" . get_queried_object()->post_name . "===");
  global $wpdb;
  $table_name = $wpdb->prefix . 'moylgrove_forms';

  if (!empty($_POST)) {
    // Submitted form; user has created or updated a booking
    //echo "==POST==$name==$id==";
	
	// Store all the <input> fields:
    $fields = json_encode($_POST);
    $wpdb->replace($table_name, [
      'id' => $id,
      'time' => current_time('mysql'),
      'name' => $name, // WP stub of this event page
      'text' => $fields,
    ]);
    // Get the auto-incremented ID:
    if (!isset($id)) {
      $id = $wpdb->insert_id;
    }
  } else {
	// User is looking at a new or existing booking 
    if (isset($_GET['paid'])) {
		// User has just completed payment
	    // Avoid sending another ack email if user hits Refresh:
      ?>
<script>location.replace("./#form");</script>
		<?php }
  }

  //echo "==GET==$name==$id==";

  // Get the entries for this booking if it exists:
  $rows = isset($id)
    ? $wpdb->get_results(
      $wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %s AND name = %s",
        $id,
        $name
      )
    )
	: []; // No booking or id yet

  // Enable inline HTML - we'll collect it and return it from this function:
  ob_start();

	$state = currentBookingState($rows[0] ?? null, $seats, $full);
	// seatsBooked, paid, price, bookingMode, fields

  //echo "==A==";
  //var_dump($rows);
  //echo "==" . count($rows) . "==";
  
  if (count($rows) == 0) {
   	  // Show fresh booking form:
      if (!empty($full)) {
        ?><h2 style='background-color:orange'><?= $full ?></h2><?php
      } else {
        moylgrove_print_form($template, $name, null, $seats, $submit, $full, $prices, $state);
      }
  } else {
    foreach ($rows as $row) {

      moylgrove_print_form($template, $name, $id, $seats, $submit, $full, $prices, $state);
      if (
        (!empty($_POST) &&
          ($status == MG_BOOKED_STATUS || $status == MG_CANCELLED_STATUS)) ||
        isset($_GET['paid'])
      ) {
		// Just completed a booking or cancelled one.
        moylgrove_send_email(
          $email_template,
          get_the_title($current_post),
          get_permalink($current_post) . "?id=$id",
          "Moylgrove Old School Hall",
          $bcc,
		  $state
        );
        //print_r($fields);
      }
    }
  }
  // Process any shortcodes there are within the body:
  return do_shortcode(ob_get_clean());
}
add_shortcode("moylgrove-form", "moylgrove_form_shortcode");
