<?php
/// Shortcode for showing form
///
/// Use this shortcode to do your own shape of form
/// (Or use the easier standard form shortcode, which uses this one)
///
/// See the description on the admin page
///
/// Shortcode attributes:
///   full - Set this to show when you're sold out. A message such as "Sorry, we're sold out!"
/// - max - Number of seats to be booked before the full message shows. If not set, full shows immediately.
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
  $email_template = $contentparts[1] ?? '';
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
        'full' => '', // e.g. "Fully booked" - show instead of content unless this booking already exists, or total booked < max
		'max' => 0, // Number of seats to book before showing the full message 
        'bcc' => '', // in addition to info@moylgrove.wales
        'debug' => '',
      ],
      $attributes
    )
  );
  // The id arrives from the URL, a cookie or the shortcode, so it's untrusted. It is printed
  // into the page's JavaScript, so only a positive whole number is allowed through.
  $id = (is_scalar($id) && ctype_digit((string) $id) && (int) $id > 0) ? (int) $id : null;
  global $wpdb;
  $table_name = $wpdb->prefix . 'moylgrove_forms';

  if (!empty($_POST)) {
    // Submitted form; user has created or updated a booking
    //echo "==POST==$name==$id==";

    // The browser should have checked the required fields, but if its scripts failed
    // it may not have. Don't store (or send an email for) an incomplete booking.
    $problems = moylgrove_validate_submission($template, $prices);
    if (!empty($problems)) {
      return moylgrove_rejected_form($problems, $current_post, $template, $name, $seats, $submit, $full, $prices);
    }

	// Store all the <input> fields:
    // Only update a booking that exists for this event; an id that doesn't match one
    // starts a new booking instead of overwriting someone else's row.
    $existing = isset($id)
      ? $wpdb->get_row($wpdb->prepare("SELECT text FROM $table_name WHERE id = %d AND name = %s", $id, $name))
      : null;
    if (!$existing) {
      $id = null;
    }

    // The price and the amount paid are ours to decide, never the browser's: whatever the
    // form posted for them is discarded. The price is recomputed from the counts, and the
    // amount paid is whatever PayPal has already credited to this booking.
    $data = moylgrove_clean_submission($template, wp_unslash($_POST));
    [$total, $calculation] = moylgrove_compute_price($prices, $data);
    $previous = $existing ? (json_decode($existing->text, true) ?: []) : [];
    $previousPaid = $previous['paid'] ?? 0;
    $data['totalPrice'] = number_format($total, 2, '.', '');
    $data['calculation'] = $calculation;
    $data['paid'] = number_format(is_numeric($previousPaid) ? (float) $previousPaid : 0, 2, '.', '');

    // Never report a booking as made unless it really was saved. The column is 64KB TEXT,
    // and a longer value would be silently cut, leaving JSON that can't be read back.
    $fields = json_encode($data);
    $saved = $fields !== false && strlen($fields) < 60000
      && $wpdb->replace($table_name, [
        'id' => $id,
        'time' => current_time('mysql'),
        'name' => $name, // WP stub of this event page
        'text' => $fields,
      ]) !== false;
    // Get the auto-incremented ID:
    if ($saved && !isset($id)) {
      $id = $wpdb->insert_id;
      $saved = $id > 0;
    }
    if (!$saved) {
      $why = $fields === false ? "could not encode: " . json_last_error_msg() : "database: " . $wpdb->last_error;
      error_log("moylgrove-forms: booking not saved ($why)");
      return moylgrove_rejected_form(["booking could not be saved ($why)"], $current_post, $template, $name, $seats, $submit, $full, $prices);
    }
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
  //echo "==" . $state . " " . count($rows) . "==";
	
  $countBooked = get_option("seatCount_" . $name) ?? 0;
  if ($countBooked < $max) $full = '';
  
  if (count($rows) == 0) {
   	  // Show fresh booking form:
      if (!empty($full)) {
        ?><h2 style='background-color:orange'><?= $full ?></h2><?php
      } else {
        moylgrove_print_form($template, $state, $name, null, $seats, $submit, $full, $prices);
      }
  } else {
    foreach ($rows as $row) {

      moylgrove_print_form($template, $state, $name, $id, $seats, $submit, $full, $prices);
	  $bookingMode = $state['bookingMode'];
      if (!empty($_POST) && ($bookingMode == MG_BOOKED_STATUS || $bookingMode == MG_CANCELLED_STATUS)) {
        // Free event (no payment needed) or cancellation: send immediately.
        moylgrove_send_email(
          $email_template,
          $state,
          get_the_title($current_post),
          get_permalink($current_post) . "?id=$id",
          "Moylgrove Old School Hall",
          $bcc
        );
      } elseif (!empty($_POST) && $bookingMode == MG_PAY_STATUS) {
        // Booking made but payment not yet received: delay email by 10 minutes
        // so we don't send a "please pay" email to someone who pays straight away.
        moylgrove_schedule_pending_email(
          $id,
          $email_template,
          $state,
          get_the_title($current_post),
          get_permalink($current_post) . "?id=$id",
          "Moylgrove Old School Hall",
          $bcc
        );
      }
    }
  }
  // Process any shortcodes there are within the body:
  return do_shortcode(ob_get_clean());
}
add_shortcode("moylgrove-form", "moylgrove_form_shortcode");

/// A submission that couldn't be accepted or saved: tell the admin, and show the form again
/// with what the person entered and a notice. Nothing has been stored or emailed to them.
function moylgrove_rejected_form($problems, $current_post, $template, $name, $seats, $submit, $full, $prices)
{
  moylgrove_notify_rejected($problems, get_the_title($current_post), get_permalink($current_post));
  $entered = [];
  foreach ($_POST as $key => $value) {
    // moylgrove_print_form escapes values when it prints them
    $entered[$key] = is_scalar($value) ? wp_unslash((string) $value) : '';
  }
  $state = [
    'seatsBooked' => 0,
    'paid' => 0,
    'price' => 0,
    'bookingMode' => MG_BOOK_STATUS,
    'fields' => $entered,
  ];
  $notice = "<p id='moylgrove-errors' style='background-color:orange;padding:4px'>"
    . "Sorry, we had a problem saving your booking. Please try again or get in touch: <a href='mailto:info@moylgrove.wales'>info@moylgrove.wales</a></p>";
  ob_start();
  moylgrove_print_form($template, $state, $name, null, $seats, $submit, $full, $prices, 0, $notice);
  return do_shortcode(ob_get_clean());
}

function moylgrove_schedule_pending_email($id, $email_template, $state, $post_title, $post_link, $place, $bcc) {
  $fields = $state['fields'];
  if (!isset($fields['email'])) return;

  // Store all params needed to send the email later.
  $pending = [
    'email_template' => $email_template,
    'state'          => $state,
    'post_title'     => $post_title,
    'post_link'      => $post_link,
    'place'          => $place,
    'bcc'            => $bcc,
  ];
  set_transient("moylgrove_pending_email_$id", $pending, 20 * MINUTE_IN_SECONDS);

  // Cancel any previously scheduled event for this booking, then reschedule.
  wp_clear_scheduled_hook('moylgrove_delayed_email', [$id]);
  wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, 'moylgrove_delayed_email', [$id]);
}
