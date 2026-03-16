# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a WordPress plugin for managing event seat bookings with PayPal payment processing. It is purely procedural PHP — no OOP, no build tools, no automated tests.

**Dependency**: Requires the `wp-paypal` WordPress plugin to be installed and configured with PayPal credentials.

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
id (MEDIUMINT AUTO_INCREMENT), time (DATETIME), name (TEXT - WP page slug), text (LONGTEXT - JSON fields)
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

- `?id=123` — load a specific booking for editing
- `?paid=timestamp` — return from PayPal (prevents duplicate confirmation emails)
- `?counters=1` — show anonymized seat counts without login
- `?delete=id` — delete a cancelled booking

### Key Conventions

- Hardcoded venue name ("Moylgrove Old School Hall") and org contact ("info@moylgrove.wales") appear in multiple files.
- Form field data is stored as JSON in the `text` column; field names are the JSON keys.
- Payment amounts are accumulated in the database (partial/multiple payments supported); `paid` field tracks total paid vs. `totalPrice`.
- The `#fieldName` convention (e.g. `#turkey`) is used internally for per-seat menu choices stored in JSON.
- Full booking details (address, email, phone) are hidden from non-logged-in users; the `counters=1` view is public.
