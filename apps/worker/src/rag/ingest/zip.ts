export const MAX_DOCX_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;
export const MAX_DOCX_COMPRESSION_RATIO = 100;
export const MAX_DOCX_ENTRIES = 10_000;

const EOCD_SIGNATURE = 0x06054b50;
const CENTRAL_SIGNATURE = 0x02014b50;

export interface ZipEntryInfo {
  name: string;
  compressedSize: number;
  uncompressedSize: number;
}

export class ZipPackageError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'ZipPackageError';
  }
}

export function readZipEntries(data: Buffer): ZipEntryInfo[] {
  const minimum = Math.max(0, data.length - 65_557);
  let eocd = -1;
  for (let offset = data.length - 22; offset >= minimum; offset--) {
    if (data.readUInt32LE(offset) === EOCD_SIGNATURE) {
      eocd = offset;
      break;
    }
  }
  if (eocd < 0) throw new ZipPackageError('not a valid zip package');
  const total = data.readUInt16LE(eocd + 10);
  const directoryOffset = data.readUInt32LE(eocd + 16);
  if (total === 0xffff || directoryOffset === 0xffffffff) throw new ZipPackageError('zip64 packages are not supported');
  const entries: ZipEntryInfo[] = [];
  let cursor = directoryOffset;
  for (let index = 0; index < total; index++) {
    if (cursor + 46 > data.length || data.readUInt32LE(cursor) !== CENTRAL_SIGNATURE) throw new ZipPackageError('corrupted zip directory');
    const compressedSize = data.readUInt32LE(cursor + 20);
    const uncompressedSize = data.readUInt32LE(cursor + 24);
    const nameLength = data.readUInt16LE(cursor + 28);
    const extraLength = data.readUInt16LE(cursor + 30);
    const commentLength = data.readUInt16LE(cursor + 32);
    const name = data.subarray(cursor + 46, cursor + 46 + nameLength).toString('utf8');
    entries.push({ name, compressedSize, uncompressedSize });
    cursor += 46 + nameLength + extraLength + commentLength;
  }
  return entries;
}

export function checkZipPackage(data: Buffer): ZipEntryInfo[] {
  const entries = readZipEntries(data);
  if (entries.length > MAX_DOCX_ENTRIES) throw new ZipPackageError('the DOCX package has too many entries');
  if (entries.some((entry) => entry.uncompressedSize === 0xffffffff || entry.compressedSize === 0xffffffff)) {
    throw new ZipPackageError('the DOCX package expands beyond the allowed size');
  }
  const uncompressed = entries.reduce((sum, entry) => sum + entry.uncompressedSize, 0);
  const compressed = entries.reduce((sum, entry) => sum + entry.compressedSize, 0);
  if (uncompressed > MAX_DOCX_UNCOMPRESSED_BYTES) throw new ZipPackageError('the DOCX package expands beyond the allowed size');
  if (compressed > 0 && uncompressed / compressed > MAX_DOCX_COMPRESSION_RATIO) {
    throw new ZipPackageError('the DOCX package has a suspicious compression ratio');
  }
  return entries;
}
