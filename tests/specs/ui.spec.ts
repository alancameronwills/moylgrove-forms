import { test, expect } from '@playwright/test';
import { wp, bookings, pageUrl } from '../lib';

test.beforeEach(() => wp('clear'));

test.describe('number fields', () => {
  test('a lone 0 is replaced by the first digit typed', async ({ page }) => {
    await page.goto(pageUrl('paid'));
    const adults = page.locator('#adults');
    await expect(adults).toHaveValue('0');
    await adults.focus();
    await page.keyboard.press('2');
    await expect(adults).toHaveValue('2');
    await page.keyboard.press('5');
    await expect(adults).toHaveValue('25'); // only a lone 0 is replaced
  });

  test('a typed 0 is replaced too', async ({ page }) => {
    await page.goto(pageUrl('paid'));
    const adults = page.locator('#adults');
    await adults.fill('');
    await page.keyboard.press('0');
    await page.keyboard.press('4');
    await expect(adults).toHaveValue('4');
  });

  test('a minus sign cannot be typed where the minimum is 0', async ({ page }) => {
    for (const key of ['paid', 'free'] as const) {
      await page.goto(pageUrl(key));
      await page.locator('#adults').focus();
      await page.keyboard.press('-');
      await page.keyboard.press('3');
      await expect(page.locator('#adults')).toHaveValue('3');
    }
  });

  test('priced fields refuse "." "e" and "E"', async ({ page }) => {
    await page.goto(pageUrl('paid'));
    for (const id of ['#adults', '#kids']) {
      await page.locator(id).focus();
      for (const key of ['.', 'e', 'E']) await page.keyboard.press(key);
      await page.keyboard.press('7');
      await expect(page.locator(id)).toHaveValue('7');
    }
  });
});

test.describe('the form in the browser', () => {
  test('the browser stops an empty form being sent', async ({ page }) => {
    await page.goto(pageUrl('free'));
    await page.click('#submit');
    await expect(page.locator('#first:invalid')).toHaveCount(1);
    expect(bookings('free')).toHaveLength(0);
  });

  test('menu choices appear only for the seats booked, and the total follows the counts', async ({ page }) => {
    await page.goto(pageUrl('paid'));
    await expect(page.locator('.menu')).toBeHidden();
    await page.fill('#adults', '2');
    await page.press('#adults', 'Tab');
    await expect(page.locator('input[name="main#2"][value="turkey"]')).toBeVisible();
    await expect(page.locator('input[name="main#3"][value="turkey"]')).toBeHidden();
    await expect(page.locator('#totalPrice')).toHaveValue('30.00');
    await page.fill('#kids', '1');
    await page.press('#kids', 'Tab');
    await expect(page.locator('input[name="main#3"][value="turkey"]')).toBeVisible();
    await expect(page.locator('#totalPrice')).toHaveValue('40.00');
  });

  test('a returning customer sees their booking read-only until they ask to change it', async ({ page }) => {
    await page.goto(pageUrl('free'));
    await page.fill('#adults', '1');
    await page.fill('#first', 'Back');
    await page.fill('#email', 'back@example.com');
    await page.click('#submit');
    await expect(page.getByText('S-DONE')).toBeVisible();
    await page.goto(pageUrl('free'));
    await expect(page.locator('#first')).toHaveValue('Back');
    await expect(page.locator('#first')).toHaveJSProperty('readOnly', true);
    await page.click('#makeChangesButton');
    await expect(page.locator('#first')).toHaveJSProperty('readOnly', false);
  });
});
