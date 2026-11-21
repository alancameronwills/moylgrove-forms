import { test, expect } from '@playwright/test';
import { wp, post, paidForm, freeForm, bookings, mails, pageUrl } from '../lib';

test.beforeEach(() => wp('clear'));

test.describe('making a booking', () => {
  test('through the browser: price is worked out and the booking awaits payment', async ({ page }) => {
    await page.goto(pageUrl('paid'));
    await page.fill('#adults', '2');
    await page.press('#adults', 'Tab');
    await page.fill('#kids', '1');
    await page.press('#kids', 'Tab');
    await expect(page.locator('#totalPrice')).toHaveValue('40.00');
    for (const seat of [1, 2, 3]) {
      await page.check(`input[name="main#${seat}"][value="turkey"]`);
    }
    await page.fill('#first', 'Gwen');
    await page.fill('#last', 'Jones');
    await page.fill('#email', 'gwen@example.com');
    await page.click('#submit');
    await expect(page.getByText('S-PAY')).toBeVisible();

    const [b] = bookings('paid');
    expect(b.data).toMatchObject({
      adults: 2, kids: 1, first: 'Gwen', totalPrice: '40.00', paid: '0.00',
      calculation: '2 x adults @ 15.00 + 1 x kids @ 10.00',
    });
  });

  test('a free event is confirmed at once and the customer is emailed', async ({ request }) => {
    const { text } = await post(request, 'free', freeForm({ email: 'who@example.com' }));
    expect(text).toContain('S-DONE');
    const [b] = bookings('free');
    expect(b.data).toMatchObject({ adults: 1, kids: 0, totalPrice: '0.00', paid: '0.00' });
    expect(mails().filter((m) => m.to === 'who@example.com')).toHaveLength(1);
  });

  test('all seats zero is a cancellation', async ({ request }) => {
    const { text } = await post(request, 'free', freeForm({ adults: '0', kids: '0' }));
    expect(text).toContain('S-CANCEL');
  });

  test('numbers are tidied and an optional field may be left out', async ({ request }) => {
    await post(request, 'paid', paidForm({ adults: ' 02 ', kids: undefined, 'main#3': undefined }));
    const [b] = bookings('paid');
    expect(b.data.adults).toBe(2);
    expect(b.data.totalPrice).toBe('30.00');
  });
});

test.describe('the server decides the money', () => {
  test('a forged price and payment are ignored', async ({ request }) => {
    await post(request, 'paid', paidForm({ totalPrice: '0.01', paid: '999', calculation: 'free!' }));
    const [b] = bookings('paid');
    expect(b.data.totalPrice).toBe('40.00');
    expect(b.data.paid).toBe('0.00');
    expect(b.data.calculation).toBe('2 x adults @ 15.00 + 1 x kids @ 10.00');
  });

  test('editing keeps what has been paid and reprices from the new seats', async ({ request }) => {
    await post(request, 'paid', paidForm());
    const [first] = bookings('paid');
    wp('setpaid', first.id, 10);

    await post(request, 'paid', paidForm({ adults: '1', kids: '0', 'main#2': undefined, 'main#3': undefined, paid: '0', totalPrice: '0' }), first.id);
    const all = bookings('paid');
    expect(all).toHaveLength(1);
    expect(all[0].id).toBe(first.id);
    expect(all[0].data).toMatchObject({ adults: 1, totalPrice: '15.00', paid: '10.00' });
  });

  test('an overpayment (seats reduced after paying) is left as paid', async ({ request }) => {
    await post(request, 'paid', paidForm());
    const [first] = bookings('paid');
    wp('setpaid', first.id, 40);
    await post(request, 'paid', paidForm({ adults: '1', kids: '0', 'main#2': undefined, 'main#3': undefined }), first.id);
    expect(bookings('paid')[0].data).toMatchObject({ totalPrice: '15.00', paid: '40.00' });
  });
});

test.describe('whose booking is it', () => {
  test('an id from another event starts a new booking and leaves the other alone', async ({ request }) => {
    await post(request, 'free', freeForm({ first: 'Original' }));
    const [other] = bookings('free');

    await post(request, 'paid', paidForm(), other.id);
    expect(bookings('paid')).toHaveLength(1);
    expect(bookings('paid')[0].id).not.toBe(other.id);
    expect(bookings('free')[0].data.first).toBe('Original');
  });

  test('an id that does not exist is not reused', async ({ request }) => {
    await post(request, 'free', freeForm(), 987654);
    const all = bookings('free');
    expect(all).toHaveLength(1);
    expect(all[0].id).not.toBe(987654);
  });

  test('the owner can change their own booking', async ({ request }) => {
    await post(request, 'free', freeForm({ first: 'Before' }));
    const [b] = bookings('free');
    await post(request, 'free', freeForm({ first: 'After' }), b.id);
    const all = bookings('free');
    expect(all).toHaveLength(1);
    expect(all[0].data.first).toBe('After');
  });
});

test.describe('what gets stored', () => {
  test("only the form's own fields are kept", async ({ request }) => {
    const body = new URLSearchParams(paidForm({ evil: 'x', 'main#9': 'turkey', home: 'h', id: '1' })).toString()
      + '&junk[]=a&junk[]=b';
    await post(request, 'paid', body);
    const keys = Object.keys(bookings('paid')[0].data).sort();
    expect(keys).toEqual(
      ['adults', 'calculation', 'email', 'first', 'key', 'kids', 'last', 'main#1', 'main#2', 'main#3', 'paid', 'phone', 'totalPrice'].sort(),
    );
  });

  test('a menu count must be a small whole number', async ({ request }) => {
    await post(request, 'paid', paidForm({ '#turkey': '"><img src=x onerror=window.__menu=1>', '#veggie': '1' }));
    const data = bookings('paid')[0].data;
    expect(data['#turkey']).toBeUndefined();
    expect(data['#veggie']).toBe('1');
  });

  test('a confirmation is never sent to an address the form did not ask for', async ({ request }) => {
    const { text } = await post(request, 'noemail', { adults: '1', first: 'Sam', email: 'victim@example.com' });
    expect(text).toContain('S-DONE');
    expect(bookings('noemail')[0].data.email).toBeUndefined();
    expect(mails().filter((m) => String(m.to).includes('victim'))).toHaveLength(0);
    // With no customer address the organisers get the email instead
    const toOrganisers = mails().filter((m) => m.to === 'info@moylgrove.wales');
    expect(toOrganisers).toHaveLength(1);
    expect(toOrganisers[0].message).toContain('Thanks Sam');
    expect(toOrganisers[0].message).toContain('no valid customer email');
  });
});

test('a form with no email template thanks the customer, with the organisers on Bcc', async ({ request }) => {
  await post(request, 'notemplate', freeForm({ first: 'Summary' }));
  expect(bookings('notemplate')).toHaveLength(1);
  const toCustomer = mails().filter((m) => m.to === 'test@example.com');
  expect(toCustomer).toHaveLength(1);
  expect(toCustomer[0].message).toContain('Thank you for booking');
  expect(toCustomer[0].message).toContain('first : Summary');
  expect(JSON.stringify(toCustomer[0].headers)).toContain('info@moylgrove.wales');
});

test('the browser refuses an email address without a dotted domain', async ({ page }) => {
  await page.goto(pageUrl('free'));
  await page.fill('#adults', '1');
  await page.fill('#first', 'Em');
  await page.fill('#email', 'me@host');
  await page.click('#submit');
  await expect(page.locator('#email:invalid')).toHaveCount(1);
  expect(bookings('free')).toHaveLength(0);
});
