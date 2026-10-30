import { getDocumentProxy, getResolvedPDFJS } from 'unpdf';

export const MIN_PAGE_TEXT_CHARS = 25;
const OBJECT_TIMEOUT_MS = 30_000;

export interface PdfPage {
  number: number;
  text: string;
  needsOcr: boolean;
  images: Buffer[];
}

export interface PdfExtraction {
  pages: PdfPage[];
  ocrSkippedPages: number;
}

export interface PdfExtractOptions {
  ocrEnabled: boolean;
  maxOcrPages: number;
  maxImagePixels: number;
}

export class PdfPasswordError extends Error {
  constructor() {
    super('the PDF is password-protected');
    this.name = 'PdfPasswordError';
  }
}

interface DecodedImage {
  width: number;
  height: number;
  kind?: number;
  data?: Uint8Array | Uint8ClampedArray;
}

interface TextItemLike {
  str?: string;
  hasEOL?: boolean;
  transform?: number[];
}

interface ObjStore {
  get(key: string, callback: (value: unknown) => void): unknown;
}

export function encodePnm(image: DecodedImage): Buffer | null {
  const { width, height, data } = image;
  if (!data || width <= 0 || height <= 0) return null;
  const pixels = width * height;
  const rowBytes = Math.ceil(width / 8);
  const kind = image.kind ?? (data.length === pixels * 4 ? 3 : data.length === pixels * 3 ? 2 : data.length === rowBytes * height ? 1 : 0);
  if (kind === 1 && data.length >= rowBytes * height) {
    const body = Buffer.alloc(rowBytes * height);
    for (let index = 0; index < body.length; index++) body[index] = ~data[index]! & 0xff;
    return Buffer.concat([Buffer.from(`P4\n${width} ${height}\n`, 'latin1'), body]);
  }
  if (kind === 2 && data.length >= pixels * 3) {
    return Buffer.concat([Buffer.from(`P6\n${width} ${height}\n255\n`, 'latin1'), Buffer.from(data.buffer, data.byteOffset, pixels * 3)]);
  }
  if (kind === 3 && data.length >= pixels * 4) {
    const body = Buffer.alloc(pixels * 3);
    for (let pixel = 0; pixel < pixels; pixel++) {
      body[pixel * 3] = data[pixel * 4]!;
      body[pixel * 3 + 1] = data[pixel * 4 + 1]!;
      body[pixel * 3 + 2] = data[pixel * 4 + 2]!;
    }
    return Buffer.concat([Buffer.from(`P6\n${width} ${height}\n255\n`, 'latin1'), body]);
  }
  if (data.length === pixels) {
    return Buffer.concat([Buffer.from(`P5\n${width} ${height}\n255\n`, 'latin1'), Buffer.from(data.buffer, data.byteOffset, pixels)]);
  }
  return null;
}

function getObject(store: ObjStore, key: string): Promise<unknown> {
  return new Promise((resolve) => {
    const timer = setTimeout(() => resolve(null), OBJECT_TIMEOUT_MS);
    try {
      store.get(key, (value) => {
        clearTimeout(timer);
        resolve(value);
      });
    } catch {
      clearTimeout(timer);
      resolve(null);
    }
  });
}

function pageText(items: readonly TextItemLike[]): string {
  let text = '';
  let lastY: number | null = null;
  for (const item of items) {
    if (typeof item.str !== 'string') continue;
    const y = item.transform?.[5] ?? null;
    if (lastY !== null && y !== null && Math.abs(y - lastY) > 2 && !text.endsWith('\n')) text += '\n';
    text += item.str;
    if (item.hasEOL) text += '\n';
    if (y !== null) lastY = y;
  }
  return text;
}

export async function extractPdf(data: Buffer, options: PdfExtractOptions): Promise<PdfExtraction> {
  let pdf: Awaited<ReturnType<typeof getDocumentProxy>>;
  try {
    pdf = await getDocumentProxy(new Uint8Array(data), { disableFontFace: true, useSystemFonts: false, verbosity: 0 });
  } catch (error) {
    if ((error as { name?: string } | null)?.name === 'PasswordException') throw new PdfPasswordError();
    throw error;
  }
  const { OPS } = await getResolvedPDFJS();
  const pages: PdfPage[] = [];
  let ocrPages = 0;
  let ocrSkippedPages = 0;
  try {
    for (let number = 1; number <= pdf.numPages; number++) {
      const page = await pdf.getPage(number);
      let text = '';
      try {
        text = pageText((await page.getTextContent()).items as TextItemLike[]);
      } catch {
        text = '';
      }
      const needsOcr = text.trim().length < MIN_PAGE_TEXT_CHARS;
      const images: Buffer[] = [];
      if (needsOcr && options.ocrEnabled) {
        if (ocrPages >= options.maxOcrPages) {
          ocrSkippedPages += 1;
        } else {
          ocrPages += 1;
          const operators = await page.getOperatorList();
          for (let index = 0; index < operators.fnArray.length; index++) {
            const fn = operators.fnArray[index];
            const args = operators.argsArray[index] as unknown[];
            let image: unknown = null;
            if (fn === OPS.paintImageXObject && typeof args[0] === 'string') {
              const key = args[0];
              image = await getObject(key.startsWith('g_') ? page.commonObjs : page.objs, key);
            } else if (fn === OPS.paintInlineImageXObject) {
              image = args[0];
            }
            const decoded = image as DecodedImage | null;
            if (!decoded || !decoded.width || !decoded.height) continue;
            if (decoded.width * decoded.height > options.maxImagePixels) continue;
            if (decoded.width < 32 || decoded.height < 16) continue;
            const encoded = encodePnm(decoded);
            if (encoded) images.push(encoded);
          }
        }
      }
      pages.push({ number, text, needsOcr, images });
      page.cleanup();
    }
  } finally {
    await pdf.loadingTask.destroy().catch(() => undefined);
  }
  return { pages, ocrSkippedPages };
}
