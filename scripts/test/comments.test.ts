import assert from 'node:assert/strict';
import { test } from 'node:test';
import { findCommentViolations } from '../lib/comments.ts';

test('flags prose comments and docblocks', () => {
  const php = ['<?php', '// explains things', '/**', ' * Explains the class.', ' * @return array<string, int>', ' */', '$a = 1;'].join('\n');
  assert.deepEqual(
    findCommentViolations('x.php', php).map((violation) => violation.line),
    [2, 4],
  );
});

test('keeps machine-read annotations, attributes and directives', () => {
  const php = ['<?php', '/** @var array<string, int> $map */', '#[Override]', '$url = "https://example.test";'].join('\n');
  assert.deepEqual(findCommentViolations('x.php', php), []);
  const ts = ['// eslint-disable-next-line no-control-regex', 'const re = /\\x00/;', 'const url = `https://x.test/${id}`;'].join('\n');
  assert.deepEqual(findCommentViolations('x.ts', ts), []);
});

test('flags HTML comments in Vue templates', () => {
  assert.equal(findCommentViolations('x.vue', '<template>\n  <!-- header -->\n</template>').length, 1);
});
