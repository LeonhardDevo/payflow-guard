import { spawnSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, statSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const target = resolve(root, '.tessdata');
const baseUrl = (process.env.TESSDATA_FAST_URL ?? 'https://github.com/tesseract-ocr/tessdata_fast/raw/main').replace(/\/+$/, '');
const command = process.env.TESSERACT_PATH ?? process.env.TESSERACT_CMD ?? 'tesseract';

function systemTessdata() {
  const result = spawnSync(command, ['--list-langs'], { encoding: 'utf8' });
  if (result.status !== 0) return null;
  const header = `${result.stdout}${result.stderr}`.split(/\r?\n/).find((line) => /available languages in/i.test(line));
  const match = header ? /"([^"]+)"/.exec(header) : null;
  return match ? match[1].replace(/[\\/]+$/, '') : null;
}

async function download(lang) {
  const file = resolve(target, `${lang}.traineddata`);
  if (existsSync(file) && statSync(file).size > 0) {
    process.stdout.write(`${lang}: already present\n`);
    return;
  }
  const response = await fetch(`${baseUrl}/${lang}.traineddata`, { redirect: 'follow', signal: AbortSignal.timeout(120_000) });
  if (!response.ok) throw new Error(`${lang}: download failed with HTTP ${response.status}`);
  writeFileSync(file, Buffer.from(await response.arrayBuffer()));
  process.stdout.write(`${lang}: downloaded\n`);
}

async function main() {
  mkdirSync(target, { recursive: true });
  const source = systemTessdata();
  for (const lang of ['eng', 'osd']) {
    const from = source ? resolve(source, `${lang}.traineddata`) : null;
    const to = resolve(target, `${lang}.traineddata`);
    if (from && existsSync(from) && !existsSync(to)) {
      copyFileSync(from, to);
      process.stdout.write(`${lang}: copied from ${source}\n`);
    } else if (!existsSync(to)) {
      await download(lang);
    }
  }
  await download('rus');
  process.stdout.write(`Set TESSDATA_PREFIX=${target}\n`);
}

main().catch((error) => {
  process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
  process.exitCode = 1;
});
