<?php
function moylgrove_standard_form($attributes = []) 
{
    $attributes = array_change_key_case( $attributes, CASE_LOWER );
  	extract(
    	shortcode_atts(
      	[
        	'date' => '',
			'meals' => '',
			'bcc' => '',
			'full' => '', // message to show when bookings exceeds max
			'max' => 0, // allow this number of bookings before showing full
			'prices' => 'adults 10 kids 0',
			'note' => ''
      	],
      	$attributes
    	)
  	);
	ob_start();
	?>
		  
<!-- wp:columns {"style":{"color":{"background":"#78d3f2"}},"textColor":"dark-gray"} -->
<div class="wp-block-columns has-dark-gray-color has-text-color has-background" style="background-color:#78d3f2"><!-- wp:column -->
<div class="wp-block-column">
<!-- wp:shortcode -->
[moylgrove-form bcc='<?=$bcc?>' <?=empty($full)?'':"full='$full'"?> max=<?=$max?> prices='<?=$prices?>']
	<h2 id='formHeader'>{state}Book your seat||You've booked your seat||You've cancelled your booking||Pay for your seat{/state}</h2>
	<?php
	if (strlen($prices)) {
		?>
	<style>
		.moylgrove-paypal {
			background-color: dodgerblue !important;
    		justify-content: space-around !important;
    		align-items: center;
			text-align: center;
    		font-weight: bold;
    		font-size: min(10vw,72pt);
    		color: white;
		}
		.moylgrove-paypal-buttons {
			/*width:min(300px,30vw);*/
		}
		.moylgrove-paypal-buttons>div>div {
			display:flex;
		    align-items: center;
			margin-top:2vw;
		}
	</style>

	<div class="row moylgrove-paypal" style="display:none">
		<div class="moylgrove-amount-due">
			<div style="font-size:min(3vw,18pt)">Please pay</div>
			£ {amountDue}
		</div>
		{paypal}
	</div>
	<?php
	}
	?>
	<div class="row"><div>Number of seats (including you):</div><div><label>Adults {adults n 0 10}</label> <label>Under-16s {kids n 0 10 ?}</label></div></div>
<?php 
	if (strlen($meals)) {
		?>
	<div class="row"><div><label><?=$meals?></label> </div><div>{meals n 0 10} </div><div>Diet...? {diet t 30 ?} </div></div>
	<?php
	}
	?>
	<?php 
	if (strlen($note)) {
		?>
	<div class="row"><div><label><?=$note?></label> </div><div> </div><div> {note t 30 ?} </div></div>
	<?php
	}
	?>
<div class="row"><div>Your name:</div><div><label>first<br>{first}</label><label>last<br>{last?}</label></div></div>
<div class="row"><div>Address:</div><div><label>house<br>{address}</label><label>post code<br>{postcode text 10}</label></div></div>
	<div class="row"><div>Contact:</div>
		<div><label>phone<br>{phone text 14}</label><label>email<br>{email t 30}</label></div>
	</div>
	<?php
	if (strlen($prices)) {
		?>
		
	<div class="row"><div>Price<a href="./?counters=1">:</a></div>
		<div><label>calculation<br>{calculation c 40}</label>
			<label>amount<br>£ {totalPrice c 6}</label>
			<label>paid<br>£ {paid c 6}</label>
		</div>
		{paid gt totalPrice}<p>We owe you some money. We will refund it soon.</p>{/gt}
	</div>
	<?php
	}
	?>
======
Dear {first} {last},

{state}Seats||Thank you for paying for seats||You've cancelled your booking||Seats booked (not paid yet){/state} at Moylgrove Old School Hall for "{title}" on <?=$date?>.

You've booked: 
     Adults:   {adults}         Under-16s: {kids}
<?php 
	if (strlen($meals)) {
		?>
	<?=$meals?>: {meals} {diet}
	<?php
	}
	?>
<?php 
	if (strlen($note)) {
		?>
	<?=$note?>: {note}
	<?php
	}
	?>
	
{1 gt paid}You can pay online or at the door{/gt}
	
{totalPrice gt 0}We'll have your name on a list when you come to the event. You can bring a copy of this just in case.{/gt}

<?php 
	if (strlen($prices)) {
		?>
    Ticket price: £{totalPrice}
    You've paid:  £{paid}
	{paid gt totalPrice}We owe you some money. We will refund it soon.{/gt}
	<?php
	}
	?>

	
We've got your address as: {address} {postcode}

If we need to contact you in a hurry, we'll use: {phone}

If you need to adjust your booking, click this link: {url}


Thanks
Cymdeithas Trewyddel
{home}

[/moylgrove-form]
<!-- /wp:shortcode -->

<!-- wp:shortcode -->
[moylgrove-forms-table style=html sum="adults kids meals" public="adults kids meals note paid"]
<!-- /wp:shortcode --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
	<?php
	
	return do_shortcode(ob_get_clean());
}
add_shortcode("moylgrove-standard-form", "moylgrove_standard_form");