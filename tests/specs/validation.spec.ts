import { test, expect } from '@playwright/test';
import { wp, post, paidForm, freeForm, expectRejected, bookings, mails, pageUrl, REJECTED, PageKey } from '../lib';

test.beforeEach(() => wp('clear'));

type Case = [string, PageKey, Record<string, string> | string];
const long = 'x'.repeat(501);

const rejected: Case[] = [
  ['required text missing', 'paid', paidForm({ first: undefined })],
  ['required text blank', 'paid', paidForm({ first: '   ' })],
  ['required email missing', 'paid', paidForm({ email: undefined })],
  ['not an email', 'paid', paidForm({ email: 'nope' })],
  ['email with a header injected', 'paid', paidForm({ email: 'a@example.com\nBcc: x@example.com' })],
  ['required number missing', 'paid', paidForm({ adults: undefined })],
  ['negative number', 'paid', paidForm({ adults: '-1' })],
  ['fraction', 'paid', paidForm({ adults: '2.5' })],
  ['exponent', 'paid', paidForm({ adults: '1e1' })],
  ['above the maximum', 'paid', paidForm({ adults: '11' })],
  ['not a number', 'paid', paidForm({ adults: 'abc' })],
  ['negative optional number', 'free', freeForm({ kids: '-3' })],
  ['text too long', 'paid', paidForm({ first: long })],
  ['email too long', 'free', freeForm({ email: 'a'.repeat(250) + '@example.com' })],
  ['a seat without its required menu choice', 'paid', paidForm({ 'main#2': undefined })],
  ['a menu choice that is not on the menu', 'paid', paidForm({ 'main#1': 'steak' })],
  ['a bad menu choice for a seat beyond the booking', 'paid', paidForm({ 'main#5': 'steak' })],
  ['invalid UTF-8', 'paid', new URLSearchParams(paidForm({ first: 'X' })).toString().replace('first=X', 'first=%FF%FE')],
  ['an array where text is expected', 'paid', new URLSearchParams(paidForm({ first: undefined })).toString() + '&first[]=a'],
];

for (const [name, key, fields] of rejected) {
  test(`rejects: ${name}`, async ({ request }) => {
    const { text } = await post(request, key, fields);
    await expectRejected(key, text);
  });
}

test('a rejected form is shown again with what was typed, escaped', async ({ request }) => {
  const { text } = await post(request, 'paid', paidForm({ first: '"><b id=pwn>x', last: undefined }));
  expect(text).toContain('&quot;&gt;&lt;b id=pwn&gt;x');
  expect(text).not.toContain('<b id=pwn>');
});

test('the admin email says what was wrong, and is sent once for a double-click', async ({ request }) => {
  const fields = paidForm({ email: 'nope' });
  await post(request, 'paid', fields);
  await post(request, 'paid', fields);
  const sent = mails().filter((m) => m.subject.startsWith('Booking problem'));
  expect(sent).toHaveLength(1);
  expect(sent[0].message).toContain('email');
});

test('invalid UTF-8 rejections are not all treated as the same submission', async ({ request }) => {
  const base = new URLSearchParams(paidForm({ first: 'X' })).toString();
  await post(request, 'paid', base.replace('first=X', 'first=%FF'));
  await post(request, 'paid', base.replace('first=X', 'first=%FE'));
  expect(mails().filter((m) => m.subject.startsWith('Booking problem'))).toHaveLength(2);
});

test('a valid booking at the limits is accepted', async ({ request }) => {
  const { text } = await post(request, 'free', freeForm({ adults: '10', first: 'x'.repeat(500) }));
  expect(text).not.toContain('we had a problem');
  expect(bookings('free')).toHaveLength(1);
});

test.describe('which posts count as bookings', () => {
  test('a post without the form marker is ignored: nothing stored, no emails', async ({ request }) => {
    const { text } = await post(request, 'free', { comment: 'Nice page', author: 'Bob' }, undefined, false);
    expect(text).not.toContain(REJECTED);
    expect(bookings('free')).toHaveLength(0);
    expect(mails()).toHaveLength(0);
    // even a complete booking is not one without the marker
    await post(request, 'free', freeForm(), undefined, false);
    expect(bookings('free')).toHaveLength(0);
    expect(mails()).toHaveLength(0);
  });

  test('a post marked for another event is ignored', async ({ request }) => {
    await post(request, 'free', freeForm(), undefined, 'mg-test-paid');
    expect(bookings('free')).toHaveLength(0);
    expect(mails()).toHaveLength(0);
  });

  test('the form carries its marker', async ({ page }) => {
    await page.goto(pageUrl('free'));
    await expect(page.locator('input[name="moylgrove_booking"]')).toHaveValue('mg-test-free');
  });

  test('however varied the junk, the admin gets at most 10 problem emails an hour', async ({ request }) => {
    test.setTimeout(180000);
    for (let i = 0; i < 12; i++) {
      await post(request, 'free', freeForm({ adults: '-' + (i + 1) }));
    }
    const problems = mails().filter((m) => m.subject.startsWith('Booking problem'));
    expect(problems).toHaveLength(10);
    expect(bookings('free')).toHaveLength(0);
  });
});
