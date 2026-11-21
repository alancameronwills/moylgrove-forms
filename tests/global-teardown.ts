import { wp } from './lib';

export default async function globalTeardown() {
  wp('teardown');
}
