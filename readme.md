# Moylgrove Forms

Provides a form for event bookings, with link to PayPal.

## Files

| |  |
|---|---|
| moylgrove-forms-admin.php | Provides a how-to page in the WP admin menu |
| moylgrove-forms-custom-form.php | Interprets \[moylgrove-form ...] \<form layout\> ... |
| csv.php | Downloads the bookings as CSV (logged-in editors only) |
| moylgrove-forms-dump.php | Debug shortcode that prints raw rows: use only on private pages |
| moylgrove-forms-layout.php |  Elaborates fields in the form |
| moylgrove-forms-standard-form.php | Interprets \[moylgrove-standard-form ...] |
| moylgrove-forms-table.php | Creates a table of bookings |
| moylgrove-forms.php | Installation |

## Bookings and security

- Each booking has a secret key as well as an id. Emailed links look like `?id=12&key=0123456789abcdef`; without the key a booking can't be opened or changed.
- The server re-checks every submission against the form, works out the price itself, and keeps only the fields the form declares.
- Only people who can edit posts can delete or refund bookings, or download the CSV.
- If the form has no email template, the customer gets a short thank-you listing their details. If there is no valid customer email, the organisers get the email instead.

## Tests

Browser tests live in `tests/`; see `tests/README.md`.
