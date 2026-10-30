import { fork } from 'node:child_process';
import { existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { Logger } from '../../logger.js';
import type { ParsedDocument } from './blocks.js';
import { OcrTimeoutError, OcrUnavailableError } from './ocr.js';
import { DocumentParseError, UnsupportedDocumentError } from './parsers.js';

export interface DocumentParsing {
  parse(data: Buffer, mime: string | null | undefined, filename?: string | null): Promise<ParsedDocument>;
}

export interface ParseChildOcrOptions {
  command: string;
  languages: string;
  timeoutMs: number;
  tessdataPrefix: string | null;
  maxPages: number;
}

export interface ParseChildRequest {
  data: Buffer;
  mime: string | null;
  filename: string | null;
  ocr: ParseChildOcrOptions;
}

export type ParseChildReply = { ok: true; document: ParsedDocument } | { ok: false; error: { name: string; message: string } };

export interface IsolatedParserOptions {
  ocr: ParseChildOcrOptions;
  timeoutMs: number;
  maxOldSpaceMb: number;
  logger: Logger;
  source?: NodeJS.ProcessEnv;
}

const PASSTHROUGH_ENV = ['PATH', 'Path', 'PATHEXT', 'SystemRoot', 'SYSTEMROOT', 'windir', 'TEMP', 'TMP', 'TMPDIR', 'LANG', 'LC_ALL'] as const;
const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');

export class ParserSandboxError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'ParserSandboxError';
  }
}

export function sandboxEnv(source: NodeJS.ProcessEnv, tessdataPrefix: string | null): NodeJS.ProcessEnv {
  const env: NodeJS.ProcessEnv = { NODE_ENV: source.NODE_ENV === 'production' ? 'production' : 'development' };
  for (const key of PASSTHROUGH_ENV) {
    const value = source[key];
    if (value !== undefined && value !== '') env[key] = value;
  }
  if (tessdataPrefix) env.TESSDATA_PREFIX = tessdataPrefix;
  else if (source.TESSDATA_PREFIX) env.TESSDATA_PREFIX = source.TESSDATA_PREFIX;
  return env;
}

export function childModule(): { path: string; execArgv: string[] } {
  const compiled = fileURLToPath(new URL('./parseChild.js', import.meta.url));
  if (existsSync(compiled)) return { path: compiled, execArgv: [] };
  return { path: fileURLToPath(new URL('./parseChild.ts', import.meta.url)), execArgv: ['--import', 'tsx'] };
}

export function rebuildError(error: { name: string; message: string }): Error {
  switch (error.name) {
    case 'DocumentParseError':
      return new DocumentParseError(error.message);
    case 'UnsupportedDocumentError':
      return new UnsupportedDocumentError(error.message);
    case 'OcrTimeoutError':
      return new OcrTimeoutError(error.message);
    case 'OcrUnavailableError':
      return new OcrUnavailableError(error.message);
    default:
      return new DocumentParseError(`the document could not be parsed: ${error.message}`);
  }
}

export class IsolatedDocumentParser implements DocumentParsing {
  constructor(private readonly options: IsolatedParserOptions) {}

  parse(data: Buffer, mime: string | null | undefined, filename?: string | null): Promise<ParsedDocument> {
    const { path, execArgv } = childModule();
    const child = fork(path, [], {
      cwd: PACKAGE_ROOT,
      env: sandboxEnv(this.options.source ?? process.env, this.options.ocr.tessdataPrefix),
      execArgv: [...execArgv, `--max-old-space-size=${this.options.maxOldSpaceMb}`],
      serialization: 'advanced',
      stdio: ['ignore', 'ignore', 'pipe', 'ipc'],
    });
    const request: ParseChildRequest = { data, mime: mime ?? null, filename: filename ?? null, ocr: this.options.ocr };
    let stderr = '';
    child.stderr?.on('data', (chunk: Buffer) => {
      if (stderr.length < 4000) stderr += chunk.toString('utf8');
    });

    return new Promise<ParsedDocument>((resolvePromise, reject) => {
      let settled = false;
      const finish = (error: Error | null, document?: ParsedDocument): void => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        if (child.exitCode === null && child.signalCode === null) child.kill('SIGKILL');
        if (error) reject(error);
        else resolvePromise(document!);
      };
      const timer = setTimeout(() => {
        this.options.logger.warn({ timeoutMs: this.options.timeoutMs }, 'document parser timed out; child process killed');
        finish(new DocumentParseError(`parsing timed out after ${Math.round(this.options.timeoutMs / 1000)}s`));
      }, this.options.timeoutMs);
      child.on('message', (message: ParseChildReply) => {
        if (message.ok) finish(null, message.document);
        else finish(rebuildError(message.error));
      });
      child.on('error', (error) => finish(new ParserSandboxError(`document parser could not start: ${error.message}`)));
      child.on('exit', (code, signal) => {
        if (settled) return;
        const detail = stderr.trim().split(/\r?\n/).slice(-3).join(' | ');
        this.options.logger.warn({ code, signal, detail }, 'document parser exited without a result');
        finish(new DocumentParseError(`the document parser stopped unexpectedly (${signal ?? `exit code ${String(code)}`})`));
      });
      child.send(request, (error) => {
        if (error) finish(new ParserSandboxError(`document could not be handed to the parser: ${error.message}`));
      });
    });
  }
}
