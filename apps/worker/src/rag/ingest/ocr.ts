import { spawn } from 'node:child_process';
import type { Logger } from '../../logger.js';

export class OcrUnavailableError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'OcrUnavailableError';
  }
}

export class OcrTimeoutError extends OcrUnavailableError {
  constructor(message: string) {
    super(message);
    this.name = 'OcrTimeoutError';
  }
}

export interface OcrEngine {
  available(): Promise<boolean>;
  missingLanguages(): Promise<string[]>;
  languages(): Promise<string>;
  imageToText(image: Buffer): Promise<string>;
}

interface RunResult {
  code: number | null;
  stdout: Buffer;
  stderr: string;
  timedOut: boolean;
}

export interface TesseractOptions {
  command: string;
  languages: string;
  timeoutMs: number;
  tessdataPrefix?: string | undefined;
  logger: Logger;
}

function run(command: string, args: string[], input: Buffer | null, timeoutMs: number, env: NodeJS.ProcessEnv): Promise<RunResult> {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, { env, windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'] });
    const stdout: Buffer[] = [];
    let stderr = '';
    let timedOut = false;
    const timer = setTimeout(() => {
      timedOut = true;
      child.kill('SIGKILL');
    }, timeoutMs);
    child.stdout.on('data', (chunk: Buffer) => stdout.push(chunk));
    child.stderr.on('data', (chunk: Buffer) => {
      if (stderr.length < 4000) stderr += chunk.toString('utf8');
    });
    child.on('error', (error) => {
      clearTimeout(timer);
      reject(error);
    });
    child.on('close', (code) => {
      clearTimeout(timer);
      resolve({ code, stdout: Buffer.concat(stdout), stderr, timedOut });
    });
    child.stdin.on('error', () => undefined);
    child.stdin.end(input ?? undefined);
  });
}

export class TesseractOcr implements OcrEngine {
  private readonly requested: string[];
  private resolved: Promise<{ languages: string; missing: string[] }> | null = null;

  constructor(private readonly options: TesseractOptions) {
    this.requested = options.languages
      .replace(/,/g, '+')
      .split('+')
      .map((lang) => lang.trim())
      .filter(Boolean);
  }

  private env(): NodeJS.ProcessEnv {
    return this.options.tessdataPrefix ? { ...process.env, TESSDATA_PREFIX: this.options.tessdataPrefix } : process.env;
  }

  private resolve(): Promise<{ languages: string; missing: string[] }> {
    this.resolved ??= (async () => {
      let installed: Set<string>;
      try {
        const result = await run(this.options.command, ['--list-langs'], null, 15_000, this.env());
        if (result.code !== 0) throw new Error(result.stderr.trim() || `exit code ${String(result.code)}`);
        const lines = result.stdout.toString('utf8').split(/\r?\n/).slice(1);
        installed = new Set(lines.map((line) => line.trim()).filter(Boolean));
      } catch (error) {
        const message = `tesseract is not available: ${error instanceof Error ? error.message : String(error)}`;
        this.options.logger.warn({ error: message }, 'ocr unavailable');
        throw new OcrUnavailableError(message);
      }
      let usable = this.requested.filter((lang) => installed.has(lang));
      const missing = this.requested.filter((lang) => !installed.has(lang));
      if (missing.length > 0) this.options.logger.warn({ missing, installed: [...installed].sort() }, 'ocr language data missing');
      if (usable.length === 0) usable = installed.has('eng') ? ['eng'] : [...installed].filter((lang) => lang !== 'osd').slice(0, 1);
      if (usable.length === 0) throw new OcrUnavailableError('tesseract has no usable language data');
      return { languages: usable.join('+'), missing };
    })();
    return this.resolved;
  }

  async available(): Promise<boolean> {
    try {
      await this.resolve();
      return true;
    } catch {
      return false;
    }
  }

  async missingLanguages(): Promise<string[]> {
    try {
      return (await this.resolve()).missing;
    } catch {
      return [...this.requested];
    }
  }

  async languages(): Promise<string> {
    return (await this.resolve()).languages;
  }

  async imageToText(image: Buffer): Promise<string> {
    const { languages } = await this.resolve();
    let result: RunResult;
    try {
      result = await run(this.options.command, ['stdin', 'stdout', '-l', languages, '--psm', '3'], image, this.options.timeoutMs, this.env());
    } catch (error) {
      throw new OcrUnavailableError(`OCR failed: ${error instanceof Error ? error.message : String(error)}`);
    }
    if (result.timedOut) throw new OcrTimeoutError(`OCR timed out after ${Math.round(this.options.timeoutMs / 1000)}s per page`);
    if (result.code !== 0) throw new OcrUnavailableError(`OCR failed: ${result.stderr.trim().slice(0, 300) || `exit code ${String(result.code)}`}`);
    return result.stdout.toString('utf8');
  }
}
