<?php
add_action('admin_menu', 'moylgrove_forms_setup_menu');
 
function moylgrove_forms_setup_menu(){
    add_menu_page( 'Moylgrove Forms How to', 'Moylgrove Forms', 'manage_options', 'moylgrove-forms', 'moylgrove_forms_admin_page' );
}
 
function moylgrove_forms_admin_page(){
	?>
<h1>How to use Moylgrove Forms</h1>
<p>Forms are used in event posts to collect seat bookings.</p>
<p>Easiest way is to use a Standard form; or for finer control, create a Custom form.</p>
<h2>Standard Form</h2>
<p>In the text of the event page, include a shortcode like this:</p>
<blockquote><code>[moylgrove-standard-form date='Tuesday 15 February 2022 at 7:30p.m.' prices='adults 10 kids 3.50']</code></blockquote>
<p>This will show a booking form that will book seats and take money online.</p>
<p>Take care not to omit the quotation marks and the spaces, and the brackets at beginning and end.</p>
<p>The date of the event is used as a reminder in the acknowledgement email. You can write anything in there, like:
</p>
<blockquote><code>date='15:00 Tue Feb 15 at the beach - bring wellies!'</code></blockquote>
<h3>Prices</h3>
<p>If you don't want to take money in advance, but just reserve seats, omit <code>prices</code>.</p>
<br/>If kids are free, write <code>kids 0</code>.</p>
<p>If you want to charge a fixed price in advance for meals, include the price of a meal:</p>
<blockquote><code>prices='adults 8.50 kids 0 meals 5'</code></blockquote>
<p>(Adults, kids, and meals are the specific price options available in the Standard form.)</p>
<h3>Managing the bookings</h3>
<p>Login to WordPress and open the event's page (the published page - don't edit it). 
	The list of bookings appears underneath the booking form.</p>
<p><b>To adjust a booking</b> on a customer's behalf, click the ID in the first column.</p>
<p><b>To print the list</b> click the <b>CSV</b> button. Open the downloaded spreadsheet (in Excel or suchlike) and print it.</p>
<p>Keep an eye on the bookings to check when they're sold out.</p>
<p>If you want someone else to be able to see the bookings list, provide them with a login to this WordPress site. They need only the Subscriber role.</p>
<p>To have someone notified by email on each booking, set an extra parameter in the shortcode: <code>bcc='someone@gmail.com'</code>.
<p><b>Be aware that the personal details on the bookings list are protected by privacy laws.</b></p>
<h3>When you're sold out</h3>
<p>Edit the shortcode to include the <code>full</code> parameter:</p>
<blockquote><code>[moylgrove-standard-form full='Sold out - sorry!' date='Tuesday 15 February 2022 at 7:30p.m.' prices='adults 10 kids 3.50']</code></blockquote>
<p>This will disallow new bookings, while allowing existing customers to review their existing booking.</p>

<h3>Refunds</h3>
<p>If, after they have paid for some tickets, a customer changes the booking to reduce the number of seats they want, then we'll owe them a refund. 
	The system doesn't do this automatically. You'll have to login to our PayPal account and refund their payment. (Or you can give them the cash - but this
	loses the PayPal fee.)
<hr/>

<h2>Custom form</h2>
<p>Instead of the Standard form, you can set your own layout and fields. Create a <b>Custom booking form</b> 
and underneath that a <b>Custom bookings table</b>.</p>
<h3><i>Custom booking form</i></h3>
<p>Example:</p>
<pre>
[moylgrove-form prices="adults 9.50 kids 0"]
	&lt;style>
	   ... CSS for your form ...
	&lt;/style>
         &lt;h2>{state}Book your ticket||You've booked your ticket||You've cancelled your ticket||Pay for your ticket{/state}&lt;/h2>
	 &lt;div class="row">&lt;div class='moylgrove-paypal'><span>Please pay £ <span class="amount_due"></span></span>{paypal}&lt;/div>	 
 	 &lt;div class="row">&lt;div>Number of seats (including you):&lt;/div>
	 	&lt;div>&lt;label>Adults {adults n 0 10}&lt;/label>&lt;/div>&lt;div>
			&lt;label>Kids {kids n 0 10 ?}&lt;/label>&lt;/div>&lt;/div>
	{menu}
	["", "", 2, "&lt;h3>Main Course choices&lt;/h3>"],
  	["main","turkey",3,"&lt;b>Turkey&lt;/b> and stuffing"],
	["main","veggie",4,"&lt;b>Vegetarian&lt;/b> cardboard sausage"],
  	["main","vegan",5,"&lt;b>Vegan&lt;/b> Quorn sausage",],
  	["", "", 6, "&lt;h3>Dessert choices&lt;/h3>"],
	["sweet", "crumble", 7, "Spiced apple crumble"],
	["sweet", "brownie", 8, "Chocolate brownie"],
  	["","",13, "&lt;h3>Dietary requirements&lt;/h3>"],
  	["", "gf", 14, "Gluten free"]
	{/menu}
 	 &lt;div class="row">&lt;div>Your name:&lt;/div>&lt;div>
	 	&lt;label>first&lt;br>{first}
		&lt;/label>&lt;label>last&lt;br>{last}&lt;/label>&lt;/div>&lt;/div>
 	 &lt;div class="row">&lt;div>Address:&lt;/div>&lt;div>
	 	&lt;label>house&lt;br>{address}&lt;/label>
	 	&lt;label>post code&lt;br>{postcode text 10}&lt;/label>&lt;/div>&lt;/div>
 	 &lt;div class="row">&lt;div>Contact:&lt;/div>&lt;div>
	 	&lt;label>phone&lt;br>{phone text 14 0 ?}&lt;/label>
		&lt;label>email&lt;br>{email t 35}&lt;/label>&lt;/div>&lt;/div>
	 &lt;div class="row">&lt;div>Price:&lt;/div>&lt;div>
	 	&lt;label>amount&lt;br>£{totalPrice calc 6}&lt;/label>
		&lt;label>paid&lt;br>£{paid calc 6}&lt;/label>&lt;/div>&lt;/div>
     {paid gt totalPrice}We owe you some money - we'll refund it in due course{/gt}	

	 ======
 	 
	 Dear {first} {last},
 	 {state} - || Thank you for booking || You've cancelled your || - {/state}
	 	seats at Moylgrove Old School Hall for "{title}" on Tuesday 18 January 2022   at 19:30.
 	 
 	 You've booked seats for {adults} adults and {kids} under-16s. You're having {meals} meals.
	 The ticket price is £{totalPrice} - thanks for your payment of £ {paid}.

 	  
 	 We've got your address as: {address} {postcode}
 	 If we need to contact you in a hurry, we'll use: {phone}
 	 If you need to adjust your booking, find it here: {url}
 	 If you have any queries, please phone 01239 881752
 	 Thanks
 	 Cymdeithas Trewyddel
 	 {home}
[/moylgrove-form]
</pre>
<p>You write the HTML of your form within the shortcode body. Remember the closing tag.</p>
<p>There are two parts, separated by <code>====</code>. The first part is the HTML of the form; the second is the email template.</p>
<p>You can include a <code>&lt;style></code> section to set your form's layout.
<h4>Shortcode attributes</h4>
<p>Example:</p>
<pre>
	[moylgrove-form submit='Book your place' seats='adults kids' bcc='me@x.com' prices='adults 5 kids 0'] ... [/moylgrove-form]
</pre>
<dl>
	<dt><code>seats</code></dt>
	<dd>Numeric fields in your template that represent seats or items purchased such as meals or tshirts. If the user sets all these fields to 0, the <code>{state}</code> field will show the cancellation text. Default 'adults kids'</dd>
	<dt><code>prices='adults 10.00 kids 5.00 meals 3.50'</code></dt>
	<dd>If this is set, payment will be part of the booking process. List of pairs of 'field price'. A subset of the numeric fields in your template that will be used to compute the price. Usually a subset of seats. Put a space between each item and price.</dd>
	<dt><code>full='Sorry - sold out!'</code></dt>
	<dd>When you're sold out, edit the shortcode to include this. It will prevent further bookings, while allowing existing customers to review theirs.</dd>
	<dt><code>bcc</code></dt><dd>Additional email to notify when a user completes or revises a form.</dd>
</dl>
<h4>Fields in the HTML form template part</h4>
<dl>
	<dt><code>{state}invitation text || confirmation text || cancellation text || please pay text{/state}</code></dt>
	<dd>
		One of the four parts is displayed, depending on how far the user has got. </dd>
	<dt><code>{fieldName type lower upper ?}</code></dt>
	<dd>
		Displays an input field with the fieldName of your choice. Don't use one of the reserved names given here. <br/>
		<code>type</code> can be abbreviated to its initial letter, and is one of:
		<ul><li><code>numeric</code></li>
		<li><code>text</code></li>
		<li><code>email</code></li>
		<li><code>calculated</code> - for the specific fields <code>calculation</code>, <code>totalPrice,</code>, or <code>paid</code></li>
		<li><code>submit</code> - for the submit button
		<code>lower upper</code> Two numbers. For numeric fields, the range of allowed values. For text, <code>lower</code>=size of text field, <code>upper</code>=0<br/>
		<code>?</code> - if not present, the user must fill in the field. For text fields, write e.g. <code>{fieldname text 14 0 ?}</code>
	</dd>
	<dt><code>{totalPrice c  6}</code></dt>
	<dd>The total price as computed from the prices you provided.</dd>
	<dt><code>{paid c  6}</code></dt>
	<dd>The sum of payments the user has made for this booking. (They may have made more than one payment if they returned to the booking
		and changed the number of seats.)</dd>
	<dt><code>{calculation c 30}</code></dt>
	<dd>Shows how the totalPrice was worked out.</dd>
	<dt><code>{menu}...{/menu}</code>
	<dd>Presents the user with menu choices. There can be multiple choices, 
		e.g. Main course, Dessert. Each choice may each have several options.
		There's a separate column of choices for each seat booked.<br/>
		Form of each line - note the comma at the end:<br/>
	<code>["Radio_button_group","item_id","Name of menu item"],</code><br/>
	E.g. <code>["dessert","crumble","Apple crumble"],</code><br/>
	Missing group name is a checkbox: <code>["", "gf", "Gluten free"],</code><br/>
	Missing item_id is a section head or separator: <code>["", "", "&lt;h3>Dessert choices&lt;/h3>"],</code>
	</dd>
</dl>
<h4>Payment area</h4>
<dl>
	<dt><code>{paypal}</code></dt>
	<dd>Code for the PayPal buttons will be inserted here. The amount to be taken will be set to <code>totalPrice - paid</code>.</dd>
	<dt><code>&lt;span class="amount_due"&gt;&lt;/span&gt;</code></dt>
	<dd>The amount to be taken will be inserted here.</dd>
	<dt><code>&lt;div class='moylgrove-paypal'&gt; ... &lt;/div&gt;</code></dt>
	<dd>Any element with this class will be hidden except when payment is invited</dd>
</dl>
<h4>Fields in the mail template part</h4>
<ul>
	<li><code>{fieldName}</code> - a field you've defined in the form part.</li>
	<li><code>{fieldName value}</code> - e.g. <code>{kids 0}</code> - a field you've defined, together with a default value if the user hasn't filled it in .</li>
	<li><code>{url}</code> - the URL of the user's form, including their booking ID</li>
	<li><code>{home}</code> - the website URL</li>
	<li><code>{title}</code> - the title of the post</li>
	<li><code>{state} - || confirmation || cancellation || - {/state}</code> - acknowledge a booking or cancellation. The first and last parts aren't relevant.</li>
	<li><code>{fieldName gt fieldName}Some remarks{/gt}</code> - Text displayed only if the first field is greater than the second. The second may be a plain number such as 0.</li>

</ul>

<h3><i>Custom bookings table</i></h3>
<p>To display the bookings table (only to logged-in users), use a shortcode like this:</p>
<pre>
	[moylgrove-forms-table style=html sum="adults kids"]
</pre>
<p>Parameters:</p>
<ul>
	<li><code>style</code> = <code>html</code> or <code>csv</code>. Default html.</li>
	<li><code>sum</code> = list of numeric fields to be summed in the displayed table. Default 'adults kids'.</li>
</ul>
<p>The shortcode will display the full table only to users who are logged in to WP.</p>
<p>It will display a summary of the <code>sum</code> columns to anyone if the page URL ends <code>?counters=1</code>.</p>

<?php
}
?>