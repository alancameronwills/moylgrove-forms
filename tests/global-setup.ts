import * as fs from 'fs';
import * as path from 'path';
import { FullConfig } from '@playwright/test';
import { wp } from './lib';

export default async function globalSetup(config: FullConfig) {
  const base = config.projects[0].use.baseURL!;
  try {
    const r = await fetch(`${base}/wp-json/`);
    if (!r.ok) throw new Error(`HTTP ${r.status}`);
  } catch (e) {
    throw new Error(`WordPress isn't answering at ${base} (${e}). Start Apache and MySQL in UniServer first.`);
  }
  const state = wp('setup');
  fs.writeFileSync(path.join(__dirname, '.state.json'), JSON.stringify(state));
}
