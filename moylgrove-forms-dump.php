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
	foreach ($rows as $row) $row->text = preg_replace('#<br \\\\?/>#', ' ', $row->text); // as stored it's <br \/> // line breaks are for emails
	echo ("<pre> $name");
	ob_start();
	var_dump($rows);
	echo esc_html(ob_get_clean()); // what people typed must not run as HTML
	echo ("</pre>");
	
  	return ob_get_clean();
}
add_shortcode("moylgrove-forms-dump", "moylgrove_forms_dump_shortcode");
?>