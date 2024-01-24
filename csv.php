<?php

function moylgrove_show_csv($rows, $name, $sum)
{
  $columnheads = [];
  $items = [];
  foreach ($rows as $row) {
    $item = json_decode($row->text, true);
    $item['id'] = $row->id;
    $item['timestamp'] = $row->time;
    foreach (array_keys($item) as $k) {
      $columnheads[$k] = 1;
    }
    $items[] = $item;
  }
  $heads = array_keys($columnheads);
  //echo "$name\n";
  $pp = ['"', '","', "\"\n"];
  echo $pp[0] . implode($pp[1], $heads) . $pp[2];

  $sums = [];
  foreach ($items as $item) {
    $cells = [];
    foreach ($heads as $head) {
      $cell = str_replace(",", ";", $item[$head] ?? "");

      if (strpos(" $sum ", " $head ") !== false) {
        $sums[$head] = ($sums[$head] ?? 0) + (float)($cell);
      }

      $cells[] = is_numeric($cell) ? "=\"\"$cell\"\"" : $cell;
    }
    echo $pp[0] . implode($pp[1], $cells) . $pp[2];
  }

  $total = 0;
  $cells = [];
  foreach ($heads as $head) {
    $v = $sums[$head] ?? "";
    $cells[] = $v;
    //$total += intval($v);
  }
  echo $pp[0] . implode($pp[1], $cells) . $pp[2];
  if ($total > 0) {
    echo "Total $total";
  }
}

/** Sets up the WordPress Environment. */
require __DIR__ . '/../../../wp-load.php';
nocache_headers();

$filename = "bookings.csv";
$name = $_GET['event'];
$sums = "adults kids meals paid";
if (isset($_GET['sum'])) {
  $sums = $_GET['sum'];
}

header('Content-Disposition: attachment; filename=' . $filename);
header("Content-type: text/csv");
ob_clean();
flush();

$table_name = $wpdb->prefix . 'moylgrove_forms';
$rows = $wpdb->get_results(
  $wpdb->prepare("SELECT * FROM $table_name WHERE name = %s", $name)
);
moylgrove_show_csv($rows, $name, $sums);
exit();

// https://moylgrove.wales/wp-content/plugins/moylgrove-forms/csv.php?event=skokholm-dream-island&sum=adults+kids+dogs
?>