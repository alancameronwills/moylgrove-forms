<?php

/// One quoted CSV cell. Quotes and newlines in the value are safe. Numbers are written as ="123",
/// which Excel shows as text, so a phone number keeps its leading 0. Text that a spreadsheet
/// would run as a formula (starts with = + - @) is made harmless with a leading apostrophe.
function moylgrove_csv_cell($value)
{
  if (is_array($value)) {
    $value = implode('; ', array_map('strval', array_filter($value, 'is_scalar')));
  }
  $value = (string) $value;
  if (is_numeric($value)) {
    $value = "=\"$value\"";
  } elseif ($value !== '' && strpos("=+-@\t\r", $value[0]) !== false) {
    $value = "'" . $value;
  }
  return '"' . str_replace('"', '""', $value) . '"';
}

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
  echo implode(',', array_map('moylgrove_csv_cell', $heads)) . "\n";

  $sums = [];
  foreach ($items as $item) {
    $cells = [];
    foreach ($heads as $head) {
      $cell = $item[$head] ?? "";
      if (is_array($cell)) {
        $cell = implode('; ', array_map('strval', array_filter($cell, 'is_scalar')));
      }

      if (strpos(" $sum ", " $head ") !== false) {
        $sums[$head] = ($sums[$head] ?? 0) + (float)($cell);
      }

      $cells[] = moylgrove_csv_cell($cell);
    }
    echo implode(',', $cells) . "\n";
  }

  $total = 0;
  $cells = [];
  foreach ($heads as $head) {
    $v = $sums[$head] ?? "";
    $cells[] = $v;
    //$total += intval($v);
  }
  echo implode(',', array_map('moylgrove_csv_cell', $cells)) . "\n";
}

/** Sets up the WordPress Environment. */
require __DIR__ . '/../../../wp-load.php';
nocache_headers();

// The export contains everyone's personal details: only for permitted logged-in users
if (!moylgrove_can_manage_bookings()) {
  status_header(403);
  exit('Please log in to download the bookings.');
}

$filename = "bookings.csv";
$name = sanitize_text_field(wp_unslash($_GET['event'] ?? ''));
if ($name === '') {
  status_header(400);
  exit('Missing event.');
}
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