<?php
/// Command-line helper for the Playwright tests: sets up and tears down the test pages,
/// users and mail capture, and reads/edits bookings in the database.
///
///   php helper.php setup | teardown | clear
///   php helper.php bookings <event>
///   php helper.php setpaid <id> <amount>
///   php helper.php setfield <id> <key> <value>
///   php helper.php mails
///
/// Everything it creates is named "mg-test-..." / "mgtest_..." and removed by teardown.
/// Output is a line "@@JSON@@" followed by JSON, because WP_DEBUG may print notices first.

if (php_sapi_name() !== 'cli') {
  exit;
}
define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST'] = 'localhost';
require dirname(__DIR__, 4) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;
$table = $wpdb->prefix . 'moylgrove_forms';
$mailLog = __DIR__ . '/.mail-log.jsonl';
$muPlugin = WPMU_PLUGIN_DIR . '/mg-test-mail-capture.php';
$pages = ['paid', 'free', 'noemail', 'table'];
$users = ['editor' => 'editor', 'subscriber' => 'subscriber'];

function out($data)
{
  echo "\n@@JSON@@" . json_encode($data) . "\n";
}

function clear_rows()
{
  global $wpdb, $table, $mailLog;
  $wpdb->query("DELETE FROM $table WHERE name LIKE 'mg-test-%'");
  // Rejection emails are de-duplicated with transients; pending-payment emails use them too
  $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_moylgrove\\_%' OR option_name LIKE '\\_transient\\_timeout\\_moylgrove\\_%' OR option_name LIKE 'seatCount\\_mg-test-%'");
  wp_cache_flush();
  @unlink($mailLog);
}

$cmd = $argv[1] ?? '';
switch ($cmd) {
  case 'setup':
    wp_set_current_user(1);
    kses_remove_filters(); // fixture pages hold <style>, onclick etc. as real pages do
    $result = ['base' => home_url(), 'pages' => [], 'users' => []];
    foreach ($pages as $key) {
      $slug = "mg-test-$key";
      $content = file_get_contents(__DIR__ . "/fixtures/$key.txt");
      $existing = get_page_by_path($slug);
      $id = wp_insert_post([
        'ID' => $existing ? $existing->ID : 0,
        'post_title' => "MG test $key",
        'post_name' => $slug,
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_content' => $content,
      ]);
      $result['pages'][$key] = get_permalink($id);
    }
    foreach ($users as $key => $role) {
      $login = "mgtest_$key";
      $pass = wp_generate_password(20, false);
      $user = get_user_by('login', $login);
      if ($user) {
        wp_set_password($pass, $user->ID);
        $uid = $user->ID;
      } else {
        $uid = wp_create_user($login, $pass, "$login@example.invalid");
      }
      (new WP_User($uid))->set_role($role);
      $result['users'][$key] = ['login' => $login, 'pass' => $pass];
    }
    // Capture mail instead of sending it, for the duration of the run
    if (!is_dir(WPMU_PLUGIN_DIR)) {
      mkdir(WPMU_PLUGIN_DIR);
    }
    file_put_contents($muPlugin, "<?php\n// Installed by moylgrove-forms/tests; removed by its teardown.\n"
      . "add_filter('pre_wp_mail', function (\$r, \$a) {\n"
      . "  file_put_contents(" . var_export($mailLog, true) . ", json_encode(['to' => \$a['to'], 'subject' => \$a['subject'], 'message' => \$a['message'], 'headers' => \$a['headers']], JSON_INVALID_UTF8_SUBSTITUTE) .\"\\n\", FILE_APPEND);\n"
      . "  return true;\n}, 10, 2);\n");
    clear_rows();
    out($result);
    break;

  case 'teardown':
    clear_rows();
    foreach ($pages as $key) {
      $page = get_page_by_path("mg-test-$key");
      if ($page) {
        wp_delete_post($page->ID, true);
      }
    }
    foreach ($users as $key => $role) {
      $user = get_user_by('login', "mgtest_$key");
      if ($user) {
        wp_delete_user($user->ID);
      }
    }
    @unlink($muPlugin);
    @rmdir(WPMU_PLUGIN_DIR); // only succeeds if we created it and it's empty
    out(true);
    break;

  case 'clear':
    clear_rows();
    out(true);
    break;

  case 'bookings':
    $rows = $wpdb->get_results($wpdb->prepare("SELECT id, name, text FROM $table WHERE name = %s ORDER BY id", $argv[2]));
    out(array_map(function ($r) {
      return ['id' => (int) $r->id, 'name' => $r->name, 'raw' => $r->text, 'data' => json_decode($r->text, true)];
    }, $rows));
    break;

  case 'setpaid':
  case 'setfield':
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $argv[2]));
    $data = json_decode($row->text, true);
    if ($cmd == 'setpaid') {
      $data['paid'] = number_format((float) $argv[3], 2, '.', '');
    } else {
      $data[$argv[3]] = $argv[4];
    }
    $wpdb->update($table, ['text' => json_encode($data)], ['id' => (int) $argv[2]]);
    out(true);
    break;

  case 'mails':
    $lines = file_exists($mailLog) ? file($mailLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    out(array_map(function ($l) {
      return json_decode($l, true);
    }, $lines));
    break;

  default:
    fwrite(STDERR, "Unknown command\n");
    exit(1);
}
