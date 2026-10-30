import { describe, expect, it } from 'vitest';
import { createSilentLogger } from '../../src/logger.js';
import { OcrTimeoutError, OcrUnavailableError, TesseractOcr } from '../../src/rag/ingest/ocr.js';
import { DocumentParseError, DocumentParser, decodeText, detectFormat, htmlToBlocks, imageDimensions, markdownToBlocks } from '../../src/rag/ingest/parsers.js';
import { encodePnm } from '../../src/rag/ingest/pdf.js';
import { checkZipPackage } from '../../src/rag/ingest/zip.js';
import { encodePng, makeBlankPdf, makeDocx, makeScannedPdf, makeTextPdf, makeZip, renderTextImage } from './support/fixtures.js';
import { noOcr, ragConfig } from './support/harness.js';

const logger = createSilentLogger();
const config = ragConfig();
const tesseract = new TesseractOcr({
  command: config.ocr.command,
  languages: 'rus+eng',
  timeoutMs: 60_000,
  tessdataPrefix: config.ocr.tessdataPrefix,
  logger,
});
const ocrAvailable = await tesseract.available();
const parser = new DocumentParser({ ocr: tesseract, logger });
const offlineParser = new DocumentParser({ ocr: noOcr, logger });

describe('format detection and decoding', () => {
  it('detects formats by mime, extension and magic bytes', () => {
    expect(detectFormat(Buffer.from('%PDF-1.4'), 'application/pdf')).toBe('pdf');
    expect(detectFormat(Buffer.from('%PDF-1.4'), null)).toBe('pdf');
    expect(detectFormat(Buffer.from('x'), 'application/octet-stream', 'notes.md')).toBe('md');
    expect(detectFormat(Buffer.from('<!DOCTYPE html><p>x'), null)).toBe('html');
    expect(detectFormat(encodePng(renderTextImage(['A'])), null)).toBe('image');
    expect(detectFormat(makeDocx([{ text: 'x' }]), 'application/octet-stream')).toBe('docx');
    expect(detectFormat(Buffer.from('plain'), 'text/plain')).toBe('txt');
    expect(() => detectFormat(Buffer.from('MZ'), 'application/x-msdownload')).toThrow(/unsupported/);
  });

  it('decodes UTF-8 with BOM and Windows-1251', () => {
    expect(decodeText(Buffer.concat([Buffer.from([0xef, 0xbb, 0xbf]), Buffer.from('Отпуск', 'utf8')]))).toBe('Отпуск');
    expect(decodeText(Buffer.from([0xce, 0xf2, 0xef, 0xf3, 0xf1, 0xea]))).toBe('Отпуск');
    expect(decodeText(Buffer.from([0xff, 0xfe, 0x41, 0x00]))).toBe('A');
  });

  it('reads image dimensions without decoding', () => {
    expect(imageDimensions(encodePng({ width: 7, height: 5, pixels: new Uint8Array(35) }))).toEqual({ width: 7, height: 5 });
    expect(imageDimensions(Buffer.from('nope'))).toBeNull();
  });

  it('encodes decoded PDF images as PNM for tesseract', () => {
    const gray = encodePnm({ width: 2, height: 1, data: new Uint8Array([0, 255]) })!;
    expect(gray.subarray(0, 2).toString('latin1')).toBe('P5');
    const rgba = encodePnm({ width: 1, height: 1, kind: 3, data: new Uint8Array([1, 2, 3, 4]) })!;
    expect([...rgba.subarray(rgba.length - 3)]).toEqual([1, 2, 3]);
    const mono = encodePnm({ width: 8, height: 1, kind: 1, data: new Uint8Array([0b11110000]) })!;
    expect(mono.subarray(0, 2).toString('latin1')).toBe('P4');
    expect(mono[mono.length - 1]).toBe(0b00001111);
  });
});

describe('document parsers', () => {
  it('keeps pages and headings of a PDF text layer', async () => {
    const doc = await parser.parse(
      makeTextPdf([
        ['1. Travel Policy', 'Employees book hotels through the portal.', 'The hotel limit is 150 EUR per night.'],
        ['2. Meals', 'Meals are reimbursed up to 40 EUR per day.'],
      ]),
      'application/pdf',
      'travel.pdf',
    );
    expect(doc.pages).toBe(2);
    expect(doc.ocrUsed).toBe(false);
    expect(doc.blocks.filter((item) => item.kind === 'heading').map((item) => [item.text, item.page])).toEqual([
      ['1. Travel Policy', 1],
      ['2. Meals', 2],
    ]);
    expect(doc.blocks.some((item) => item.text.includes('150 EUR') && item.page === 1)).toBe(true);
  });

  it('reports a clear error for a scanned PDF without OCR', async () => {
    await expect(offlineParser.parse(makeBlankPdf(), 'application/pdf')).rejects.toThrow(DocumentParseError);
    await expect(offlineParser.parse(makeBlankPdf(), 'application/pdf')).rejects.toThrow(/no text/);
  });

  it.skipIf(!ocrAvailable)('runs OCR on a scanned PDF page', async () => {
    const doc = await parser.parse(makeScannedPdf(renderTextImage(['HOTEL LIMIT PER NIGHT', 'MONDAY TO FRIDAY'])), 'application/pdf', 'scan.pdf');
    expect(doc.ocrUsed).toBe(true);
    const text = doc.blocks.map((item) => item.text).join(' ').toLowerCase();
    expect(text).toContain('hotel');
    expect(text).toContain('friday');
    if ((await tesseract.missingLanguages()).includes('rus')) expect(doc.warnings.some((warning) => warning.includes('rus'))).toBe(true);
  });

  it.skipIf(!ocrAvailable)('runs OCR on images', async () => {
    const doc = await parser.parse(encodePng(renderTextImage(['SUPPORT HOURS', 'MONDAY TO FRIDAY'])), 'image/png', 'hours.png');
    expect(doc.ocrUsed).toBe(true);
    expect(doc.pages).toBe(1);
    expect(doc.blocks.map((item) => item.text).join(' ').toLowerCase()).toContain('monday');
  });

  it.skipIf(!ocrAvailable)('reports an OCR timeout per page', async () => {
    const slow = new TesseractOcr({ command: config.ocr.command, languages: 'eng', timeoutMs: 1, tessdataPrefix: config.ocr.tessdataPrefix, logger });
    const image = encodePng(renderTextImage(['SUPPORT HOURS']));
    await expect(slow.imageToText(image)).rejects.toBeInstanceOf(OcrTimeoutError);
    await expect(new DocumentParser({ ocr: slow, logger }).parse(image, 'image/png', 'slow.png')).rejects.toThrow(/timed out/);
  });

  it('rejects images when OCR is unavailable and oversized images always', async () => {
    await expect(offlineParser.parse(encodePng(renderTextImage(['X'])), 'image/png')).rejects.toThrow(OcrUnavailableError);
    const huge = encodePng({ width: 1, height: 1, pixels: new Uint8Array(1) });
    huge.writeUInt32BE(20_000, 16);
    huge.writeUInt32BE(20_000, 20);
    await expect(
      new DocumentParser({ ocr: { ...noOcr, available: async () => true, missingLanguages: async () => [] }, logger }).parse(huge, 'image/png'),
    ).rejects.toThrow(/too large/);
  });

  it('keeps DOCX headings, lists, tables and bold headings', async () => {
    const doc = await parser.parse(
      makeDocx([
        { text: 'Bank Details Change Procedure', style: 'Title' },
        { text: 'Who calls the vendor', style: 'Heading1' },
        { text: 'The bookkeeper calls the vendor on the contract number.' },
        { text: 'Never use the number from the e-mail', list: true },
        { table: [['Role', 'Action'], ['Approver', 'Confirms']] },
        { text: '2. Escalation', bold: true },
        { text: 'Escalate to the owner.' },
      ]),
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    );
    expect(doc.blocks.map((item) => [item.kind, item.text])).toEqual([
      ['heading', 'Bank Details Change Procedure'],
      ['heading', 'Who calls the vendor'],
      ['paragraph', 'The bookkeeper calls the vendor on the contract number.'],
      ['list', 'Never use the number from the e-mail'],
      ['table', 'Role | Action\nApprover | Confirms'],
      ['heading', '2. Escalation'],
      ['paragraph', 'Escalate to the owner.'],
    ]);
  });

  it('parses Markdown structure', () => {
    const blocks = markdownToBlocks('# Policy\n\nIntro text.\n\n## Steps\n\n- Pause\n- Call\n\n| A | B |\n|---|---|\n| 1 | 2 |\n\n```\ncode line\n```\n');
    expect(blocks.map((item) => [item.kind, item.level, item.text])).toEqual([
      ['heading', 1, 'Policy'],
      ['paragraph', 0, 'Intro text.'],
      ['heading', 2, 'Steps'],
      ['list', 0, 'Pause'],
      ['list', 0, 'Call'],
      ['table', 0, 'A | B\n1 | 2'],
      ['code', 0, 'code line'],
    ]);
  });

  it('strips HTML chrome and keeps structure', () => {
    const blocks = htmlToBlocks(
      '<html><head><title>T</title><script>alert(1)</script></head><body><nav>Menu</nav><h2>Rules</h2><div><p>First <b>rule</b>.</p><ul><li>One</li></ul></div><table><tr><th>K</th><td>V</td></tr></table><footer>Footer</footer></body></html>',
    );
    expect(blocks.map((item) => [item.kind, item.text])).toEqual([
      ['heading', 'Rules'],
      ['paragraph', 'First rule .'],
      ['list', 'One'],
      ['table', 'K | V'],
    ]);
  });

  it('parses plain text and rejects empty input', async () => {
    const doc = await parser.parse(Buffer.from('Callback rules\n\nAlways call back.'), 'text/plain');
    expect(doc.blocks.map((item) => item.text)).toEqual(['Callback rules', 'Always call back.']);
    await expect(parser.parse(Buffer.alloc(0), 'text/plain')).rejects.toThrow(/empty/);
  });
});

describe('zip bomb guards', () => {
  it('rejects packages that expand beyond the limit before parsing', async () => {
    const bomb = makeZip([{ name: 'word/document.xml', data: Buffer.from('x'), compress: false, declaredSize: 200 * 1024 * 1024 }]);
    expect(() => checkZipPackage(bomb)).toThrow(/expands beyond/);
    await expect(parser.parse(bomb, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')).rejects.toThrow(DocumentParseError);
  });

  it('rejects a suspicious compression ratio', () => {
    const ratio = makeZip([{ name: 'word/document.xml', data: Buffer.alloc(5 * 1024 * 1024, 0x41) }]);
    expect(() => checkZipPackage(ratio)).toThrow(/compression ratio/);
  });
});
