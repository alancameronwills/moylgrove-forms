<?php

/// Who may delete bookings, record refunds, or download the CSV of personal details.
function moylgrove_can_manage_bookings()
{
  return current_user_can('edit_posts');
}

function record_refund($bookingId, $table_name)
{
  global $wpdb;
  $rowSet = $wpdb->get_results(
    $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $bookingId)
  );

  foreach ($rowSet as $row) {
    $item = json_decode($row->text, true);
    $totalPrice = $item['totalPrice'] ?? 0;
    $paid = $item['paid'] ?? 0;
    if ($paid > $totalPrice) {
      $item['paid'] = $totalPrice;
      $fields = json_encode($item);
      $wpdb->update(
        $table_name,
        array(
          'text' => $fields
        ),
        array(
          'id' => $bookingId
        )
      );
    }
    break;
  }
}

/// Shortcode for tabulating bookings
///
/// Full content only visible to users logged in to WP
/// Page URL parameters:
///   ?counters=1  -  See summarized sales counts even if not logged in
///   ?delete=id   -  Delete the cancelled (all seats==0) booking with the specified id
///   ?refunded=id -  Reset the paid field to equal the totalPrice
/// Shortcode attributes all optional:
///   name=(this event) - WP slug (URL suffix) of the event page (defaults to the current one)
///   style=[html|text|dump] - Show HTML layout, plain text
///   sum="adults kids meals" - Numeric fields to sum up and show totals at bottom
///   debug=[true|false]
/// 
function moylgrove_forms_table_shortcode($attributes = [], $content = null)
{
  // The bookings table contains personal info - emails, numbers, addresses
  // So we only allow logged-in users to see it.
  // Non-logged in users can see the counts of seats by appending "?counters=1" to the page URL
  $fullTable = is_user_logged_in();
  $showTable = $fullTable || isset($_GET['counters']);

  // normalize attribute keys, lowercase:
  $attributes = array_change_key_case((array) $attributes, CASE_LOWER);
  extract(
    shortcode_atts(
      [
        'name' => get_queried_object()->post_name ?? "test form", // name of form & template
        'style' => 'html', // html or (plain) text
        'sum' => "adults kids", // columns of which to count up totals
        'public' => '', // other columns to display even if not logged in
        'debug' => ''
      ],
      $attributes
    )
  );
  //$sum .= " paid"; // also count up the fixed-name PayPal payments

  global $wpdb;
  $table_name = $wpdb->prefix . 'moylgrove_forms';

  // Click the red X next to a cancelled booking to purge it:
  // Anyone can load this page, so these only act for a permitted user with a valid nonce
  // (the links we generate carry one); otherwise the parameter is ignored.
  if (array_key_exists('delete', $_GET) && moylgrove_can_manage_bookings()) {
    $deleteId = absint($_GET['delete']);
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', "moylgrove_delete_$deleteId")) {
      wp_die('This link has expired. Please go back and reload the page.', '', ['response' => 403]);
    }
    // Only a cancelled booking (no seats) of this event may be purged
    $row = $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d AND name = %s", $deleteId, $name)
    );
    if ($row && currentBookingState($row, $sum, '')['seatsBooked'] == 0) {
      $wpdb->delete($table_name, ['id' => $deleteId]);
    }
    wp_redirect(".");
    exit();
  }

  // Record that an overpayment has been refunded:
  if (array_key_exists('refunded', $_GET) && moylgrove_can_manage_bookings()) {
    $bookingId = absint($_GET['refunded']);
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', "moylgrove_refund_$bookingId")) {
      wp_die('This link has expired. Please go back and reload the page.', '', ['response' => 403]);
    }
    record_refund($bookingId, $table_name);
    wp_redirect(".");
    exit();
  }

  // We'll return the content as a string:
  ob_start();

  $rows = $wpdb->get_results(
    $wpdb->prepare("SELECT * FROM $table_name WHERE name = %s", $name)
  );
  if ($style == "dump") {
    foreach ($rows as $row) $row->text = preg_replace('#<br \\\\?/>#', ' ', $row->text); // as stored it's <br \/> // line breaks are for emails
    echo "<pre> $name";
    ob_start();
    var_dump($rows);
    echo esc_html(ob_get_clean()); // what people typed must not run as HTML
    echo "</pre>";
  } else {
    $html = $style == "html";

    $columnheads = [];
    $items = [];
    foreach ($rows as $row) {
      $item = json_decode($row->text, true);
      $item['id'] = $row->id;
      $item['timestamp'] = $row->time;
      foreach (array_keys($item) as $k) {
        if ($k === 'key') {
          continue; // the booking's secret link key isn't for display
        }
        // Logged in user can see everything; others only see summed columns
        if ($fullTable || strpos(" $public ", " $k ") !== false) {
          if (strpos($k, "#") > 1) {
            // exclude detail menu items
          } else {
            $columnheads[$k] = 1;
          }
        }
      }
      $items[] = $item;
    }

    // Remove uninformative columns
    unset($columnheads['calculation']);
    unset($columnheads['description']);
    unset($columnheads['price']);
    unset($columnheads['amount']);

    $heads = array_keys($columnheads);
    $style = "<style>
    .rowButton{color: darkred;
      font-weight: 700;
      cursor: pointer;
      border: red 1px solid;
      padding: 6px;
      border-radius: 4px;
      background-color: white;}</style>";
    // Everything below comes from what people typed into forms, so it's escaped for display
    // (the plain-text style is shown inside <pre>, so it needs it too)
    echo $html ? "$style<p>" . esc_html($name) . "</p><table class='bookings-table'>" : "<pre>" . esc_html($name) . "\n";
    $pp = $html ? ["<tr><td>", "</td><td>", "</td></tr>\n"] : ["", ",", "\n"];
    echo $pp[0] . implode($pp[1], array_map('esc_html', $heads)) . $pp[2];

    $sums = [];
    foreach ($items as $item) {
      $cells = [];
      $rowsum = 0;
      $rowid = "";
      $totalPrice = 0;
      $paid = 0;
      foreach ($heads as $head) {
        $cell = $item[$head] ?? "";
        $cell = is_scalar($cell) ? str_replace(",", ";", (string) $cell) : "";
        $number = floatval($cell);
        if ($number < 1E6 && $head != "id" && $head != "timestamp") { // exclude phone numbers
          $sums[$head] = ($sums[$head] ?? 0) + $number;
        }
        if (strpos(" $sum ", " $head ") !== false) {
          $rowsum += $number;
        }
        if ($head == 'id') {
          $rowid = absint($cell);
          $cells[] = "<a href='./?id=$rowid' target='_blank'>$rowid</a>";
        } else {
          // the menu summary's line breaks are for emails
          $cells[] = esc_html(is_string($cell) ? preg_replace('#<br ?/?>#', ' ', $cell) : $cell);
        }
        if ($head == 'totalPrice') {
          $totalPrice = floatval($cell);
        }
        if ($head == 'paid') {
          $paid = floatval($cell);
        }
      }
      if ($html) {
        if (moylgrove_can_manage_bookings()) {
          if ($paid > $totalPrice) {
            $nonce = wp_create_nonce("moylgrove_refund_$rowid");
            $cells[] = "<span onclick='refund($rowid, $paid-$totalPrice, \"$nonce\")' class='rowButton'>Refund</span>";
          } else if ($rowsum == 0) {
            $nonce = wp_create_nonce("moylgrove_delete_$rowid");
            $cells[] = "<a href='./?delete=$rowid&_wpnonce=$nonce'><span class='rowButton'>X</span></a>";
          }
        }
      }
      echo $pp[0] . implode($pp[1], $cells) . $pp[2];
    }

    $total = 0;
    $cells = [];
    foreach ($heads as $head) {
      $v = $sums[$head] ?? "";
      $cells[] = ($v != 0 ? $v : "");
      if (strpos(" $sum ", " $head ") !== false) {
        $total += intval($v);
      }
    }
    echo $pp[0] . implode($pp[1], $cells) . $pp[2];
    echo $html ? "</table>" : "</pre>";
    if ($total > 0) {
      echo "<p>Total $total</p>";
      add_option("seatCount_" . $name);
      update_option("seatCount_" . $name, $total);
    }
  }
  echo "<button style='color:black;border-radius:10px;padding:0 10px;'><a href='/wp-content/plugins/moylgrove-forms/csv.php?event=" . urlencode($name) . "'>CSV</a></button>";
?>
  <script>
    jQuery(() => {
      jQuery(".entry-content.alignwide").css("max-width", "none");
      jQuery(".moylgrove-form").css("max-width", "var(--responsive--alignwide-width)");
      jQuery(".bookings-table").css("font-size", "small");
    });

    function refund(id, amount, nonce) {
      let refundDialog = document.getElementById("refundDialog");
      refundDialog.bookingId = id;
      refundDialog.nonce = nonce;
      let refundAmountSpan = document.getElementById("refundAmountSpan");
      refundAmountSpan.innerText = amount;
      refundDialog.showModal();
    }

    function closeRefunder() {
      let refundDialog = document.getElementById("refundDialog");
      refundDialog.close();
    }

    function doneRefund() {
      let refundDialog = document.getElementById("refundDialog");
      location = `./?refunded=${refundDialog.bookingId}&_wpnonce=${refundDialog.nonce}`;
    }
  </script>
  <style>
    dialog {
      align-self: center;
      border-radius: 10px;
    }

    dialog::backdrop {
      background-color: rgba(0, 0, 0, 0.25);
    }

    dialog>div {
      display: flex;
      justify-content: space-around;
      padding: 10px;
    }

    dialog button {
      color: black !important;
      border: black 2pt solid;
      padding: 6px 10px;
      border-radius: 10px;
    }
  </style>
  <dialog id="refundDialog" closedby="any">
    Has the customer been refunded £<span id='refundAmountSpan'></span>
    (usually via PayPal)?
    <div>
      <button onclick="closeRefunder()" style="background-color:beige;" class="close">Not yet</button>
      <button onclick="doneRefund()" style="background-color:lightgreen;">Yes, has been refunded</button>
    </div>
  </dialog>
<?php
  if ($showTable) return ob_get_clean();
  else {
    ob_clean();
    return "";
  }
}
add_shortcode("moylgrove-forms-table", "moylgrove_forms_table_shortcode");
?>