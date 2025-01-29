<?php

/// Shortcode for tabulating bookings
///
/// Full content only visible to users logged in to WP
/// Page URL parameters:
///   ?counters=1  -  See summarized sales counts even if not logged in
///   ?delete=id   -  Delete the cancelled (all seats==0) booking with the specified id
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
  if (array_key_exists('delete', $_GET)) {
    $wpdb->delete($table_name, ['id' => $_GET['delete']]);
    wp_redirect(".");
    exit();
  }

  // We'll return the content as a string:
  ob_start();

  $rows = $wpdb->get_results(
    $wpdb->prepare("SELECT * FROM $table_name WHERE name = %s", $name)
  );
  if ($style == "dump") {
    echo "<pre> $name";
    var_dump($rows);
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
        // Logged in user can see everything; others only see summed columns
        if ($fullTable || strpos(" $public ", " $k ") !== false ) {
			if (strpos($k, "#")>1) {
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
    echo $html ? "<p>$name</p><table class='bookings-table'>" : "<pre>$name\n";
    $pp = $html ? ["<tr><td>", "</td><td>", "</td></tr>\n"] : ["", ",", "\n"];
    echo $pp[0] . implode($pp[1], $heads) . $pp[2];

    $sums = [];
    foreach ($items as $item) {
      $cells = [];
      $rowsum = 0;
      $rowid = "";
      foreach ($heads as $head) {
        $cell = str_replace(",", ";", $item[$head] ?? "");
        $number = floatval($cell);
		if ($number < 1E6 && $head != "id" && $head != "timestamp") { // exclude phone numbers
          $sums[$head] = ($sums[$head] ?? 0) + $number;
		}
        if (strpos(" $sum ", " $head ") !== false) {
			$rowsum += $number;
        }
        if ($head == 'id') {
          $cells[] = "<a href='./?id=$cell' target='_blank'>$cell</a>";
          $rowid = $cell;
        } else {
          $cells[] = $cell;
        }
      }
      if ($rowsum == 0 && $fullTable) {
        $cells[] = "<a href='./?delete=$rowid'><span style='color:red;font-weight:700;'>X</span></a>";
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
  echo "<button style='color:black;border-radius:10px;padding:0 10px;'><a href='/wp-content/plugins/moylgrove-forms/csv.php?event=$name'>CSV</a></button>";
  ?>
<script>jQuery(() =>{
		jQuery(".entry-content.alignwide").css("max-width","none");
		jQuery(".moylgrove-form").css("max-width", "var(--responsive--alignwide-width)");
		jQuery(".bookings-table").css("font-size", "small");
	});</script>
<?php
	if ($showTable) return ob_get_clean();
	else {
		ob_clean();
		return "";
	}
}
add_shortcode("moylgrove-forms-table", "moylgrove_forms_table_shortcode");
?>
