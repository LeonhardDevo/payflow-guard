import { createHash } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

export const STAMP_FILE = '.platform-setup-stamp';

const DEPENDENCY_FIELDS = ['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies', 'overrides'] as const;

export interface StampInputs {
  packageJson: string;
  lockfile: string | null;
  platform: string;
  arch: string;
  nodeMajor: string;
}

export function computeStamp(inputs: StampInputs): string {
  const manifest = JSON.parse(inputs.packageJson) as Record<string, unknown>;
  const deps = Object.fromEntries(DEPENDENCY_FIELDS.map((field) => [field, manifest[field] ?? null]));
  return createHash('sha256')
    .update(JSON.stringify(deps))
    .update('\0')
    .update(inputs.lockfile ?? '<no lockfile>')
    .update('\0')
    .update(`${inputs.platform}-${inputs.arch}-node${inputs.nodeMajor}`)
    .digest('hex');
}

function currentStamp(dir: string): string | null {
  const manifest = join(dir, 'package.json');
  if (!existsSync(manifest)) return null;
  const lock = join(dir, 'package-lock.json');
  return computeStamp({
    packageJson: readFileSync(manifest, 'utf8'),
    lockfile: existsSync(lock) ? readFileSync(lock, 'utf8') : null,
    platform: process.platform,
    arch: process.arch,
    nodeMajor: process.versions.node.split('.')[0] ?? '',
  });
}

export function depsUpToDate(dir: string): boolean {
  const stampPath = join(dir, 'node_modules', STAMP_FILE);
  if (!existsSync(stampPath)) return false;
  const expected = currentStamp(dir);
  return expected !== null && readFileSync(stampPath, 'utf8').trim() === expected;
}

export function writeDepsStamp(dir: string): void {
  const stamp = currentStamp(dir);
  if (stamp === null || !existsSync(join(dir, 'node_modules'))) return;
  writeFileSync(join(dir, 'node_modules', STAMP_FILE), `${stamp}\n`, 'utf8');
}
