import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { scanComments } from './lib/comments.ts';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const dirs = [
  'apps/web/app',
  'apps/web/config',
  'apps/web/database',
  'apps/web/routes',
  'apps/web/bootstrap',
  'apps/web/tests',
  'apps/web/resources/js',
  'apps/worker/src',
  'apps/worker/tests',
  'e2e/specs',
  'e2e/support',
  'scripts/lib',
];

const violations = scanComments(root, dirs);

for (const violation of violations) {
  process.stdout.write(`${violation.file}:${violation.line}  ${violation.text}\n`);
}

if (violations.length > 0) {
  process.stderr.write(`\n${violations.length} prose comment line(s) found; source code must not contain comments.\n`);
  process.exit(1);
}

process.stdout.write('no prose comments found\n');
