# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a WordPress plugin for managing event seat bookings with PayPal payment processing. It is purely procedural PHP — no OOP, no build tools. Browser tests are in `tests/` (see Tests below).

**Payment**: Uses the PayPal JS SDK directly (no `wp-paypal` plugin needed). PayPal Client ID and Secret are configured in the Moylgrove Forms admin page (Sandbox and Live). Payment uses two WP REST endpoints: `POST /wp-json/moylgrove/v1/create-order` and `POST /wp-json/moylgrove/v1/capture-order`.

## Development

There are no build steps. To develop:
- Place the plugin directory in `/wp-content/plugins/` and activate it in the WordPress admin dashboard.
- PHP can be linted with `phpcs` or statically analyzed with `phpstan`, but no configuration for these is included.

## Architecture

### Files

| File | Purpose |
|------|---------|
| `moylgrove-forms.php` | Main plugin entry point: activation/deactivation hooks, DB table creation, PayPal event handlers |
| `moylgrove-forms-custom-form.php` | Renders `[moylgrove-form]` shortcode; core template parsing engine and email dispatch |
| `moylgrove-forms-standard-form.php` | Pre-built `[moylgrove-standard-form]` shortcode wrapping the custom form |
| `moylgrove-forms-layout.php` | Field rendering (HTML inputs), price calculation, form state management |
| `moylgrove-forms-table.php` | `[moylgrove-forms-table]` shortcode: booking list, CSV download link, delete |
| `moylgrove-forms-dump.php` | Debug shortcode `[moylgrove-forms-dump]` for raw data output |
| `csv.php` | Direct PHP endpoint for CSV export (loads WordPress via `wp-load.php`) |

### Database

Single table `{wp_prefix}moylgrove_forms`:
```
id (MEDIUMINT AUTO_INCREMENT), time (DATETIME), name (TEXT - WP page slug), text (TEXT, 64KB - JSON fields; saves are refused above 60000 bytes)
```

### Booking States

```php
MG_BOOK_STATUS = 1    // Fresh/new booking
MG_PAY_STATUS = 4     // PayPal payment pending
MG_BOOKED_STATUS = 2  // Paid and confirmed
MG_CANCELLED_STATUS = 3 // All seat quantities = 0
```

### Template Syntax

The `[moylgrove-form]` shortcode body uses a custom template language:
- `{fieldName n 0 10}` — numeric field (min 0, max 10)
- `{fieldName text 25}` — text input (max 25 chars), `?` suffix = optional
- `{fieldName calc 6}` — calculated/read-only field (width 6)
- `{fieldName e 25}` — email input
- `{state}msg1||msg2||msg3||msg4{/state}` — conditional text by booking state (BOOK/BOOKED/CANCELLED/PAY)
- `{fieldName gt number}text{/gt}` — show text if field value > number
- `{menu}...{/menu}` — seat-by-seat meal/dietary choice matrix
- `======` line separates the HTML form template from the email template

### Shortcode Parameters

**`[moylgrove-standard-form]`**: `date`, `prices`, `max`, `full`, `meals`, `note`, `bcc`

**`[moylgrove-form]`**: `prices` (e.g., `"adults 15 kids 10"`), `seats`, `sum`, `max`, `full`, `bcc`, `submit`

**`[moylgrove-forms-table]`**: `style` (html/text/dump), `sum`, `public`, `name`

### URL Parameters

- `?id=123&key=<16 hex>` — load a specific booking for editing (the key is required; see Booking Keys)
- `&_t=timestamp` — added by the JS when it reloads the page after a PayPal capture, to defeat caching. It carries no meaning for the server; the confirmation email is driven by the `confirm_due` flag in the row, not by the URL.
- `?counters=1` — show anonymized seat counts without login
- `?delete=id` — delete a cancelled booking

### Submission Validation

The browser's `required` attributes can't be relied on: the menu radios, the `{diet ?}` box inside `{menu}` and `menuSummary` are all built by JavaScript, so if the page's JS fails an incomplete form can be posted. So `moylgrove_validate_submission()` (in `moylgrove-forms-layout.php`) re-checks every POST against the form template before anything is stored or emailed:
- Fields are required unless marked `?`; numeric fields are range-checked and normalised (`"02"` becomes `2`); emails are checked with `is_email`.
- If `prices` is set, each seat (up to 8) needs a choice in every `{menu}` row group marked `"!"` (mirrors the JS rule that `seatCount` is the sum of the priced fields).
- On failure nothing is saved and no email is sent. The form is redisplayed with what they typed and an error notice, and `moylgrove_notify_rejected()` emails info@moylgrove.wales the problems, submitted fields and browser, so we can help the person.
- If you change the field syntax in `moylgrove_print_form`, keep the validator's regex and required-rules in step.
- In the email template, a `{field}` that wasn't submitted renders blank (or its `{field default}`), not as a literal placeholder.

### What the server trusts

Everything posted is untrusted; the browser's checks are conveniences only.
- **Which posts are bookings**: only a POST carrying the hidden field `moylgrove_booking` equal to this event's name (printed by `moylgrove_print_form`). Other posts to the page (comments, search) are ignored. Rejection emails to the organisers are de-duplicated per payload and capped at `MG_MAX_REJECTION_EMAILS` (10) an hour.
- **What is stored**: `moylgrove_clean_submission()` keeps only fields the template declares (not `c`/`s` fields, `paypal`, `amountDue`, `state`, `menu`, `key`) plus valid menu keys (`#option`, `group#1..8`, `menuSummary`). Text is capped (`MG_MAX_TEXT` 500, `MG_MAX_EMAIL` 254). Numbers must be whole and, unless a lower limit says otherwise, not negative.
- **Money**: `totalPrice` and `calculation` are always recomputed from `prices` and the counts (`moylgrove_compute_price`). `paid` is never taken from the browser: it is the stored value, changed only by PayPal capture or the refund button.
- **PayPal orders**: `create-order` stamps the booking id into the order as `custom_id`. `capture-order` first fetches the order and refuses it unless every purchase unit carries this booking's id (`moylgrove_order_is_for_booking`), then captures and credits only COMPLETED GBP captures stamped with the id (`moylgrove_captured_amount`). Note PayPal's *capture reply* puts `custom_id` on each capture, not on the purchase unit (the fetched order has it on the unit), so both places count. Any failure writes PayPal's reply to the PHP error log (lines starting `moylgrove-forms: PayPal`) and the page tells the customer the payment could not be confirmed and not to pay again; the organiser then fixes `paid` in the row by hand.
- **Saving**: a save that fails (DB error, over 60000 bytes) is logged, emailed to the organisers and the form shown again; never reported as a booking.
- **Output**: every value printed into HTML, attributes or JavaScript is escaped (`esc_attr`/`esc_html`, `JSON_HEX_*` for JSON in comments and scripts). The `id` is accepted only as a positive whole number.
- **Permissions**: delete, refund and the CSV need `edit_posts` (`moylgrove_can_manage_bookings()`); delete and refund also need nonces. Delete only works on a cancelled booking of the same event.
- **Emails**: with an email template, it goes to the customer's `email` field if that is valid (organisers on Bcc). With no valid customer address the organisers get it instead. With no template, a short thank-you listing the fields goes the same way.
- **Menu summary**: the browser builds `menuSummary` ("Seat 1: …; <br />Seat 2: …") in a one-line input, so the `<br />` is what puts each seat on its own line in emails (the email code turns it into a newline). Stored by PHP it reads `<br \/>`. The CSV, the dumps and the table cells replace it with a space.
- **CSV**: every cell is quoted; text starting `= + - @` gets a leading apostrophe; numbers are written `="0123"`; the booking key is omitted.

### Email timing and payment confirmation

- **Free booking or cancellation**: the email is sent immediately when the form is posted.
- **Booking that needs payment**: the "awaiting payment" email is delayed. `moylgrove_schedule_pending_email` stores what's needed in a 20-minute transient (`moylgrove_pending_email_<id>`) and schedules a WP-Cron event (`moylgrove_delayed_email`, 5 minutes later). `moylgrove_send_delayed_email` sends it unless the booking has been paid by then, and deletes the transient. **The transient exists only for this delayed message — the payment confirmation must never depend on it** (a person may pay minutes, days or weeks later, after the transient is gone).
- **Payment confirmation**: `capture-order` credits `paid`, cancels the scheduled delayed email and sets `confirm_due = '1'` in the row's JSON; it sends no email itself because the email template lives in the page's shortcode, not the row (and `[moylgrove-standard-form]` generates that shortcode in PHP, so it can't be re-parsed from the post). The JS then reloads the page (`location.replace(…?id=…&key=…&_t=…)`), and `moylgrove_form_shortcode`, which has the template, sees `confirm_due`, clears it in the row first (so refreshes and second tabs can't resend), then sends the confirmation to the row's `email`. If the browser closes between PayPal and the reload, the flag stays and the email goes out on the next view of that booking.
- Scenario that must keep working: book and click "Book and pay", close the window, come back days later via the cookie, pay, and receive the confirmation.

### Booking Keys

Ids are sequential, so each new booking also gets a random 16-hex `key` (in the row's JSON). Viewing or editing a booking needs `id` and `key` (URL, or the `id`/`key` cookies the page sets); without a matching key the page behaves as if there were no booking and a post starts a new one. The key is carried in emailed links (`moylgrove_booking_link`), the page's JS, and the PayPal REST calls (`create-order`, `capture-order` return 404 without it). Users who can manage bookings may open any booking by id. Bookings made before keys existed have none and open by id alone. The key is hidden in the table and CSV. Helpers: `moylgrove_new_booking_key`, `moylgrove_clean_key`, `moylgrove_booking_key_ok` in `moylgrove-forms-layout.php`. `[moylgrove-forms-dump]` and the table's `style="dump"` print raw rows (keys included) without a login check: use them only on private pages. The output is HTML-escaped, and the menu summary's `<br />` markers (which are for emails) are replaced by spaces.

### Tests

`tests/` holds Playwright tests that run against the local UniServer WordPress (Apache and MySQL running): `cd tests && npx playwright test` (about 16 minutes). See `tests/README.md`. They create `mg-test-*` pages and users and capture outgoing mail instead of sending it, and remove them afterwards. Don't run two runs at once: they share the same tables. When you change validation, the whitelist, access rules, emails or the form markup, run the suite and add a test for the new behaviour. `tests/lib.ts` `post()` adds the `moylgrove_booking` marker the way the real form does.

### Releases (manual)

There is no release script, and the GitHub releases (latest `v1.5`) are **not** used or kept in step: ignore them. A release is:
1. Bump the version in both places in `moylgrove-forms.php`: the `Version:` header and `$moylgrove_forms_version` (a change to the latter triggers the plugin's update routine on the next load). Numbering continues from the header (e.g. 1.2.8 → 1.2.9), not from the GitHub tag.
2. Commit with a message ending `; version x.y.z` (the history's convention). Pushing is separate; do it only when asked.
3. Build the zip from the commit and **overwrite** `C:\Users\alan\Downloads\moylgrove-forms.zip` (the previous release sits there):
   `git archive --format=zip --prefix=moylgrove-forms/ -o /c/Users/alan/Downloads/moylgrove-forms.zip HEAD -- . ':!CLAUDE.md' ':!tests'`
   The zip has a top-level `moylgrove-forms/` folder and leaves out `CLAUDE.md` and `tests/`. Check with `unzip -l`.
4. The user installs the zip by hand; no tag or GitHub release is created.

### Key Conventions

- Hardcoded venue name ("Moylgrove Old School Hall") and org contact ("info@moylgrove.wales") appear in multiple files.
- Form field data is stored as JSON in the `text` column; field names are the JSON keys.
- Payment amounts are accumulated in the database (partial/multiple payments supported); `paid` field tracks total paid vs. `totalPrice`.
- The `#fieldName` convention (e.g. `#turkey`) is used internally for per-seat menu choices stored in JSON.
- Full booking details (address, email, phone) are hidden from non-logged-in users; the `counters=1` view is public.
