import { resolve } from 'node:path';
import { depsUpToDate, writeDepsStamp } from './lib/deps-stamp.ts';

const [command, dir] = process.argv.slice(2);
if (!dir || (command !== 'check' && command !== 'write')) {
  console.error('usage: node scripts/deps-stamp.ts <check|write> <dir>');
  process.exit(2);
}
const target = resolve(dir);
if (command === 'check') process.exit(depsUpToDate(target) ? 0 : 1);
writeDepsStamp(target);
