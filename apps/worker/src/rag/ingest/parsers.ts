import { isTag, isText, type ChildNode, type Element } from 'domhandler';
import { parseDocument } from 'htmlparser2';
import mammoth from 'mammoth';
import MarkdownIt, { type Token } from 'markdown-it';
import type { Logger } from '../../logger.js';
import { collapseWhitespace } from '../text.js';
import { block, headingLevel, linesToBlocks, type Block, type BlockKind, type ParsedDocument } from './blocks.js';
import { OcrUnavailableError, type OcrEngine } from './ocr.js';
import { extractPdf, PdfPasswordError } from './pdf.js';
import { checkZipPackage, ZipPackageError } from './zip.js';

export const MAX_IMAGE_PIXELS = 50_000_000;

export type DocumentFormat = 'pdf' | 'docx' | 'txt' | 'md' | 'html' | 'image';

const PDF_MIMES = new Set(['application/pdf', 'application/x-pdf']);
const DOCX_MIMES = new Set(['application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
const TEXT_MIMES = new Set(['text/plain', 'text/csv']);
const MARKDOWN_MIMES = new Set(['text/markdown', 'text/x-markdown']);
const HTML_MIMES = new Set(['text/html', 'application/xhtml+xml']);
const IMAGE_MIMES = new Set(['image/png', 'image/jpeg', 'image/jpg', 'image/tiff', 'image/bmp', 'image/gif', 'image/webp']);
const EXTENSIONS: Readonly<Record<string, DocumentFormat>> = {
  '.pdf': 'pdf',
  '.docx': 'docx',
  '.txt': 'txt',
  '.csv': 'txt',
  '.md': 'md',
  '.markdown': 'md',
  '.html': 'html',
  '.htm': 'html',
  '.png': 'image',
  '.jpg': 'image',
  '.jpeg': 'image',
  '.tif': 'image',
  '.tiff': 'image',
  '.bmp': 'image',
  '.gif': 'image',
  '.webp': 'image',
};

export class UnsupportedDocumentError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'UnsupportedDocumentError';
  }
}

export class DocumentParseError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'DocumentParseError';
  }
}

function startsWith(data: Buffer, bytes: readonly number[], offset = 0): boolean {
  return bytes.every((value, index) => data[offset + index] === value);
}

function isImageMagic(data: Buffer): boolean {
  return (
    startsWith(data, [0x89, 0x50, 0x4e, 0x47]) ||
    startsWith(data, [0xff, 0xd8, 0xff]) ||
    startsWith(data, [0x47, 0x49, 0x46, 0x38]) ||
    startsWith(data, [0x49, 0x49, 0x2a, 0x00]) ||
    startsWith(data, [0x4d, 0x4d, 0x00, 0x2a]) ||
    startsWith(data, [0x42, 0x4d]) ||
    (startsWith(data, [0x52, 0x49, 0x46, 0x46]) && data.subarray(8, 12).toString('latin1') === 'WEBP')
  );
}

export function detectFormat(data: Buffer, mime: string | null | undefined, filename?: string | null): DocumentFormat {
  const type = (mime ?? '').split(';')[0]!.trim().toLowerCase();
  if (PDF_MIMES.has(type)) return 'pdf';
  if (DOCX_MIMES.has(type)) return 'docx';
  if (MARKDOWN_MIMES.has(type)) return 'md';
  if (HTML_MIMES.has(type)) return 'html';
  if (IMAGE_MIMES.has(type)) return 'image';
  if (filename) {
    const match = /\.[a-z0-9]+$/i.exec(filename);
    const format = match ? EXTENSIONS[match[0].toLowerCase()] : undefined;
    if (format) return format;
  }
  if (data.subarray(0, 4).toString('latin1') === '%PDF') return 'pdf';
  if (startsWith(data, [0x50, 0x4b, 0x03, 0x04])) {
    const window = Buffer.concat([data.subarray(0, 4096), data.subarray(Math.max(0, data.length - 65_536))]).toString('latin1');
    if (window.includes('word/')) return 'docx';
  }
  if (isImageMagic(data)) return 'image';
  if (TEXT_MIMES.has(type) || type.startsWith('text/') || type === '' || type === 'application/octet-stream') {
    const sniff = data.subarray(0, 2048).toString('latin1').trimStart().toLowerCase();
    if (sniff.startsWith('<!doctype html') || sniff.startsWith('<html')) return 'html';
    return 'txt';
  }
  throw new UnsupportedDocumentError(`unsupported document type: ${type || 'unknown'}`);
}

export function decodeText(data: Buffer): string {
  if (startsWith(data, [0xef, 0xbb, 0xbf])) return data.subarray(3).toString('utf8');
  if (startsWith(data, [0xff, 0xfe])) return new TextDecoder('utf-16le').decode(data.subarray(2));
  if (startsWith(data, [0xfe, 0xff])) return new TextDecoder('utf-16be').decode(data.subarray(2));
  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(data);
  } catch {
    const cp1251 = new TextDecoder('windows-1251').decode(data);
    const letters = [...cp1251].filter((char) => /\p{L}/u.test(char)).length || 1;
    const cyrillic = [...cp1251].filter((char) => char >= 'Ѐ' && char <= 'ӿ').length;
    return cyrillic / letters > 0.3 ? cp1251 : data.toString('latin1');
  }
}

export function imageDimensions(data: Buffer): { width: number; height: number } | null {
  if (startsWith(data, [0x89, 0x50, 0x4e, 0x47]) && data.length >= 24) {
    return { width: data.readUInt32BE(16), height: data.readUInt32BE(20) };
  }
  if (startsWith(data, [0x47, 0x49, 0x46, 0x38]) && data.length >= 10) {
    return { width: data.readUInt16LE(6), height: data.readUInt16LE(8) };
  }
  if (startsWith(data, [0x42, 0x4d]) && data.length >= 26) {
    return { width: Math.abs(data.readInt32LE(18)), height: Math.abs(data.readInt32LE(22)) };
  }
  if (startsWith(data, [0xff, 0xd8])) {
    let offset = 2;
    while (offset + 9 < data.length) {
      if (data[offset] !== 0xff) {
        offset += 1;
        continue;
      }
      const marker = data[offset + 1]!;
      if (marker >= 0xc0 && marker <= 0xcf && ![0xc4, 0xc8, 0xcc].includes(marker)) {
        return { height: data.readUInt16BE(offset + 5), width: data.readUInt16BE(offset + 7) };
      }
      offset += 2 + data.readUInt16BE(offset + 2);
    }
  }
  return null;
}

const HTML_SKIP = new Set(['script', 'style', 'noscript', 'template', 'nav', 'footer', 'aside', 'form', 'svg', 'iframe', 'head', 'title']);
const HTML_BLOCKS = new Set(['p', 'li', 'pre', 'blockquote', 'dt', 'dd', 'figcaption', 'caption', 'div', 'section', 'article', 'main', 'td']);
const HTML_STRUCTURE = new Set([...HTML_BLOCKS, 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'ul', 'ol']);

function isElement(node: ChildNode): node is Element {
  return isTag(node);
}

function nodeText(node: ChildNode): string {
  if (isText(node)) return node.data;
  if (isElement(node)) {
    if (HTML_SKIP.has(node.name)) return '';
    if (node.name === 'br') return ' ';
    return node.children.map(nodeText).join(' ');
  }
  return '';
}

function elementText(element: Element): string {
  return collapseWhitespace(element.children.map(nodeText).join(' '));
}

function hasStructuredDescendant(element: Element): boolean {
  return element.children.some((child) => isElement(child) && (HTML_STRUCTURE.has(child.name) || hasStructuredDescendant(child)));
}

function boldOnly(element: Element): boolean {
  const meaningful = element.children.filter((child) => !(isText(child) && child.data.trim() === ''));
  return meaningful.length > 0 && meaningful.every((child) => isElement(child) && (child.name === 'strong' || child.name === 'b'));
}

export function htmlToBlocks(html: string, options: { boldHeadings?: boolean } = {}): Block[] {
  const document = parseDocument(html, { decodeEntities: true });
  const blocks: Block[] = [];

  const walk = (node: ChildNode, inListItem: boolean): void => {
    if (!isElement(node)) return;
    const name = node.name.toLowerCase();
    if (HTML_SKIP.has(name)) return;
    if (name === 'table') {
      const rows: string[] = [];
      const collectRows = (parent: Element): void => {
        for (const child of parent.children) {
          if (!isElement(child)) continue;
          if (child.name === 'tr') {
            const cells: string[] = [];
            for (const cell of child.children) {
              if (!isElement(cell) || (cell.name !== 'td' && cell.name !== 'th')) continue;
              const value = elementText(cell);
              if (value !== '' && cells[cells.length - 1] !== value) cells.push(value);
            }
            if (cells.length > 0) rows.push(cells.join(' | '));
          } else if (child.name !== 'table') {
            collectRows(child);
          }
        }
      };
      collectRows(node);
      if (rows.length > 0) blocks.push(block(rows.join('\n'), 'table'));
      return;
    }
    const heading = /^h([1-6])$/.exec(name);
    if (heading) {
      const text = elementText(node);
      if (text !== '') blocks.push(block(text, 'heading', Number(heading[1])));
      return;
    }
    const listItem = inListItem || name === 'li';
    if (HTML_BLOCKS.has(name) && !hasStructuredDescendant(node)) {
      const text = elementText(node);
      if (text === '') return;
      if (options.boldHeadings && name === 'p' && text.length < 80 && boldOnly(node)) {
        const level = headingLevel(text);
        if (level > 0) {
          blocks.push(block(text, 'heading', level));
          return;
        }
      }
      const kind: BlockKind = listItem ? 'list' : name === 'pre' ? 'code' : 'paragraph';
      blocks.push(block(text, kind));
      return;
    }
    for (const child of node.children) walk(child, listItem);
  };

  for (const child of document.children) walk(child, false);
  if (blocks.length === 0) {
    const fallback = document.children.map(nodeText).join('\n');
    return linesToBlocks(fallback);
  }
  return blocks;
}

function inlineText(token: Token): string {
  const parts: string[] = [];
  for (const child of token.children ?? []) {
    if (child.type === 'text' || child.type === 'code_inline') parts.push(child.content);
    else if (child.type === 'softbreak' || child.type === 'hardbreak') parts.push(' ');
    else if (child.type === 'image') parts.push(child.content);
  }
  return collapseWhitespace(parts.join(''));
}

export function markdownToBlocks(source: string): Block[] {
  const tokens = new MarkdownIt('commonmark').enable('table').parse(source, {});
  const blocks: Block[] = [];
  let listDepth = 0;
  let row: string[] = [];
  let tableRows: string[] = [];
  let pendingHeading: number | null = null;
  tokens.forEach((token, index) => {
    switch (token.type) {
      case 'heading_open':
        pendingHeading = Number(token.tag.slice(1));
        break;
      case 'bullet_list_open':
      case 'ordered_list_open':
        listDepth += 1;
        break;
      case 'bullet_list_close':
      case 'ordered_list_close':
        listDepth -= 1;
        break;
      case 'tr_open':
        row = [];
        break;
      case 'tr_close':
        if (row.length > 0) tableRows.push(row.join(' | '));
        break;
      case 'table_close':
        if (tableRows.length > 0) blocks.push(block(tableRows.join('\n'), 'table'));
        tableRows = [];
        break;
      case 'fence':
      case 'code_block':
        if (token.content.trim() !== '') blocks.push(block(token.content.trimEnd(), 'code'));
        break;
      case 'inline': {
        const text = inlineText(token);
        const previous = index > 0 ? tokens[index - 1]!.type : '';
        if (previous === 'th_open' || previous === 'td_open') row.push(text);
        else if (pendingHeading !== null) {
          blocks.push(block(text, 'heading', pendingHeading));
          pendingHeading = null;
        } else if (text !== '') blocks.push(block(text, listDepth > 0 ? 'list' : 'paragraph'));
        break;
      }
      default:
        break;
    }
  });
  return blocks;
}

export interface DocumentParserOptions {
  ocr: OcrEngine;
  logger: Logger;
  maxOcrPages?: number;
}

export class DocumentParser {
  private readonly maxOcrPages: number;

  constructor(private readonly options: DocumentParserOptions) {
    this.maxOcrPages = options.maxOcrPages ?? 300;
  }

  async parse(data: Buffer, mime: string | null | undefined, filename?: string | null): Promise<ParsedDocument> {
    if (data.length === 0) throw new DocumentParseError('the file is empty');
    const format = detectFormat(data, mime, filename);
    let parsed: ParsedDocument;
    try {
      parsed = await this.parseFormat(format, data);
    } catch (error) {
      if (error instanceof UnsupportedDocumentError || error instanceof DocumentParseError || error instanceof OcrUnavailableError) throw error;
      if (error instanceof ZipPackageError) throw new DocumentParseError(error.message);
      throw new DocumentParseError(`cannot parse ${format} document: ${error instanceof Error ? error.message : String(error)}`);
    }
    parsed.blocks = parsed.blocks.filter((item) => item.text.trim() !== '');
    return parsed;
  }

  private async parseFormat(format: DocumentFormat, data: Buffer): Promise<ParsedDocument> {
    switch (format) {
      case 'pdf':
        return this.parsePdf(data);
      case 'docx':
        return this.parseDocx(data);
      case 'md':
        return { blocks: markdownToBlocks(decodeText(data)), pages: null, ocrUsed: false, warnings: [] };
      case 'html':
        return { blocks: htmlToBlocks(decodeText(data)), pages: null, ocrUsed: false, warnings: [] };
      case 'image':
        return this.parseImage(data);
      case 'txt':
        return { blocks: linesToBlocks(decodeText(data)), pages: null, ocrUsed: false, warnings: [] };
    }
  }

  private async noteMissingLanguages(warnings: string[]): Promise<void> {
    const missing = await this.options.ocr.missingLanguages();
    if (missing.length > 0) warnings.push(`OCR language data missing: ${missing.join(', ')}; used ${await this.options.ocr.languages()}`);
  }

  private async parsePdf(data: Buffer): Promise<ParsedDocument> {
    const doc: ParsedDocument = { blocks: [], pages: 0, ocrUsed: false, warnings: [] };
    const ocrAvailable = await this.options.ocr.available();
    let extraction: Awaited<ReturnType<typeof extractPdf>>;
    try {
      extraction = await extractPdf(data, { ocrEnabled: ocrAvailable, maxOcrPages: this.maxOcrPages, maxImagePixels: MAX_IMAGE_PIXELS });
    } catch (error) {
      if (error instanceof PdfPasswordError) throw new DocumentParseError(error.message);
      throw error;
    }
    const pages = extraction.pages;
    const skippedPages = extraction.ocrSkippedPages;
    doc.pages = pages.length;
    const needingOcr = pages.filter((page) => page.needsOcr);
    if (needingOcr.length > 0 && !ocrAvailable) {
      doc.warnings.push(`${needingOcr.length} page(s) have no text layer and OCR is unavailable`);
    } else if (needingOcr.length > 0) {
      await this.noteMissingLanguages(doc.warnings);
    }
    for (const page of pages) {
      let text = page.text;
      if (page.needsOcr && page.images.length > 0) {
        const parts: string[] = [];
        for (const image of page.images) parts.push(await this.options.ocr.imageToText(image));
        const ocrText = parts.join('\n');
        if (ocrText.trim().length > text.trim().length) {
          text = ocrText;
          doc.ocrUsed = true;
        }
      }
      doc.blocks.push(...linesToBlocks(text, page.number));
    }
    if (skippedPages > 0) doc.warnings.push(`OCR limited to the first ${this.maxOcrPages} pages without a text layer`);
    if (doc.blocks.length === 0) throw new DocumentParseError('no text could be extracted from the PDF');
    return doc;
  }

  private async parseImage(data: Buffer): Promise<ParsedDocument> {
    if (!(await this.options.ocr.available())) throw new OcrUnavailableError('images need OCR, but Tesseract is not available');
    const size = imageDimensions(data);
    if (size && size.width * size.height > MAX_IMAGE_PIXELS) {
      throw new DocumentParseError(`the image is too large (${size.width}x${size.height} pixels)`);
    }
    const doc: ParsedDocument = { blocks: [], pages: 1, ocrUsed: true, warnings: [] };
    await this.noteMissingLanguages(doc.warnings);
    const text = await this.options.ocr.imageToText(data);
    const pages = text.split('\f').filter((page) => page.trim() !== '');
    doc.pages = Math.max(1, pages.length);
    pages.slice(0, this.maxOcrPages).forEach((page, index) => doc.blocks.push(...linesToBlocks(page, index + 1)));
    if (doc.blocks.length === 0) throw new DocumentParseError('OCR found no text in the image');
    return doc;
  }

  private async parseDocx(data: Buffer): Promise<ParsedDocument> {
    checkZipPackage(data);
    const result = await mammoth.convertToHtml(
      { buffer: data },
      {
        externalFileAccess: false,
        convertImage: mammoth.images.imgElement(async () => ({ src: '' })),
        styleMap: ["p[style-name='Title'] => h1:fresh", "p[style-name='Название'] => h1:fresh", "p[style-name='Заголовок'] => h1:fresh"],
      },
    );
    return { blocks: htmlToBlocks(result.value, { boldHeadings: true }), pages: null, ocrUsed: false, warnings: [] };
  }
}
