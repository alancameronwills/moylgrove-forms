import { test, expect } from '@playwright/test';
import { wp } from '../lib';

// These exercise the checks on a PayPal order without calling PayPal: the helper runs the
// plugin's own functions on an order given as JSON.
const check = (order: object, id: number) => wp('orderchecks', JSON.stringify(order), id) as { for: boolean; amount: number };

const capture = (value: string, over: object = {}) => ({ status: 'COMPLETED', amount: { currency_code: 'GBP', value }, ...over });
const unit = (custom_id: string, captures: object[] = []) => ({ custom_id, payments: { captures } });

test.describe('PayPal orders', () => {
  test('a capture reply stamps the id on the capture, not the unit, and still counts', () => {
    const order = { purchase_units: [{ payments: { captures: [capture('10.00', { custom_id: '957' }), capture('5.00', { custom_id: '958' })] } }] };
    expect(check(order, 957).amount).toBe(10);
  });

  test("an order stamped with this booking's id belongs to it", () => {
    expect(check({ purchase_units: [unit('42')] }, 42).for).toBe(true);
  });

  test("an order for another booking, or with no id, or with no units, does not", () => {
    expect(check({ purchase_units: [unit('43')] }, 42).for).toBe(false);
    expect(check({ purchase_units: [{}] }, 42).for).toBe(false);
    expect(check({ purchase_units: [] }, 42).for).toBe(false);
    expect(check({}, 42).for).toBe(false);
    expect(check({ purchase_units: [unit('42'), unit('43')] }, 42).for).toBe(false);
  });

  test('a prefix of the id is not the id', () => {
    expect(check({ purchase_units: [unit('142')] }, 42).for).toBe(false);
    expect(check({ purchase_units: [unit('4')] }, 42).for).toBe(false);
  });

  test('only completed GBP captures for this booking are credited', () => {
    const order = {
      purchase_units: [
        unit('42', [capture('10.50'), capture('4.50'), capture('99', { status: 'PENDING' }), capture('99', { amount: { currency_code: 'USD', value: '99' } })]),
        unit('43', [capture('200')]),
      ],
    };
    expect(check(order, 42).amount).toBe(15);
    expect(check(order, 44).amount).toBe(0);
  });
});
