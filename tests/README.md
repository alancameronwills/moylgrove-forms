# Tests

Playwright tests that run against the WordPress on the local UniServer, with the plugin active.
Apache and MySQL must be running.

    cd tests
    npm install        # first time only (browsers are shared with other Playwright projects)
    npx playwright test
    npx playwright test specs/validation.spec.ts
    npx playwright test --ui

How it works:

- `global-setup.ts` runs `helper.php setup`, which creates pages `mg-test-paid`, `mg-test-free`,
  `mg-test-noemail`, `mg-test-notemplate` and `mg-test-table` from `fixtures/`, two users (`mgtest_editor`, `mgtest_subscriber`
  with random passwords), and a temporary must-use plugin that captures outgoing mail in `.mail-log.jsonl`
  instead of sending it. `global-teardown.ts` removes all of it, including any `mg-test-*` bookings.
- Tests read and change bookings with the `mysql` command-line client (`lib.ts`), because starting
  WordPress for each check is slow. Defaults: root/root, database `wordpress`, prefix `wp_`;
  override with `MG_DB_USER`, `MG_DB_PASS`, `MG_DB_NAME`, `MG_DB_PREFIX`, `MG_MYSQL`, and `MG_PHP` for the PHP
  used by `helper.php`. `MG_BASE_URL` sets the site address.
- Most tests POST forms directly (as a browser whose JavaScript failed would); `ui.spec.ts` and a few others
  drive the real page.
- The dev site takes ~3 seconds per page, so the whole suite takes about 13 minutes. Don't start a second run while one is going: both clear the same tables.

| Spec | Covers |
|------|--------|
| `submission.spec.ts` | booking flow, server-computed price, paid kept on edit, id ownership, stored fields, email recipient |
| `validation.spec.ts` | every rule the server validator enforces, rejection notice and admin email, which posts count as bookings, email cap |
| `escaping.spec.ts` | stored/reflected XSS: form values, menu data, `?id=`, cookie, bookings table |
| `access.spec.ts` | who may delete, refund and export CSV; nonces |
| `keys.spec.ts` | booking keys: id alone refused, key in links, PayPal endpoints, old bookings, editors |
| `ui.spec.ts` | number-key rules, replacing a lone 0, menu and total in the browser |
