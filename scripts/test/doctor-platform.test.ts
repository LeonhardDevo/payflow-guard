import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, it } from 'node:test';
import { platformDocCheck } from '../lib/doctor.ts';

describe('platformDocCheck', () => {
  it('reports whether the project PLATFORM.md matches the canonical parent copy', () => {
    const parent = mkdtempSync(join(tmpdir(), 'platform-'));
    const root = join(parent, 'product');
    mkdirSync(root);
    try {
      assert.equal(platformDocCheck(root), null);
      writeFileSync(join(parent, 'PLATFORM.md'), '# rules v2\n');
      writeFileSync(join(root, 'PLATFORM.md'), '# rules v1\n');
      assert.equal(platformDocCheck(root)?.ok, false);
      writeFileSync(join(root, 'PLATFORM.md'), '# rules v2\n');
      assert.equal(platformDocCheck(root)?.ok, true);
    } finally {
      rmSync(parent, { recursive: true, force: true });
    }
  });
});
