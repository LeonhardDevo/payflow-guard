import { readdirSync, readFileSync, statSync } from 'node:fs';
import { extname, join, relative } from 'node:path';

export interface CommentViolation {
  file: string;
  line: number;
  text: string;
}

const SOURCE_EXTENSIONS = new Set(['.php', '.ts', '.vue', '.py']);
const SKIPPED_DIRS = new Set(['node_modules', 'vendor', 'dist', 'build', 'storage', '.venv', '__pycache__', 'actions', 'routes', 'wayfinder', 'test-results', 'playwright-report', 'artifacts']);
const ALLOWED_LINE = /^\s*(\/\/\s*(eslint-|@ts-)|\/\/\/\s*<reference|#\s*(type:|noqa|pragma)|#!)/;
const LINE_COMMENT = /^\s*(\/\/|#(?![[!]))/;
const PROSE_DOC_LINE = /^\s*\*\s+(?!@)[A-Za-z]/;
const PROSE_DOC_OPEN = /^\s*\/\*\*?\s+(?!@)[A-Za-z]/;

export function findCommentViolations(file: string, content: string): CommentViolation[] {
  const extension = extname(file);
  const violations: CommentViolation[] = [];
  const lines = content.split(/\r?\n/);
  let inTemplateString = false;

  lines.forEach((text, index) => {
    const backticks = (text.match(/`/g) ?? []).length;
    if (backticks % 2 === 1) inTemplateString = !inTemplateString;
    if (inTemplateString || ALLOWED_LINE.test(text)) return;
    const lineComment = extension === '.php' || extension === '.py' ? LINE_COMMENT.test(text) : /^\s*\/\//.test(text);
    const htmlComment = extension === '.vue' && /<!--(?!\s*\[)/.test(text);
    if (lineComment || htmlComment || PROSE_DOC_LINE.test(text) || PROSE_DOC_OPEN.test(text)) {
      violations.push({ file, line: index + 1, text: text.trim() });
    }
  });
  return violations;
}

export function collectSources(root: string, dirs: readonly string[]): string[] {
  const files: string[] = [];
  const walk = (path: string): void => {
    let stats;
    try {
      stats = statSync(path);
    } catch {
      return;
    }
    if (stats.isDirectory()) {
      if (SKIPPED_DIRS.has(path.split(/[\\/]/).pop() ?? '')) return;
      for (const entry of readdirSync(path)) walk(join(path, entry));
      return;
    }
    if (SOURCE_EXTENSIONS.has(extname(path))) files.push(path);
  };
  for (const dir of dirs) walk(join(root, dir));
  return files;
}

export function scanComments(root: string, dirs: readonly string[]): CommentViolation[] {
  return collectSources(root, dirs).flatMap((file) => findCommentViolations(relative(root, file), readFileSync(file, 'utf8')));
}
