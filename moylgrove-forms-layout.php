<?php

/// Display the form
/// Prints to output
/// Returns state of booking: fresh|completed|cancelled|awaiting-payment

function moylgrove_print_form(
  $template, // HTML with embedded {fields}
  $state, // []: seatsBooked, paid, price, bookingMode, fields
  $name, // WP stub of booking
  $id, // ID of this booking - null if fresh
  $seats = "adults kids", // numeric fields to be counted
  $submit = "Book and pay", // label of submit button if used
  $full = '', // If set, prevents further bookings after $max
  $prices = '', // If set, requires payment before booking is completed
  $max = 0, // Ignore $full until count of bookings is > $max
  $notice = '' // HTML to show at the head of the form, e.g. what's wrong with a rejected submission
) {
  $done_submit = false;
  $fields = $state['fields'];

  ?><a name="form"></a>
  <?= $notice ?>
      <form action='./#form' method='post' class='moylgrove-form' onsubmit="jQuery('#submit')[0].disabled=true;jQuery('#submit').val('...');">
      <input type='hidden' name='moylgrove_booking' value='<?= esc_attr($name) ?>'>

<?php
formHeaderStyles($id, $state, $prices, $name);


$template2 = rewrite_longs($template, $state, $fields);
$fields['paypal'] = "<div id='paypal-button-container'></div>";
$fields['amountDue'] = "<span class='amount_due'></span>";

echo preg_replace_callback(
  '/\{ *([a-zA-Z0-9]+) *([a-zA-Z0-9]+)? *([0-9]+)? *([0-9]+)? *(\?)? *\}/',
  function ($matches) use ($fields,$prices,$done_submit) {

    if (count($matches) < 2) {
      return "";
    }

    $field = $matches[1];
    $typecode = "t";
    $type = "text";
    $modifier = "";
    $value = "";

    if (
      $fields != null &&
      isset($fields[$field])
    ) {
      $value = "$fields[$field]";
    }
    // Only these two are HTML we generated; anything else may be user input and is escaped
    if (!in_array($field, ['paypal', 'amountDue'], true)) {
      $typecode = isset($matches[2]) ? substr($matches[2], 0, 1) : "t";
      if (!in_array($typecode, ['e', 't', 'n', 'c', 's'], true)) {
        $typecode = "t"; // unknown types are text, as the validator assumes
      }
      switch ($typecode) {
        case "e":
        case "t":
          if (isset($matches[3])) {
            $modifier = "size = '$matches[3]'";
          }
          if (!isset($matches[5]) || $matches[5] != '?') {
            $modifier .= " required";
          }
          $modifier .= " maxlength='" . ($typecode == 't' ? MG_MAX_TEXT : MG_MAX_EMAIL) . "'";
          $type = $typecode == 't' ? "text" : "email";
          if ($typecode == 'e') {
            // Browsers accept "me@host"; the server (is_email) wants a dotted domain, so say so up front
            $modifier .= " pattern='[^@\\s]+@[^@\\s]+\\.[^@\\s]+' title='Please enter a full email address, like name@example.com'";
          }
          break;
        case "n":
          if (isset($matches[3])) {
            $modifier = "min='$matches[3]'";
			  if ($value < $matches[3]) {
				  $value = $matches[3];
			  }			
          }
          if (isset($matches[4])) {
            $modifier .= " max='$matches[4]'";
          }
          if (!isset($matches[5]) || $matches[5] != '?') {
            $modifier .= " required";
          }
          // Keys to refuse: no minus if the minimum isn't negative; whole numbers only for priced fields
          $blocked = (isset($matches[3]) && $matches[3] !== '') ? '-' : '';
          $priceWords = preg_split('/\s+/', trim($prices));
          $isPriced = false;
          for ($p = 0; $p < count($priceWords); $p += 2) {
            if ($priceWords[$p] === $field) {
              $isPriced = true;
            }
          }
          if ($isPriced) {
            $blocked .= '.eE';
          }
          $modifier .= " onkeydown='return mgBlockKeys(event, \"$blocked\")'";
          if (strpos($prices, $field) !== false) {
            $modifier .= " onchange='recalculate()'";
          } else {
            $modifier .= " x='" . $prices . "|" . $field . "'";
          }
          $type = "number";
          break;
        case "c":
          $type = "text";
          if (isset($matches[3])) {
            $modifier = "size='$matches[3]'";
          }
          $modifier .= " class='calculated'";
          if (is_numeric($value)) {
            $value = number_format($value, 2);
          }
          break;
        case "s":
          $type = "submit";
          $done_submit = true;
          break;
      }
      return "<input type='$type' $modifier id='$field' name='$field' value='" . esc_attr($value) . "' />";
    } else {
      return $value;
    }
  },
  $template2
);
if (!$done_submit) { ?>
		    <input type='submit' id='submit' value='<?= $submit ?>'/>
<?php }
if (!empty($full)) {
  ?>	
		  <p style='background-color:orange'>Bookings now closed - email if you need to change</p>
	  <?php 
} else {
  ?>	
		  <button onclick="makeChanges()" id="makeChangesButton" type="button">Want to change your booking? Click here</button>
	  <?php 
  }
  ?>
  		  <p style='clear:both;text-align:center;font-size:small'>Need a hand with booking? <a href='mailto:info@moylgrove.wales'>info@moylgrove.wales</a><br/> Alan 0797 473 9216</p>
	  </form>
  <?php
}

const MG_MAX_TEXT = 500; // longest text field we accept
const MG_MAX_EMAIL = 254;
const MG_MAX_REJECTION_EMAILS = 10;

/// Each new booking gets a random key, kept in its row. The id alone (sequential, so guessable)
/// isn't enough to see or change a booking: the link, cookie and PayPal calls carry the key too.
/// Bookings made before keys existed have none, and stay reachable by id alone.
function moylgrove_new_booking_key()
{
  return bin2hex(random_bytes(8));
}

/// The key from a URL, cookie or request, if it is shaped like one; else ''
function moylgrove_clean_key($key)
{
  return (is_string($key) && preg_match('/^[0-9a-f]{16}$/', $key)) ? $key : '';
}

/// May whoever presented $key see and change the booking whose stored fields are $data?
function moylgrove_booking_key_ok($data, $key)
{
  $stored = is_array($data) ? ($data['key'] ?? '') : '';
  if ($stored === '' || !is_string($stored)) {
    return true; // made before keys
  }
  return is_string($key) && hash_equals($stored, $key);
}

/// Link back to a booking, as put in emails
function moylgrove_booking_link($permalink, $id, $fields)
{
  $key = moylgrove_clean_key($fields['key'] ?? '');
  return $permalink . "?id=$id" . ($key !== '' ? "&key=$key" : '');
}

/// The rows of the template's {menu}: [["group", "option_id", "Label", "!"], ...]; [] if none.
function moylgrove_menu_items($template)
{
  if (!preg_match('#\{menu\}([^¬]*?)\{/menu\}#', $template, $menu)) {
    return [];
  }
  $items = json_decode(
    '[' . str_replace(["<br />", "</p>", "<p>", "\n", "\r"], "", $menu[1]) . ']',
    true
  );
  return is_array($items) ? $items : [];
}

/// PHP turns spaces and dots in submitted names into underscores
function moylgrove_posted_name($name)
{
  return str_replace([' ', '.'], '_', $name);
}

/// Keep only what the form could have sent: its declared fields and its menu choices.
/// Anything else in $posted (made-up names, arrays, oversized values) is dropped, so it
/// can't be stored, emailed or shown in the bookings table.
/// Call before setting the fields we decide ourselves (paid, totalPrice, calculation).
function moylgrove_clean_submission($template, $posted)
{
  $allowed = [];
  preg_match_all(
    '/\{ *([a-zA-Z0-9]+) *([a-zA-Z0-9]+)? *([0-9]+)? *([0-9]+)? *(\?)? *\}/',
    $template,
    $all,
    PREG_SET_ORDER
  );
  foreach ($all as $m) {
    $typecode = isset($m[2]) ? substr($m[2], 0, 1) : "t";
    if ($typecode == 'c' || $typecode == 's' || in_array($m[1], ['paypal', 'amountDue', 'state', 'menu', 'key'], true)) {
      continue; // calculated, button or generated: never taken from the browser
    }
    $allowed[$m[1]] = true;
  }
  $items = moylgrove_menu_items($template);
  foreach ($items as $item) {
    if (!is_array($item) || empty($item[1])) {
      continue;
    }
    $option = moylgrove_posted_name($item[1]);
    $group = !empty($item[0]) ? moylgrove_posted_name($item[0]) : $option;
    $allowed["#$option"] = true; // how many seats chose this option
    for ($seat = 1; $seat <= 8; $seat++) {
      $allowed["$group#$seat"] = true;
    }
  }
  if (!empty($items)) {
    $allowed['menuSummary'] = true;
  }

  $clean = [];
  foreach ($posted as $key => $value) {
    // "#option" is a count of seats, so only a small whole number can be genuine
    if (is_scalar($value) && is_string($key) && $key !== '' && $key[0] === '#' && !(ctype_digit((string) $value) && $value <= 8)) {
      continue;
    }
    if (isset($allowed[$key]) && is_scalar($value)) {
      $clean[$key] = is_string($value) ? mb_substr($value, 0, 2000) : $value;
    }
  }
  return $clean;
}

/// Server-side check of a submitted form ($_POST) against the form template,
/// in case the browser's own checks didn't run (e.g. the page's JavaScript failed).
/// Mirrors the rules in moylgrove_print_form: fields are required unless marked '?',
/// and a menu choice is required for each seat (up to 8) in menu rows marked '!'.
/// Tidies numeric values in $_POST (e.g. "02" becomes 2).
/// Returns a list of problems; empty if the submission is OK.
function moylgrove_validate_submission($template, $prices = '')
{
  $problems = [];
  $form = rewrite_longs($template, ['bookingMode' => MG_BOOK_STATUS], $_POST);

  preg_match_all(
    '/\{ *([a-zA-Z0-9]+) *([a-zA-Z0-9]+)? *([0-9]+)? *([0-9]+)? *(\?)? *\}/',
    $form,
    $all,
    PREG_SET_ORDER
  );
  foreach ($all as $m) {
    $field = $m[1];
    if ($field == 'paypal' || $field == 'amountDue') {
      continue;
    }
    $typecode = isset($m[2]) ? substr($m[2], 0, 1) : "t";
    if ($typecode == 'c' || $typecode == 's') {
      continue; // calculated or submit
    }
    if ($typecode != 'e' && $typecode != 'n') {
      $typecode = 't'; // as moylgrove_print_form, anything else is text
    }
    $optional = isset($m[5]) && $m[5] == '?';
    $value = $_POST[$field] ?? '';
    $value = is_string($value) ? trim($value) : '';

    if ($value === '') {
      if (!$optional) {
        $problems[] = $field;
      }
    } elseif ($typecode == 'n') {
      // Whole numbers only; negative numbers only if the template's minimum allows
      if (!preg_match('/^[+-]?\d{1,9}$/', $value)) {
        $problems[] = "$field (must be a whole number)";
      } else {
        $number = (int) $value;
        $_POST[$field] = $number;
        $min = (isset($m[3]) && $m[3] !== '') ? (int) $m[3] : 0;
        $max = (isset($m[4]) && $m[4] !== '') ? (int) $m[4] : null;
        if ($number < $min || ($max !== null && $number > $max)) {
          $problems[] = "$field (out of range)";
        }
      }
    } elseif (mb_strlen($value) > ($typecode == 'e' ? MG_MAX_EMAIL : MG_MAX_TEXT)) {
      $problems[] = "$field (too long)";
    } elseif ($typecode == 'e' && !is_email($value)) {
      $problems[] = "$field (not a valid email address)";
    }
  }

  // Text that isn't valid UTF-8 can't be stored as JSON
  foreach ($_POST as $value) {
    if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
      $problems[] = "a field contains characters we can't read";
      break;
    }
  }

  // Menu choices are only asked for when there are seats to choose for,
  // and the number of seats is the total of the priced fields.
  $seatCount = 0;
  $priceWords = preg_split('/\s+/', trim($prices));
  for ($i = 0; $i < count($priceWords); $i += 2) {
    $posted = $_POST[$priceWords[$i]] ?? 0;
    $seatCount += is_scalar($posted) ? max(0, (int) $posted) : 0;
  }
  $items = moylgrove_menu_items($template);
  if ($seatCount > 0 && !empty($items)) {
    $requiredGroups = [];
    $options = []; // group => valid option values
    foreach ((array) $items as $item) {
      if (is_array($item) && !empty($item[0]) && !empty($item[1])) {
        $options[$item[0]][] = (string) $item[1];
        if (($item[3] ?? '') == '!') {
          $requiredGroups[$item[0]] = true;
        }
      }
    }
    foreach ($options as $group => $valid) {
      for ($seat = 1; $seat <= 8; $seat++) {
        // PHP turns spaces and dots in submitted names into underscores
        $key = moylgrove_posted_name($group) . "#$seat";
        $choice = wp_unslash($_POST[$key] ?? '');
        if (!is_string($choice) || ($choice !== '' && !in_array($choice, $valid, true))) {
          $problems[] = "$group for seat $seat (not a valid choice)";
        } elseif ($choice === '' && isset($requiredGroups[$group]) && $seat <= $seatCount) {
          $problems[] = "$group for seat $seat";
        }
      }
    }
  }
  return $problems;
}

/// Work out what a submission costs, from the shortcode's `prices` ("adults 15 kids 10")
/// and the submitted counts. The browser shows the same sum (recalculate() in the page script)
/// but it is only for display: the figures we store come from here.
/// Returns [total, calculation text].
function moylgrove_compute_price($prices, $posted)
{
  $total = 0;
  $items = [];
  $words = preg_split('/\s+/', trim($prices));
  for ($i = 0; $i + 1 < count($words); $i += 2) {
    $field = $words[$i];
    $price = is_numeric($words[$i + 1]) ? max(0, (float) $words[$i + 1]) : 0;
    $count = $posted[$field] ?? 0;
    $count = (is_scalar($count) && is_numeric($count)) ? max(0, (int) $count) : 0;
    $total += $count * $price;
    $items[] = "$count x $field @ " . number_format($price, 2, '.', '');
  }
  return [round($total, 2), trim($prices) === '' ? "0" : implode(' + ', $items)];
}

/// Tell the admin about a rejected submission, so we can help the person who tried to book.
/// Includes whatever form details were submitted.
function moylgrove_notify_rejected($problems, $post_title, $post_link)
{
  $posted = wp_unslash($_POST);

  // A double-click or an impatient resubmit shouldn't send the same message twice:
  // (serialize, not json_encode: that fails on invalid UTF-8 and every such post would share one key)
  $transient = 'moylgrove_rejected_' . md5(serialize($posted));
  if (get_transient($transient)) {
    return;
  }
  set_transient($transient, 1, 10 * MINUTE_IN_SECONDS);

  // However varied the junk, the inbox gets at most MG_MAX_REJECTION_EMAILS an hour
  $sent = (int) get_transient('moylgrove_rejected_count');
  if ($sent >= MG_MAX_REJECTION_EMAILS) {
    error_log("moylgrove-forms: rejected booking not emailed (limit reached): " . implode(", ", $problems));
    return;
  }
  set_transient('moylgrove_rejected_count', $sent + 1, HOUR_IN_SECONDS);

  $message = "Someone tried to book on \"$post_title\" ($post_link) but the form was rejected, so nothing has been saved and they've had no confirmation.\n\n";
  $message .= "Problems: " . implode(", ", $problems) . "\n\n";
  $message .= "What they submitted:\n";
  foreach ($posted as $k => $v) {
    if (is_scalar($v) && trim((string) $v) !== '') {
      $message .= "$k : $v\n";
    }
  }
  $message .= "\nBrowser: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown') . "\n";
  $message .= "Time: " . current_time('mysql') . "\n";

  $headers = [];
  $email = $posted['email'] ?? '';
  if (is_string($email) && is_email($email)) {
    $headers[] = "Reply-To: $email";
  }
  wp_mail(
    "info@moylgrove.wales",
    "Booking problem at Moylgrove: $post_title",
    $message,
    $headers
  );
}

function moylgrove_send_email(
  $template, 
  $state,
  $post_title,
  $post_link,
  $place_date_time = "Moylgrove Old School Hall",
  $bcc = ''
) {
  $fields = $state['fields'];
  // Only send to a proper address (also keeps newlines out of the mail headers)
  $customer = (isset($fields['email']) && is_string($fields['email']) && is_email($fields['email'])) ? $fields['email'] : '';
  $to = $customer;
  $message = "";
  $summary = !isset($template) || strlen(trim($template)) == 0;
  if ($summary) {
    // No email template: a short thank-you with the fields to the customer (organisers on Bcc),
    // or if there's no valid customer address, the same summary to the organisers alone
    if ($customer === '') {
      $to = "info@moylgrove.wales";
      $message = "Booking at $place_date_time for '$post_title' (no valid customer email).\n\n";
    } else {
      $message = "Thank you for booking seats at $place_date_time for '$post_title'.\n\n";
    }
    foreach ($fields as $k => $v) {
      $message .= "$k : " . (is_array($v) ? implode(', ', $v) : $v) . "\n";
    }
    $message .= "\nClick to see or adjust your booking: $post_link\n";
  } else {
    if ($customer === '') {
      // Nobody to send it to: the organisers get it, to follow up
      $to = "info@moylgrove.wales";
      $message = "(The booking has no valid customer email, so this wasn't sent to them.)\n\n";
    }
    $fields['paid']     = number_format((float)($fields['paid']       ?? 0), 2);
    $fields['totalPrice'] = number_format((float)($fields['totalPrice'] ?? 0), 2);
    $fields['title'] = preg_replace(
      "/&#.*?;/",
      " ",
      $post_title
    );
    $fields['home'] = get_home_url();
    $fields['url'] = $post_link;
    $template = rewrite_longs($template, $state, $fields);
    $message .= preg_replace_callback(
      '/\\{([^ }]+) *([^ }]*)\\}/',
      function ($matches) use($fields) {
        // A field that wasn't submitted (e.g. an optional one) is blank, not left as a {placeholder}
        $subs = $fields[$matches[1]] ?? "";
        if ($subs == "") {
          $subs = $matches[2] ?? "";
        }
        return $subs;
      },
      $template
    );
    $message = str_replace(
      ["<br />", "<p>", "</p>"],
      ["\n", "\n", ""],
      $message
    );
  }
  $headers = ["From: Moylgrove <info@moylgrove.wales>"];
  if ($to === $customer) {
    $headers[] = "Bcc: info@moylgrove.wales";
  }
  $copies = explode(',', $bcc);
  foreach ($copies as $copy) {
    if (strlen($copy) > 0) {
      //echo ("=== $copy ===");
      $headers[] = "Bcc: $copy";
    }
  }
  //echo ("==mail:<pre> " . $message . "</pre>==");
  $x = wp_mail(
    $to,
    "Booking at Moylgrove",
    $message,
    $headers
  );
}

function rewrite_longs($template, $state, $fields)
{
  $bookingMode = $state['bookingMode'];
  $status_message = [
    "",
    "Book your seat",
    "You've booked your seat",
    "You've cancelled your booking",
    "Pay for your seat",
  ][$bookingMode];


  // State - Book a seat || You've booked || You've cancelled || Pay up
  $template2 = preg_replace_callback(
    '#\{state\}(.*?)(?:\|\|(.*?)(?:\|\|(.*?)(?:\|\|(.*?))?)?)?\{/state\}#s',
    function ($matches) use($bookingMode, $status_message) {
      if (
        $bookingMode >= 1 &&
        $bookingMode < count($matches)
      ) {
        return $matches[$bookingMode];
      } else {
        return $status_message;
      }
    },
    $template
  );

  // Menu - ["selection","option_id", "Option label"],...
  $template2 = preg_replace_callback(
    '#\{menu\}([^¬]*?)\{/menu\}#',
    function ($matches) use($fields) {
     	// Encode menu fields, which have # in the name
 		$menu_fields_json = "{}";
		if (is_array($fields)) {
	    	$menu_fields = array_filter($fields, 
    	    function ($key) { return strpos($key, "#")!=false;},
        	2);
	      	$menu_fields_json = json_encode($menu_fields, JSON_HEX_TAG | JSON_HEX_AMP); // HEX flags stop "-->" in a value closing the comment
		}

	    // Send menu field values and menu form
    	$menu = str_replace(["<br />","</p>","<p>", "\n", "\r"], ["","",""], $matches[1]);
      	return "<div class='menu'><!--[ $menu_fields_json ,  $menu ]--></div>" ;
    },
    $template2
  );

  // gt comparison - allows field names or literal numbers
  $template2 = preg_replace_callback(
    // /s: the text between the tags may span several lines. Operands can't contain braces
    // or whitespace, so the opening tag can't run on into the text after it.
    '#\{ *([^\s{}]+) +gt +([^\s{}]+) *\}(.*?)\{/gt\}#s',
    function ($matches)use($fields) {
      // A number, or the numeric value of a field (tolerating "1,234.50" as formatted for email)
      $term = function ($i) use ($matches, $fields) {
        $v = is_numeric($matches[$i]) ? $matches[$i]
            : (is_array($fields) && isset($fields[$matches[$i]]) ? $fields[$matches[$i]] : 0);
        $v = str_replace(',', '', (string) $v);
        return is_numeric($v) ? (float) $v : 0.0;
      };
      return $term(1) > $term(2) ? $matches[3] : "";
    },
    $template2
  );
  return $template2;
}

function currentBookingState ($row, $seats, $full) {
  $fields = null;
  $seatsBooked = 0;
  $paid = 0;
  $price = 0;

  if (isset($row->text)) {
    $fields = json_decode($row->text, true);
    $seatCounters = explode(' ', $seats);
    foreach ($seatCounters as $counter) {
      if (
        isset($fields[$counter]) &&
        is_numeric($fields[$counter])
      ) {
        $seatsBooked += $fields[$counter];
      }
    }
    $paid = $fields["paid"] ?? 0;
    if (!is_numeric($paid)) {
      $paid = 0;
    }
    $price = $fields["totalPrice"] ?? 0;
    if (!is_numeric($price)) {
      $price = 0;
    }
  }

  $bookingMode = !isset($row)
    ? MG_BOOK_STATUS
    : ($seatsBooked == 0
      ? MG_CANCELLED_STATUS
      : ($paid < $price
        ? MG_PAY_STATUS
        : MG_BOOKED_STATUS));

  return [
    'seatsBooked'=>$seatsBooked, 
    'paid'=>$paid, 
    'price'=>$price,
    'bookingMode'=>$bookingMode,
    'fields' => $fields
  ];
}

function formHeaderStyles($id, $state, $prices, $name)
{
  ?>
	<style>
			 .moylgrove-form input[type=submit], .moylgrove-form button{
				 padding:4px 10px;margin:10px 0px;
				 border-radius: 6px;
			 }
			 .moylgrove-form input[type=submit] {
				 color:black !important;
				 background-color: aliceblue !important;
				 float:right;
			 }
			 .moylgrove-form input[type=submit]:hover {
				 background-color: lightcyan !important;
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
			.moylgrove-form .row label {
				display:inline-block;
				margin: 0 10px;
			 }
			 .moylgrove-form input[type=number] {
        		width: 3em;
       		}
			.moylgrove-form input.menu-count {
				display: none;
			}
       		.moylgrove-form.readonly input[type=number]::-webkit-outer-spin-button,
			.moylgrove-form.readonly input[type=number]::-webkit-inner-spin-button {
    			-webkit-appearance: none;
    			margin: 0;
			}

			.moylgrove-form.readonly input[type=number] {
    			-moz-appearance:textfield;
			}
			.moylgrove-form.readonly .menu {
				pointer-events: none;
				user-select: none;
			}
			.moylgrove-form.readonly .clearMenuButton {
				display: none;
			}
			.moylgrove-form:not(.readonly) #makeChangesButton {
        		display: none;
      		}
			.moylgrove-form input {
    			max-width: calc(100% - 10px);
			}
      .moylgrove-form input[type='submit'] {
        border: darkred 2px solid;
      }
      .moylgrove-form button {
        border: darkred 2px solid;
      }
      .moylgrove-form input:invalid {
        background-color:#fffff0;
        border: red 2px solid;
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
      .moylgrove-paypal {
			  background-color: dodgerblue !important;
    	  justify-content: space-around !important;
    	  align-items: center;
			  text-align: center;
    	  font-weight: bold;
    	  font-size: min(10vw,72pt);
    	  color: white;
		  }
		.moylgrove-paypal-buttons>div>div {
			display:flex;
		  align-items: center;
			margin-top:2vw;
		}
    .moylgrove-form .menu {
      display: grid;
		width: 100%;
		justify-content: space-between;
		column-gap: 4px;
		font-size: smaller;
		overflow: hidden;
    margin: 10px 0;
    background-color: rgba(200, 255, 255, 0.4);
    }
    .moylgrove-form .count {
      width: 1em;
      opacity: 0;
    }
    .moylgrove-form input[name="places"] {
      width: 3em;
    }
    .moylgrove-form .menu-title {
      grid-column: 1;
    }
    .moylgrove-form .menu-title.option {
      text-align: end;
		margin-right: 4px;
    }
	.moylgrove-form .clearMenuButton {
		grid-row:100;
		grid-column: 2/9;
	}
	.moylgrove-form .clearMenuButton > button {
		font-style: italic;
    	font-size: small;
    	padding: 1px;
    	margin: 0;
    	opacity: .7;
    	border-color: grey;
	}
		.radio {
    		position: relative;
			display:flex;
			justify-content:center;
			align-items: center;
	}

	.menu .radio::before {
    	content: "";
    	position: absolute;
    	background-color: lightgray;
    	inline-size: 1px;
    	block-size: 300%;
    	inset-block-start: -200%;
    	inset-inline-start: -3px;
	}
		
	.menu-title:not(.option) {
			border-top: lightgray solid 1px;
	    	margin-top: 2px;
    		padding-top: 0;
			position: relative;
			z-index: 1;
		& h3 {
    		background-color: steelblue;
        color: white;
			border-top: none;
		}
	}
	</style>
	<?php
	if (!empty($prices)) {
		$pp_mode      = get_option('moylgrove_paypal_mode', 'sandbox');
		$pp_client_id = get_option("moylgrove_paypal_client_id_$pp_mode", '');
		if ($pp_client_id) {
			$sdk_url = 'https://www.paypal.com/sdk/js?client-id=' . esc_attr($pp_client_id) . '&currency=GBP';
			echo "<script src='" . esc_url($sdk_url) . "'></script>\n";
		}
	}
	?>
	<script>
		
  // onkeydown for number inputs: refuse typing any of the characters in `blocked`.
  // Typing a digit over a lone "0" replaces it, so "2" doesn't become "20".
  function mgBlockKeys(event, blocked) {
    if (event.ctrlKey || event.metaKey || event.altKey) return true;
    if (event.key.length != 1) return true;
    if (blocked.indexOf(event.key) >= 0) return false;
    let input = event.target;
    if (/^[0-9]$/.test(event.key) && !input.readOnly && input.value === "0") {
      input.value = "";
    }
    return true;
  }

  function reveal() {
	jQuery(".moylgrove-form input[type=radio]").prop("required", false);
    let places =  window.seatCount || 0;
	if (places==0) jQuery(".moylgrove-form .menu").hide();
	else jQuery(".moylgrove-form .menu").show(500);
    let cells = document.querySelectorAll(".radio");
    for (const cell of cells) {
      if (+cell.dataset.col > places) {
        cell.style.display = "none";
      } else {
        cell.style.display = "";
      }
    }
	for (let i = 1; i<=places; i++) {
		jQuery(".required-radio-"+i).prop("required", true);
	}
  }

function clearMenu() {
	jQuery(".moylgrove-form .menu .radio>input").prop( "checked", false );
}
		
function setMenu() {
  let table = document.querySelector(".menu");
  let places = 8;
  let fields = {};
  let menuMatch = table.innerHTML.match(/<!--([^~]*)-->/);
  if (!menuMatch) return;
  let items = [];
  try {
    items = JSON.parse(`${menuMatch[1]}`);
  } catch (e){
    table.insertAdjacentHTML("beforeend", ""+e);
    return;
  }
  jQuery(".moylgrove-form .menu").hide();

  let rowNumber = 0;
  for (const item of items) {
    rowNumber++;
    if (rowNumber == 1) {
      // First item is the previous values
      fields = item;
      continue;
    }
    if (item[1]) {
      // Radio buttons or checkbox
      table.insertAdjacentHTML(
        "beforeend",
		  // Hidden sum for the item
        `<div class="menu-title option" style="grid-column:1;grid-row:${rowNumber}">
            <input type="number" name="#${item[1]}" class="menu-count" value="${+fields[item[1]] || ''}">
            ${item[2]}
          </div>`,
      );
    } else {
      // header
      table.insertAdjacentHTML(
        "beforeend",
        `<div class="menu-title" style="grid-column:1/${places};grid-row:${rowNumber}">${item[2]}</div>`,
      );
    }
    if (item[1]) {
      // Radio buttons or checkbox
      for (let i = 1; i <= places; i++) {
        table.insertAdjacentHTML(
          "beforeend",
          `<div class="radio" data-col="${i}" style="grid-column:${i + 1};grid-row:${rowNumber}">` +
            (item[0]
              ? `<input type='radio' name='${item[0]}#${i}' value='${item[1]}'
               ${fields[`${item[0]}#${i}`] == item[1] ? "checked" :"" } 
				 ${item[3]=='!' ? `class='required-radio-${i}'` : ""}>`
              : `<input type='checkbox' name='${item[1]}#${i}' data-group='${item[1]}' 
              ${fields[`${item[1]}#${i}`] ? "checked" : ""}>`) +
            `</div>`,
        );
      }
    }
  }

  for (let i = 1; i <= places; i++) {
    table.insertAdjacentHTML(
      "beforeend",
      `<div class="radio" data-col="${i}" style="grid-column:${i + 1};grid-row:1"><b>Seat ${i}</b></div>`,
    );
  }
  table.insertAdjacentHTML("beforeend", `<div class="clearMenuButton"><button onclick="clearMenu()">Clear menu</button></div>`);
  table.insertAdjacentHTML("afterend", `<input type="text" name="menuSummary" id="menuSummary" style="display:none">`);
 
  const form = document.querySelector(".moylgrove-form");
  form.addEventListener(
    "submit",
    (event) => {
		let menu = form.querySelector(".menu");
	  	// Each menu row (dish) has a hidden count of how many seats selected that dish
		// We'll zero them all, then count up the ones that are checked
      	for (const counter of menu.querySelectorAll("input.menu-count")) {
        	counter.value = "0";
      	}
		let seatSelections = [];
	  	// Radio buttons and checkboxes within the menu
      	let checkOptions = menu.querySelectorAll(".radio>input");
      	for (const checkOption of checkOptions) {
        	if (checkOption.checked) {
				let [optionName, seat] = checkOption.name.split('#');
				if (seat <= window.seatCount) {
					let selectionName = checkOption.type == "checkbox" ? optionName : checkOption.value;
					// Increment the sum for this menu row, id beginning '#'
					// (of class menu-count)
          			let sum = menu.querySelector(`input[name="#${selectionName}"]`);
          			if (sum) {
            			sum.value = +sum.value + 1;
          			}
					if (!seatSelections[seat]) seatSelections[seat]= [];
					seatSelections[seat].push (selectionName);
				}
        	}
      	}
		// 
		// 
		let menuSummary = "";
		for (let i= 1; i<=window.seatCount; i++) {
			menuSummary += `Seat ${i}: ${(seatSelections[i] || []).join(", ")}; <br />\n`;
		}
		let ms = document.querySelector("#menuSummary");
		if (ms) ms.value = menuSummary;
    },
    false,
  );
}
			  function recalculate() {
				  let prices = "<?= $prices ?>";
				  let calculation = "";
				  let total = 0;
				  window.seatCount = 0;
				  try {
				  if (prices) {
					  let psplit = prices.split(/\s+/);
					  let items = [];
					  for (let i = 0; i<psplit.length; i += 2) {
						  let fieldName = psplit[i];
						  let fieldPrice = +(psplit?.[i+1]);
						  if (isNaN(fieldPrice)) fieldPrice = 0;
						  let fieldValue = Number(jQuery('#'+fieldName).prop('value')); // not attr()!
						  window.seatCount += fieldValue;
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
				  reveal();
			  }
			  function makeChanges() {
			  	jQuery('.moylgrove-form input:not(.calculated)').attr('readonly', false);
				jQuery('.moylgrove-form').removeClass('readonly');
				jQuery('#makeChangesButton').hide();
				jQuery('#formHeader').text("Change your booking");
				jQuery('#submit').attr("value", "Confirm your changes");
				jQuery('#submit').prop('disabled',false);
				jQuery('#submit').show();
				payPalButtons().hide();
			  }
			  function payPalButtons() {
				  return jQuery('.moylgrove-paypal');
			  }
			  jQuery(() => {
				  let amountDue = "<?= number_format($state['price'] - $state['paid'], 2) ?>";
				payPalButtons().
				<?= $state['bookingMode'] == MG_PAY_STATUS ? "show" : "hide" ?>();
				jQuery('.amount_due').html(amountDue);
			  	jQuery('.moylgrove-form input.calculated').attr('readonly', true);
				recalculate();
				window.scrollBy(0,-80);

				<?php if ($state['bookingMode'] == MG_PAY_STATUS && !empty($prices)): ?>
				if (typeof paypal !== 'undefined') {
					paypal.Buttons({
						createOrder: function() {
							return fetch(<?= json_encode(rest_url('moylgrove/v1/create-order')) ?>, {
								method: 'POST',
								headers: {'Content-Type': 'application/json'},
								body: JSON.stringify({id: window.id, key: window.key})
							}).then(r => r.json()).then(data => data.id);
						},
						onApprove: function(data) {
							document.querySelector('#paypal-button-container').innerHTML = '<p style="font-size:1rem">Processing payment\u2026</p>';
							var bookingId = window.id;
							var keyParam = window.key ? '&key=' + window.key : '';
							return fetch(<?= json_encode(rest_url('moylgrove/v1/capture-order')) ?>, {
								method: 'POST',
								headers: {'Content-Type': 'application/json'},
								body: JSON.stringify({orderID: data.orderID, id: bookingId, key: window.key})
							}).then(function(r) { return r.json(); }).then(function(result) {
									if (!result || !result.success) {
										throw new Error('not credited');
									}
								// Use a timestamp so the browser always issues a fresh GET
								// even if the URL is unchanged (same ?id= param).
								location.replace(location.pathname + '?id=' + bookingId + keyParam + '&_t=' + Date.now() + '#form');
							}).catch(function() {
								document.querySelector('#paypal-button-container').innerHTML =
									'<p style="font-size:1rem">We could not confirm your payment on this page. If PayPal has taken the money, please do not pay again: ' +
										'<a href="' + location.pathname + '?id=' + bookingId + keyParam + '">reload this page</a>, or email info@moylgrove.wales quoting booking ' + bookingId + '.</p>';
							});
						}
					}).render('#paypal-button-container');
				}
				<?php endif; ?>
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
			    setMenu();
				  reveal();
			  });
		  </script>
		  
		<input type='hidden' id='id' name='id' value='<?= $id ?>' />
		<script>
			window.id = "<?= $id ?>";
			window.key = <?= json_encode(moylgrove_clean_key($state['fields']['key'] ?? '')) ?>;
			document.cookie= "id=<?= $id ?>; Max-Age=6000000"; // <3 months
			document.cookie= "key=" + window.key + "; Max-Age=6000000";
		</script>
		<?php 
	} else { ?>
		<script>
			jQuery(() =>{
				setMenu();
				window.seatCount = 0;
				reveal();
			});
		</script>
		  <?php }
}
?>