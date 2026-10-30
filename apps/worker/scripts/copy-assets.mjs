import { cpSync, existsSync, statSync } from 'node:fs';
import { dirname, extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const assets = [['src/rag', 'dist/rag']];
const copied = new Set(['.md', '.json']);

for (const [from, to] of assets) {
  const source = resolve(root, from);
  if (!existsSync(source)) continue;
  cpSync(source, resolve(root, to), {
    recursive: true,
    filter: (path) => statSync(path).isDirectory() || copied.has(extname(path)),
  });
}
