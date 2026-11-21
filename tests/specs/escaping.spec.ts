import { test, expect, Page, BrowserContext } from '@playwright/test';
import { wp, post, freeForm, paidForm, bookings, pageUrl, login, keyOf } from '../lib';

test.beforeEach(() => wp('clear'));

const flag = (page: Page, name: string) => page.evaluate((n) => (window as any)[n], name);

async function visitAs(page: Page, context: BrowserContext, key: 'free' | 'paid', id: number) {
  await context.addCookies([
    { name: 'id', value: String(id), url: pageUrl(key) },
    { name: 'key', value: keyOf(id), url: pageUrl(key) },
  ]);
  await page.goto(pageUrl(key));
}

test('text containing quotes and tags comes back as text, not markup', async ({ request, page, context }) => {
  const first = `'><img src=x onerror=window.__a=1><script>window.__b=1</script>`;
  const last = `" autofocus onfocus="window.__c=1`;
  await post(request, 'free', freeForm({ first, last }));
  const [b] = bookings('free');
  expect(b.data.first).toBe(first); // stored as typed, without WordPress's added backslashes

  await visitAs(page, context, 'free', b.id);
  await expect(page.locator('#first')).toHaveValue(first);
  await expect(page.locator('#last')).toHaveValue(last);
  for (const f of ['__a', '__b', '__c']) {
    expect(await flag(page, f)).toBeUndefined();
  }
});

test('a stored menu value cannot break out of the page', async ({ request, page, context }) => {
  await post(request, 'paid', paidForm());
  const [b] = bookings('paid');
  // The server now refuses such values, but older rows may hold them:
  wp('setfield', b.id, '#turkey', '"><img src=x onerror=window.__menu=1>');
  wp('setfield', b.id, 'main#1', '--><script>window.__comment=1</script>');

  await visitAs(page, context, 'paid', b.id);
  await page.waitForTimeout(500);
  for (const f of ['__menu', '__comment']) {
    expect(await flag(page, f)).toBeUndefined();
  }
});

test('a hostile ?id= is ignored: no script injected, and no booking shown', async ({ request, page }) => {
  await post(request, 'free', freeForm());
  const [b] = bookings('free');
  const res = await page.goto(`${pageUrl('free')}?id=${b.id}%22);window.__rx=1;//`);
  expect(await res!.text()).not.toContain('window.__rx=1');
  expect(await flag(page, '__rx')).toBeUndefined();
  expect(await flag(page, 'id')).toBeUndefined(); // treated as no id: a fresh form
  await expect(page.locator('#first')).toHaveValue('');
});

test('a hostile id cookie is ignored', async ({ request }) => {
  await post(request, 'free', freeForm());
  const [b] = bookings('free');
  const res = await request.get(pageUrl('free'), { headers: { cookie: `id=${b.id}");window.__rc=1;//; key=${keyOf(b.id)}` } });
  expect(await res.text()).not.toContain('window.__rc=1');
});

test('a genuine ?id= shows that booking', async ({ request, page }) => {
  await post(request, 'free', freeForm({ first: 'Mine' }));
  const [b] = bookings('free');
  await page.goto(`${pageUrl('free')}?id=${b.id}&key=${keyOf(b.id)}`);
  expect(await flag(page, 'id')).toBe(String(b.id));
  await expect(page.locator('#first')).toHaveValue('Mine');
});

test('the bookings table shows what people typed as text', async ({ request, page }) => {
  const first = '<img src=x onerror=window.__tx=1>';
  await post(request, 'free', freeForm({ first }));
  await login(page, 'editor');
  await page.goto(pageUrl('table'));
  await expect(page.locator('.bookings-table')).toContainText(first);
  expect(await flag(page, '__tx')).toBeUndefined();
});
