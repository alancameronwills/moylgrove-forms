<?php

/// Display the form
/// Prints to output
/// Returns state of booking: fresh|completed|cancelled|awaiting-payment

function moylgrove_print_form(
  $template, // HTML with embedded {fields}
  $name, // WP stub of booking
  $id, // ID of this booking - null if fresh
  $seats = "adults kids", // numeric fields to be counted
  $submit = "Book and pay", // label of submit button if used
  $full = '', // If set, prevents further bookings
  $prices = '', // If set, requires payment before booking is completed
  $state // seatsBooked, paid, price, bookingMode, fields
) {
  $done_submit = false;
  $fields = $state['fields'];

  if (!get_post_meta(get_the_ID(), "wp-paypal-custom-field")) {
    add_post_meta(
      get_the_ID(),
      'wp-paypal-custom-field',
      '[wp_paypal_checkout]',
      true
    );
  }
  ?><a name="form"></a>
      <form action='./#form' method='post' class='moylgrove-form' onsubmit="jQuery('#submit')[0].disabled=true;jQuery('#submit').val('...');">

<?php
formHeaderStyles($id, $state, $prices, $name);


$template2 = rewrite_longs($template, $state, $fields);

$timestamp = time();
$fields['paypal'] = "
		<div class='moylgrove-paypal-buttons'>
			[wp_paypal_checkout 
				description='Tickets for event at Moylgrove Old School Hall' 
				amount='0' no_shipping='1' custom='444' return_url='./?paid=$timestamp#form']
		</div>";
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
    if (strpos($value, '<') === false) {
      $typecode = isset($matches[2]) ? substr($matches[2], 0, 1) : "t";
      switch ($typecode) {
        case "e":
        case "t":
          if (isset($matches[3])) {
            $modifier = "size = '$matches[3]'";
          }
          if (!isset($matches[5]) || $matches[5] != '?') {
            $modifier .= " required";
          }
          $type = $typecode == 't' ? "text" : "email";
          break;
        case "n":
          if (isset($matches[3])) {
            $modifier = "min='$matches[3]'";
          }
          if (isset($matches[4])) {
            $modifier .= " max='$matches[4]'";
          }
          if (!isset($matches[5]) || $matches[5] != '?') {
            $modifier .= " required";
          }
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
      return "<input type='$type' $modifier id='$field' name='$field' value='$value' />";
    } else {
      return $value;
    }
  },
  $template2
);
if (!$done_submit) { ?>
		    <input type='submit' id='submit' value='<?= $submit ?>'/>
<?php }
?>	
		<button onclick="makeChanges()" id="makeChangesButton" type="button">Want to change your booking? Click here</button>
		<p style='clear:both;text-align:center;font-size:small'>Need a hand with this? <a href='mailto:info@moylgrove.wales'>info@moylgrove.wales</a></p>
	</form>
	<?php 
}

function moylgrove_send_email(
  $template,
  $post_title,
  $post_link,
  $place_date_time = "Moylgrove Old School Hall",
  $bcc = '', 
  $state
) {
  $fields = $state['fields'];
  if (!isset($fields['email'])) {
    return;
  }
  $message = "";
  if (!isset($template) || strlen($template) == 0) {
    $message = "Thank you for booking seats at $place_date_time for '$post_title'.\n\n";
    foreach ($fields as $k => $v) {
      $message .= "$k : $v \n";
    }
    $message .= "\nClick to see or adjust your booking: $post_link\n";
  } else {
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
        $subs = $fields[$matches[1]] ?? ($matches[0] ?? "0");
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
  $headers = [
    "From: Moylgrove <info@moylgrove.wales>",
    "Bcc: info@moylgrove.wales",
  ];
  $copies = explode(',', $bcc);
  foreach ($copies as $copy) {
    if (strlen($copy) > 0) {
      //echo ("=== $copy ===");
      $headers[] = "Bcc: $copy";
    }
  }
  //echo ("==mail:<pre> " . $message . "</pre>==");
  $x = wp_mail(
    $fields['email'],
    "Booking at Moylgrove Hall",
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


  $template2 = preg_replace_callback(
    '#\{state\}(.*?)(?:\|\|(.*?)(?:\|\|(.*?)(?:\|\|(.*?))?)?)?\{/state\}#',
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


  // gt comparison - allows field names or literal numbers
  $template2 = preg_replace_callback(
    '#\{ *([^ {}]+) +gt +([^ ]+) *\}(.*?){/gt\}#',
    function ($matches)use($fields) {
      $term = function ($i) use ($matches, $fields) {
        return is_numeric($matches[$i]) ? $matches[$i] 
            : ($fields != null ? $fields[$matches[$i]] :  0);
      };
      if (count($matches) == 4) {
        $a = $term(1);
        $b = $term(2);
        if ($a > $b) {
          return $matches[3];
        } else {
          return "";
        }
      }
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

  if (!empty($full)) {
    if ($seatsBooked == 0) {
      echo "<h2>$full</h2>";
      return;
    } else {
      echo "<p>$full</p>";
    }
  }
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
			.moylgrove-form:not(.readonly) #makeChangesButton {
        display: none;
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
				  let prices = "<?= $prices ?>";
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
			  	jQuery('.wppaypal_checkout_description_input')
					.attr("value", 'Tickets for <?= $name ?> at Moylgrove Old School Hall');
				jQuery('.wppaypal_checkout_amount_input')
				  .attr("value",  amountDue );
				jQuery('.amount_due').html(amountDue);
			  	jQuery('.moylgrove-form input.calculated').attr('readonly', true);
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
			  	jQuery('.wppaypal_checkout_custom_input').attr("value", '<?= $id ?>');
			  });
		  </script>
		  
		<input type='hidden' id='id' name='id' value='<?= $id ?>' />
		<script>
			window.id = "<?= $id ?>";
			document.cookie= "id=<?= $id ?>; Max-Age=6000000"; // <3 months
		</script>
		<?php } else { ?>
		<script>
			jQuery(() =>{
				let amountField = jQuery('.wppaypal_checkout_amount_input');
				let buttons = amountField.parent();
				buttons.hide();
			});
		</script>
		  <?php }
}
?>