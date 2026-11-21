import { test, expect, APIRequestContext } from '@playwright/test';
import { wp, post, freeForm, bookings, pageUrl, login } from '../lib';

test.beforeEach(() => wp('clear'));

const csv = (event = 'mg-test-free') => `/wp-content/plugins/moylgrove-forms/csv.php${event ? `?event=${event}` : ''}`;

async function cancelledBooking(request: APIRequestContext) {
  await post(request, 'free', freeForm({ adults: '0', kids: '0', first: 'Gone' }));
  return bookings('free')[0].id;
}

test.describe('the bookings table', () => {
  test('is empty for the public, and counters are only sums', async ({ request, page }) => {
    await post(request, 'free', freeForm({ first: 'Private' }));
    await page.goto(pageUrl('table'));
    await expect(page.locator('.bookings-table')).toHaveCount(0);
    await page.goto(`${pageUrl('table')}?counters=1`);
    await expect(page.locator('.bookings-table')).toBeVisible();
    await expect(page.locator('.bookings-table')).not.toContainText('Private');
    await expect(page.locator('.bookings-table')).not.toContainText('test@example.com');
  });

  test('a visitor cannot delete a booking', async ({ request, page }) => {
    const id = await cancelledBooking(request);
    await page.goto(`${pageUrl('table')}?delete=${id}`);
    expect(bookings('free')).toHaveLength(1);
  });

  test('a visitor cannot record a refund', async ({ request, page }) => {
    await post(request, 'free', freeForm());
    const [b] = bookings('free');
    wp('setfield', b.id, 'totalPrice', '5.00');
    wp('setpaid', b.id, 20);
    await page.goto(`${pageUrl('table')}?refunded=${b.id}`);
    expect(bookings('free')[0].data.paid).toBe('20.00');
  });

  test('a subscriber gets no buttons and no effect', async ({ request, page }) => {
    const id = await cancelledBooking(request);
    await login(page, 'subscriber');
    await page.goto(pageUrl('table'));
    await expect(page.locator('a[href*="delete="]')).toHaveCount(0);
    await page.goto(`${pageUrl('table')}?delete=${id}`);
    expect(bookings('free')).toHaveLength(1);
  });

  test('an editor deletes a cancelled booking with the X button', async ({ request, page }) => {
    await cancelledBooking(request);
    await post(request, 'free', freeForm({ first: 'Live' }));
    await login(page, 'editor');
    await page.goto(pageUrl('table'));
    await expect(page.locator('a[href*="delete="]')).toHaveCount(1); // only the cancelled one
    await page.click('a[href*="delete="]');
    await expect(page.locator('a[href*="delete="]')).toHaveCount(0);
    const left = bookings('free');
    expect(left).toHaveLength(1);
    expect(left[0].data.first).toBe('Live');
  });

  test('delete needs the nonce its button carries', async ({ request, page }) => {
    const id = await cancelledBooking(request);
    await login(page, 'editor');
    const res = await page.request.get(`${pageUrl('table')}?delete=${id}`);
    expect(await res.text()).toContain('expired');
    expect(bookings('free')).toHaveLength(1);
    const bad = await page.request.get(`${pageUrl('table')}?delete=${id}&_wpnonce=0123456789`);
    expect(await bad.text()).toContain('expired');
    expect(bookings('free')).toHaveLength(1);
  });

  test('an editor records a refund through the dialog', async ({ request, page }) => {
    await post(request, 'free', freeForm());
    const [b] = bookings('free');
    wp('setfield', b.id, 'totalPrice', '5.00');
    wp('setpaid', b.id, 20);
    await login(page, 'editor');
    await page.goto(pageUrl('table'));
    await page.click('.rowButton:has-text("Refund")');
    await page.click('button:has-text("Yes, has been refunded")');
    await expect.poll(() => bookings('free')[0].data.paid).toBe('5.00');
  });
});

test.describe('the CSV export', () => {
  test('is refused to the public', async ({ request }) => {
    await post(request, 'free', freeForm());
    const res = await request.get(csv());
    expect(res.status()).toBe(403);
    expect(await res.text()).not.toContain('test@example.com');
  });

  test('is refused to a subscriber', async ({ page, request }) => {
    await post(request, 'free', freeForm());
    await login(page, 'subscriber');
    expect((await page.request.get(csv())).status()).toBe(403);
  });

  test('is given to an editor', async ({ page, request }) => {
    await post(request, 'free', freeForm({ first: 'Csv' }));
    await login(page, 'editor');
    const res = await page.request.get(csv());
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toContain('text/csv');
    const text = await res.text();
    expect(text).toContain('test@example.com');
    expect(text).toContain('Csv');
  });

  test('quotes, newlines, commas and formulas in what people typed stay inert', async ({ page, request }) => {
    await post(request, 'free', freeForm({ first: 'Plain' }));
    const [b] = bookings('free');
    wp('setfield', b.id, 'last', 'He said "hi", then\nleft');
    wp('setfield', b.id, 'phone', '=HYPERLINK("http://evil.example","x")');
    wp('setfield', b.id, 'note', '@SUM(1+1)');
    wp('setfield', b.id, 'zip', '0123');
    await login(page, 'editor');
    const text = await (await page.request.get(csv())).text();
    expect(text).toContain('"He said ""hi"", then\nleft"');
    expect(text).toContain(`"'=HYPERLINK(""http://evil.example"",""x"")"`);
    expect(text).toContain(`"'@SUM(1+1)"`);
    expect(text).toContain('"=""0123"""'); // numbers stay text, keeping leading zeros
  });

  test('needs an event', async ({ page }) => {
    await login(page, 'editor');
    expect((await page.request.get(csv(''))).status()).toBe(400);
  });
});
