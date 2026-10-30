import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { computeStamp, depsUpToDate, writeDepsStamp, type StampInputs } from '../lib/deps-stamp.ts';

const BASE: StampInputs = {
  packageJson: JSON.stringify({ name: 'x', scripts: { dev: 'vite' }, dependencies: { vue: '^3.5.0' } }),
  lockfile: '{"lockfileVersion":3}',
  platform: 'win32',
  arch: 'x64',
  nodeMajor: '24',
};

describe('computeStamp', () => {
  it('ignores package.json fields that do not affect the installed tree', () => {
    const scriptsChanged = { ...BASE, packageJson: JSON.stringify({ name: 'y', scripts: { dev: 'vp dev' }, dependencies: { vue: '^3.5.0' } }) };
    assert.equal(computeStamp(scriptsChanged), computeStamp(BASE));
  });

  it('changes with dependencies, lockfile, platform and node major', () => {
    const base = computeStamp(BASE);
    const variants: StampInputs[] = [
      { ...BASE, packageJson: JSON.stringify({ dependencies: { vue: '^3.6.0' } }) },
      { ...BASE, packageJson: JSON.stringify({ dependencies: { vue: '^3.5.0' }, optionalDependencies: { a: '1' } }) },
      { ...BASE, lockfile: '{"lockfileVersion":3,"x":1}' },
      { ...BASE, lockfile: null },
      { ...BASE, platform: 'linux' },
      { ...BASE, arch: 'arm64' },
      { ...BASE, nodeMajor: '26' },
    ];
    for (const variant of variants) assert.notEqual(computeStamp(variant), base);
  });
});

describe('depsUpToDate / writeDepsStamp', () => {
  it('is false until a stamp is written, true afterwards, false again when the lockfile changes', () => {
    const dir = mkdtempSync(join(tmpdir(), 'deps-stamp-'));
    try {
      writeFileSync(join(dir, 'package.json'), BASE.packageJson);
      writeFileSync(join(dir, 'package-lock.json'), BASE.lockfile!);
      assert.equal(depsUpToDate(dir), false, 'no node_modules');

      writeDepsStamp(dir);
      assert.equal(depsUpToDate(dir), false);

      mkdirSync(join(dir, 'node_modules'));
      assert.equal(depsUpToDate(dir), false, 'node_modules without stamp');
      writeDepsStamp(dir);
      assert.equal(depsUpToDate(dir), true);

      writeFileSync(join(dir, 'package-lock.json'), '{"lockfileVersion":3,"changed":true}');
      assert.equal(depsUpToDate(dir), false);
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });
});
