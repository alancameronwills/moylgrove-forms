import { execFileSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';
import { expect, APIRequestContext, Page } from '@playwright/test';

const PHP = process.env.MG_PHP || 'C:/Users/alan/UniServerZ/core/php74/php.exe';
const STATE = path.join(__dirname, '.state.json');

const MYSQL = process.env.MG_MYSQL || 'C:/Users/alan/UniServerZ/core/mysql/bin/mysql.exe';
const DB = { user: process.env.MG_DB_USER || 'root', pass: process.env.MG_DB_PASS || 'root', name: process.env.MG_DB_NAME || 'wordpress' };
const PREFIX = process.env.MG_DB_PREFIX || 'wp_';
const MAIL_LOG = path.join(__dirname, '.mail-log.jsonl');

/** Run SQL with the mysql command-line client; returns the tab-separated rows (no escaping, no headers). */
function sql(query: string): string {
  return execFileSync(MYSQL, ['-u' + DB.user, '-p' + DB.pass, DB.name, '-N', '-B', '-r', '-e', query], {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'ignore'],
  });
}

const table = () => `${PREFIX}moylgrove_forms`;

function updateText(id: number, change: (data: Record<string, any>) => void) {
  const row = sql(`SELECT text FROM ${table()} WHERE id = ${Number(id)}`).replace(/\r?\n$/, '');
  const data = JSON.parse(row);
  change(data);
  sql(`UPDATE ${table()} SET text = 0x${Buffer.from(JSON.stringify(data), 'utf8').toString('hex')} WHERE id = ${Number(id)}`);
}

/**
 * Test-support commands. Most go straight to MySQL because starting WordPress for each is slow;
 * only setup/teardown use helper.php (see there).
 *   clear | bookings <event> | setpaid <id> <amount> | setfield <id> <key> <value> | mails | setup | teardown
 */
export function wp(cmd: string, ...args: (string | number)[]): any {
  switch (cmd) {
    case 'clear':
      sql(`DELETE FROM ${table()} WHERE name LIKE 'mg-test-%';
           DELETE FROM ${PREFIX}options WHERE option_name LIKE '\_transient\_moylgrove\_%' OR option_name LIKE '\_transient\_timeout\_moylgrove\_%' OR option_name LIKE 'seatCount\_mg-test-%'`);
      fs.rmSync(MAIL_LOG, { force: true });
      return true;
    case 'bookings':
      return sql(`SELECT id, text FROM ${table()} WHERE name = '${String(args[0]).replace(/[^a-z0-9-]/gi, '')}' ORDER BY id`)
        .split('\n')
        .filter(Boolean)
        .map((line) => {
          const tab = line.indexOf('\t');
          const raw = line.slice(tab + 1);
          let data = null;
          try { data = JSON.parse(raw); } catch { /* leave null: the row is unreadable */ }
          return { id: Number(line.slice(0, tab)), raw, data };
        });
    case 'setpaid':
      updateText(Number(args[0]), (d) => { d.paid = Number(args[1]).toFixed(2); });
      return true;
    case 'setfield':
      updateText(Number(args[0]), (d) => { d[String(args[1])] = String(args[2]); });
      return true;
    case 'mails':
      return fs.existsSync(MAIL_LOG)
        ? fs.readFileSync(MAIL_LOG, 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l))
        : [];
  }
  const out = execFileSync(PHP, [path.join(__dirname, 'helper.php'), cmd, ...args.map(String)], { encoding: 'utf8' });
  const i = out.lastIndexOf('@@JSON@@');
  if (i < 0) throw new Error(`helper.php ${cmd}: no result in output:\n${out}`);
  return JSON.parse(out.slice(i + 8));
}

export type Role = 'editor' | 'subscriber';
export function state(): { base: string; pages: Record<string, string>; users: Record<Role, { login: string; pass: string }> } {
  return JSON.parse(fs.readFileSync(STATE, 'utf8'));
}

export type PageKey = 'paid' | 'free' | 'noemail' | 'table';
export const pageUrl = (key: PageKey) => state().pages[key];
export const eventName = (key: PageKey) => `mg-test-${key}`;
export const bookings = (key: PageKey) => wp('bookings', eventName(key)) as { id: number; raw: string; data: Record<string, any> }[];
export const mails = () => wp('mails') as { to: string; subject: string; message: string; headers: any }[];

/** Form values that pass validation; override or remove (undefined) fields as needed. */
export function paidForm(over: Record<string, string | undefined> = {}) {
  return merge({
    adults: '2', kids: '1', first: 'Test', last: 'User', email: 'test@example.com', phone: '',
    'main#1': 'turkey', 'main#2': 'veggie', 'main#3': 'turkey',
  }, over);
}
export function freeForm(over: Record<string, string | undefined> = {}) {
  return merge({ adults: '1', kids: '0', first: 'Test', last: 'User', email: 'test@example.com' }, over);
}
function merge(base: Record<string, string>, over: Record<string, string | undefined>) {
  const r: Record<string, string> = { ...base };
  for (const [k, v] of Object.entries(over)) {
    if (v === undefined) delete r[k];
    else r[k] = v;
  }
  return r;
}

/**
 * POST a form straight to a page, as a browser without working JavaScript would.
 * `fields` may be an already-encoded body for oddities such as invalid UTF-8 or arrays.
 * `cookieId` sends the `id` cookie the page sets for a returning customer.
 */
export async function post(request: APIRequestContext, key: PageKey, fields: Record<string, string> | string, cookieId?: number) {
  const data = typeof fields === 'string' ? fields : new URLSearchParams(fields).toString();
  const headers: Record<string, string> = { 'content-type': 'application/x-www-form-urlencoded' };
  if (cookieId) headers.cookie = `id=${cookieId}`;
  const res = await request.post(pageUrl(key), { data, headers });
  const text = await res.text();
  return { res, text };
}

export const REJECTED = 'we had a problem saving your booking';

/** The page was rejected: shown again with a notice, nothing stored, admin told. */
export async function expectRejected(key: PageKey, text: string) {
  expect(text).toContain(REJECTED);
  expect(bookings(key)).toHaveLength(0);
  const sent = mails();
  expect(sent.some((m) => m.to === 'info@moylgrove.wales' && m.subject.startsWith('Booking problem'))).toBe(true);
}

export async function login(page: Page, role: Role) {
  const u = state().users[role];
  await page.goto('/wp-login.php');
  await page.fill('#user_login', u.login);
  await page.fill('#user_pass', u.pass);
  await page.click('#wp-submit');
  await page.waitForURL(/wp-admin|\/$/);
}
