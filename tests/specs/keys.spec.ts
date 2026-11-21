import { test, expect } from '@playwright/test';
import { wp, post, freeForm, paidForm, bookings, mails, pageUrl, keyOf, login } from '../lib';

test.beforeEach(() => wp('clear'));

const flag = (page: any, name: string) => page.evaluate((n: string) => (window as any)[n], name);

test('a new booking gets a random key, which is not shown in the table or the export', async ({ request, page }) => {
  await post(request, 'free', freeForm());
  await post(request, 'free', freeForm({ first: 'Other' }));
  const [a, b] = bookings('free');
  expect(a.data.key).toMatch(/^[0-9a-f]{16}$/);
  expect(b.data.key).toMatch(/^[0-9a-f]{16}$/);
  expect(a.data.key).not.toBe(b.data.key);

  await login(page, 'editor');
  await page.goto(pageUrl('table'));
  await page.goto(pageUrl('free'));
  const csv = await (await page.request.get('/wp-content/plugins/moylgrove-forms/csv.php?event=mg-test-free')).text();
  expect(csv).not.toContain(a.data.key);
  expect(csv).not.toContain('"key"');
});

test('the id alone does not open a booking, nor does a wrong key', async ({ request, page }) => {
  await post(request, 'free', freeForm({ first: 'Mine' }));
  const [b] = bookings('free');
  for (const url of [`?id=${b.id}`, `?id=${b.id}&key=0123456789abcdef`, `?id=${b.id}&key=${keyOf(b.id).toUpperCase()}`]) {
    await page.goto(pageUrl('free') + url);
    expect(await flag(page, 'id')).toBeUndefined();
    await expect(page.locator('#first')).toHaveValue('');
  }
});

test('the id with its key opens the booking', async ({ request, page }) => {
  await post(request, 'free', freeForm({ first: 'Mine' }));
  const [b] = bookings('free');
  await page.goto(`${pageUrl('free')}?id=${b.id}&key=${keyOf(b.id)}`);
  await expect(page.locator('#first')).toHaveValue('Mine');
});

test('a post that names a booking without its key starts a new booking and leaves that one alone', async ({ request }) => {
  await post(request, 'free', freeForm({ first: 'Victim' }));
  const [b] = bookings('free');
  const res = await request.post(pageUrl('free'), {
    data: new URLSearchParams({ ...freeForm({ first: 'Attacker' }), moylgrove_booking: 'mg-test-free' }).toString(),
    headers: { 'content-type': 'application/x-www-form-urlencoded', cookie: `id=${b.id}` },
  });
  expect(res.ok()).toBe(true);
  const all = bookings('free');
  expect(all).toHaveLength(2);
  expect(all[0].data.first).toBe('Victim');
  expect(all[1].data.first).toBe('Attacker');
});

test('editing keeps the key', async ({ request }) => {
  await post(request, 'free', freeForm());
  const [b] = bookings('free');
  const key = keyOf(b.id);
  await post(request, 'free', freeForm({ first: 'Changed' }), b.id);
  const [after] = bookings('free');
  expect(after.data.first).toBe('Changed');
  expect(after.data.key).toBe(key);
});

test('the emailed link carries the key', async ({ request }) => {
  await post(request, 'notemplate', freeForm());
  const [b] = bookings('notemplate');
  const mail = mails().find((m) => m.to === 'test@example.com')!;
  expect(mail.message).toContain(`?id=${b.id}&key=${b.data.key}`);
});

test('bookings made before keys existed still open by id alone', async ({ request, page }) => {
  await post(request, 'free', freeForm({ first: 'Old' }));
  const [b] = bookings('free');
  wp('delfield', b.id, 'key');
  await page.goto(`${pageUrl('free')}?id=${b.id}`);
  await expect(page.locator('#first')).toHaveValue('Old');
});

test('an editor can open any booking by its id', async ({ request, page }) => {
  await post(request, 'free', freeForm({ first: 'Theirs' }));
  const [b] = bookings('free');
  await login(page, 'editor');
  await page.goto(`${pageUrl('free')}?id=${b.id}`);
  await expect(page.locator('#first')).toHaveValue('Theirs');
});

test('PayPal endpoints refuse a booking id without its key', async ({ request }) => {
  await post(request, 'paid', paidForm());
  const [b] = bookings('paid');
  for (const route of ['create-order', 'capture-order']) {
    for (const key of [undefined, '0123456789abcdef']) {
      const res = await request.post(`/wp-json/moylgrove/v1/${route}`, { data: { id: b.id, key, orderID: 'X' } });
      expect(res.status()).toBe(404);
    }
  }
  expect(bookings('paid')[0].data.paid).toBe('0.00');
});
