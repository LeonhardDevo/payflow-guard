import { describe, expect, it } from 'vitest';
import { createSilentLogger } from '../../src/logger.js';
import { DocumentParseError, UnsupportedDocumentError } from '../../src/rag/ingest/parsers.js';
import { IsolatedDocumentParser, rebuildError, sandboxEnv } from '../../src/rag/ingest/sandbox.js';

const OCR = { command: 'tesseract', languages: 'rus+eng', timeoutMs: 30_000, tessdataPrefix: null, maxPages: 5 };

function parser(timeoutMs = 60_000): IsolatedDocumentParser {
  return new IsolatedDocumentParser({ ocr: OCR, timeoutMs, maxOldSpaceMb: 256, logger: createSilentLogger() });
}

describe('parser sandbox', () => {
  it('starts the parser without keys, database or provider settings', () => {
    const env = sandboxEnv(
      {
        PATH: '/usr/bin',
        SystemRoot: 'C:\\Windows',
        TEMP: '/tmp',
        NODE_ENV: 'production',
        DATABASE_URL: 'postgres://app:app@db/payflow',
        REDIS_URL: 'redis://redis:6379',
        INTERNAL_API_SECRET: 'secret',
        INTERNAL_KEY_WEB_AI: 'k1',
        INTERNAL_KEY_WORKER_AI: 'k2',
        INTERNAL_KEY_WEB_WORKER: 'k3',
        AI_SETTINGS_KEY: 'k4',
        ANTHROPIC_API_KEY: 'sk-ant',
        OPENAI_API_KEY: 'sk-openai',
        EMBEDDINGS_API_KEY: 'sk-emb',
        TESSDATA_PREFIX: '/opt/tessdata',
      },
      null,
    );
    expect(env).toEqual({ NODE_ENV: 'production', PATH: '/usr/bin', SystemRoot: 'C:\\Windows', TEMP: '/tmp', TESSDATA_PREFIX: '/opt/tessdata' });
    expect(sandboxEnv({ PATH: '/bin' }, '/srv/tessdata')).toEqual({ NODE_ENV: 'development', PATH: '/bin', TESSDATA_PREFIX: '/srv/tessdata' });
  });

  it('maps parser failures back to the ingest error types', () => {
    expect(rebuildError({ name: 'UnsupportedDocumentError', message: 'x' })).toBeInstanceOf(UnsupportedDocumentError);
    expect(rebuildError({ name: 'DocumentParseError', message: 'x' })).toBeInstanceOf(DocumentParseError);
    const unknown = rebuildError({ name: 'RangeError', message: 'boom' });
    expect(unknown).toBeInstanceOf(DocumentParseError);
    expect(unknown.message).toContain('boom');
  });

  it('parses a document in a child process', async () => {
    const parsed = await parser().parse(Buffer.from('# Leave policy\n\nEmployees get 28 days of paid vacation.\n'), 'text/markdown', 'policy.md');
    expect(parsed.ocrUsed).toBe(false);
    expect(parsed.blocks.map((block) => block.text)).toEqual(['Leave policy', 'Employees get 28 days of paid vacation.']);
  }, 60_000);

  it('reports unsupported files and kills a parser that runs too long', async () => {
    await expect(parser().parse(Buffer.from([0x00, 0x01, 0x02, 0x03]), 'application/x-msdownload', 'tool.exe')).rejects.toBeInstanceOf(UnsupportedDocumentError);
    await expect(parser(1).parse(Buffer.from('# Title\n\nBody.'), 'text/markdown', 'a.md')).rejects.toThrow(/timed out/);
  }, 60_000);
});
