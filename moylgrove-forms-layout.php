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
  $max = 0 // Ignore $full until count of bookings is > $max
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
$urlForReturn = get_permalink( get_the_ID() );
$timestamp = time();
$fields['paypal'] = "
		<div class='moylgrove-paypal-buttons'>
			[wp_paypal_checkout 
				description='Tickets for event at Moylgrove Old School Hall' 
				amount='0' no_shipping='1' custom='444' return_url='$urlForReturn?paid=$timestamp#form']
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

function moylgrove_send_email(
  $template, 
  $state,
  $post_title,
  $post_link,
  $place_date_time = "Moylgrove Old School Hall",
  $bcc = ''
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
	      	$menu_fields_json = json_encode($menu_fields);
		}

	    // Send menu field values and menu form
    	$menu = str_replace(["<br />","</p>\n","\n<p>"], ["","",""], $matches[1]);
      	return "<div class='menu'><!--[ $menu_fields_json ,  $menu ]--></div>" ;
    },
    $template2
  );

  // gt comparison - allows field names or literal numbers
  $template2 = preg_replace_callback(
    '#\{ *([^ {}]+) +gt +([^ ]+) *\}(.*?){/gt\}#',
    function ($matches)use($fields) {
      $term = function ($i) use ($matches, $fields) {
        return is_numeric($matches[$i]) ? $matches[$i] 
            : ($fields != null ? (isset($fields[$matches[$i]]) ? $fields[$matches[$i]] : 0) :  0);
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
			border-top: none;
		}
	}
	</style>
	<script>
		
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
            <input type="number" name="#${item[1]}" class="menu-count" value="${fields[item[1]] || ''}">
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
			menuSummary += `Seat ${i}: ${seatSelections[i].join(", ")}; <br />\n`;
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
			    setMenu();
				  reveal();
			  });
		  </script>
		  
		<input type='hidden' id='id' name='id' value='<?= $id ?>' />
		<script>
			window.id = "<?= $id ?>";
			document.cookie= "id=<?= $id ?>; Max-Age=6000000"; // <3 months
		</script>
		<?php 
	} else { ?>
		<script>
			jQuery(() =>{
				let amountField = jQuery('.wppaypal_checkout_amount_input');
				let buttons = amountField.parent();
				buttons.hide();
				
				setMenu();
				window.seatCount = 0;
				reveal();
			});
		</script>
		  <?php }
}
?>