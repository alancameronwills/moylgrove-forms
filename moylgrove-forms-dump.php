<?php
// Shortcode for getting table content
function moylgrove_forms_dump_shortcode($attributes = [], $content = null)
{
	global $wpdb;
    // normalize attribute keys, lowercase
    $attributes = array_change_key_case( (array) $attributes, CASE_LOWER );
  extract(
    shortcode_atts(
      [
        'name' => get_queried_object()->post_name ?? "test form", // name of form & template
        'debug' => ''
      ],
      $attributes
    )
  );
	ob_start();
	
	$table_name = $wpdb->prefix . 'moylgrove_forms';
	$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE name = %s", $name));
	echo ("<pre> $name");
	var_dump($rows);
	echo ("</pre>");
	
  	return ob_get_clean();
}
add_shortcode("moylgrove-forms-dump", "moylgrove_forms_dump_shortcode");
?>