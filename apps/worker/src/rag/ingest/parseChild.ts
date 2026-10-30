import { createSilentLogger } from '../../logger.js';
import { TesseractOcr } from './ocr.js';
import { DocumentParser } from './parsers.js';
import type { ParseChildReply, ParseChildRequest } from './sandbox.js';

function reply(message: ParseChildReply): void {
  if (!process.send) {
    process.exit(2);
  }
  process.send(message, () => process.exit(0));
}

function toBuffer(data: unknown): Buffer {
  if (Buffer.isBuffer(data)) return data;
  if (data instanceof Uint8Array) return Buffer.from(data.buffer, data.byteOffset, data.byteLength);
  throw new TypeError('document bytes are missing');
}

process.once('message', (request: ParseChildRequest) => {
  const logger = createSilentLogger();
  const ocr = new TesseractOcr({
    command: request.ocr.command,
    languages: request.ocr.languages,
    timeoutMs: request.ocr.timeoutMs,
    tessdataPrefix: request.ocr.tessdataPrefix ?? undefined,
    logger,
  });
  const parser = new DocumentParser({ ocr, logger, maxOcrPages: request.ocr.maxPages });
  Promise.resolve()
    .then(() => parser.parse(toBuffer(request.data), request.mime, request.filename))
    .then((document) => reply({ ok: true, document }))
    .catch((error: unknown) =>
      reply({
        ok: false,
        error: error instanceof Error ? { name: error.name, message: error.message } : { name: 'Error', message: String(error) },
      }),
    );
});

process.on('disconnect', () => process.exit(0));
