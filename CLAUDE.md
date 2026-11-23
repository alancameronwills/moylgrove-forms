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
- `?paid=timestamp` — return from PayPal (prevents duplicate confirmation emails)
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
- **Saving**: a save that fails (DB error, over 60000 bytes) is logged, emailed to the organisers and the form shown again; never reported as a booking.
- **Output**: every value printed into HTML, attributes or JavaScript is escaped (`esc_attr`/`esc_html`, `JSON_HEX_*` for JSON in comments and scripts). The `id` is accepted only as a positive whole number.
- **Permissions**: delete, refund and the CSV need `edit_posts` (`moylgrove_can_manage_bookings()`); delete and refund also need nonces. Delete only works on a cancelled booking of the same event.
- **Emails**: with an email template, it goes to the customer's `email` field if that is valid (organisers on Bcc). With no valid customer address the organisers get it instead. With no template, a short thank-you listing the fields goes the same way.
- **CSV**: every cell is quoted; text starting `= + - @` gets a leading apostrophe; numbers are written `="0123"`; the booking key is omitted.

### Booking Keys

Ids are sequential, so each new booking also gets a random 16-hex `key` (in the row's JSON). Viewing or editing a booking needs `id` and `key` (URL, or the `id`/`key` cookies the page sets); without a matching key the page behaves as if there were no booking and a post starts a new one. The key is carried in emailed links (`moylgrove_booking_link`), the page's JS, and the PayPal REST calls (`create-order`, `capture-order` return 404 without it). Users who can manage bookings may open any booking by id. Bookings made before keys existed have none and open by id alone. The key is hidden in the table and CSV. Helpers: `moylgrove_new_booking_key`, `moylgrove_clean_key`, `moylgrove_booking_key_ok` in `moylgrove-forms-layout.php`. `[moylgrove-forms-dump]` and the table's `style="dump"` print raw rows (keys included) without a login check: use them only on private pages. The output is HTML-escaped, and the menu summary's `<br />` markers (which are for emails) are replaced by spaces.

### Tests

`tests/` holds Playwright tests that run against the local UniServer WordPress (Apache and MySQL running): `cd tests && npx playwright test` (about 13 minutes). See `tests/README.md`. They create `mg-test-*` pages and users and capture outgoing mail instead of sending it, and remove them afterwards. Don't run two runs at once: they share the same tables. When you change validation, the whitelist, access rules, emails or the form markup, run the suite and add a test for the new behaviour. `tests/lib.ts` `post()` adds the `moylgrove_booking` marker the way the real form does.

### Key Conventions

- Hardcoded venue name ("Moylgrove Old School Hall") and org contact ("info@moylgrove.wales") appear in multiple files.
- Form field data is stored as JSON in the `text` column; field names are the JSON keys.
- Payment amounts are accumulated in the database (partial/multiple payments supported); `paid` field tracks total paid vs. `totalPrice`.
- The `#fieldName` convention (e.g. `#turkey`) is used internally for per-seat menu choices stored in JSON.
- Full booking details (address, email, phone) are hidden from non-logged-in users; the `counters=1` view is public.
