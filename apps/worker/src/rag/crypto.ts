import { createCipheriv, createDecipheriv, createHash, createHmac, randomBytes, timingSafeEqual } from 'node:crypto';

const VERSION = 'v1';
const IV_BYTES = 12;
const TAG_BYTES = 16;
const FERNET_VERSION = 0x80;
const FERNET_HEADER_BYTES = 25;
const FERNET_HMAC_BYTES = 32;

export class SecretBoxError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'SecretBoxError';
  }
}

export function deriveKey(material: string): Buffer {
  const trimmed = material.trim();
  if (/^[A-Za-z0-9+/_-]{43}=?$/.test(trimmed)) {
    const decoded = Buffer.from(trimmed.replace(/-/g, '+').replace(/_/g, '/'), 'base64');
    if (decoded.length === 32) return decoded;
  }
  if (/^[0-9a-f]{64}$/i.test(trimmed)) return Buffer.from(trimmed, 'hex');
  return createHash('sha256').update(trimmed, 'utf8').digest();
}

export function fernetKey(material: string): Buffer {
  const trimmed = material.trim();
  if (/^[A-Za-z0-9_-]{43}=?$/.test(trimmed)) {
    const decoded = Buffer.from(trimmed, 'base64url');
    if (decoded.length === 32) return decoded;
  }
  return createHash('sha256').update(trimmed, 'utf8').digest();
}

export function decryptFernet(key: Buffer, token: string): string {
  const raw = Buffer.from(token.trim(), 'base64url');
  if (raw.length < FERNET_HEADER_BYTES + 16 + FERNET_HMAC_BYTES || raw[0] !== FERNET_VERSION) {
    throw new SecretBoxError('stored secret has an unknown format');
  }
  const signed = raw.subarray(0, raw.length - FERNET_HMAC_BYTES);
  const expected = createHmac('sha256', key.subarray(0, 16)).update(signed).digest();
  if (!timingSafeEqual(expected, raw.subarray(raw.length - FERNET_HMAC_BYTES))) {
    throw new SecretBoxError('stored secret cannot be decrypted (AI_SETTINGS_KEY changed?)');
  }
  try {
    const decipher = createDecipheriv('aes-128-cbc', key.subarray(16, 32), raw.subarray(9, FERNET_HEADER_BYTES));
    return Buffer.concat([decipher.update(signed.subarray(FERNET_HEADER_BYTES)), decipher.final()]).toString('utf8');
  } catch {
    throw new SecretBoxError('stored secret cannot be decrypted (AI_SETTINGS_KEY changed?)');
  }
}

export class SecretBox {
  private readonly key: Buffer;
  private readonly legacyKey: Buffer;

  constructor(material: string) {
    if (material.trim() === '') throw new SecretBoxError('AI_SETTINGS_KEY is empty');
    this.key = deriveKey(material);
    this.legacyKey = fernetKey(material);
  }

  encrypt(plaintext: string): string {
    const iv = randomBytes(IV_BYTES);
    const cipher = createCipheriv('aes-256-gcm', this.key, iv);
    cipher.setAAD(Buffer.from(VERSION));
    const ciphertext = Buffer.concat([cipher.update(plaintext, 'utf8'), cipher.final()]);
    const tag = cipher.getAuthTag();
    return `${VERSION}:${Buffer.concat([iv, tag, ciphertext]).toString('base64url')}`;
  }

  decrypt(token: string): string {
    if (token.startsWith('gAAAAA')) return decryptFernet(this.legacyKey, token);
    const [version, payload] = token.split(':', 2);
    if (version !== VERSION || !payload) throw new SecretBoxError('stored secret has an unknown format');
    const raw = Buffer.from(payload, 'base64url');
    if (raw.length < IV_BYTES + TAG_BYTES) throw new SecretBoxError('stored secret is truncated');
    try {
      const decipher = createDecipheriv('aes-256-gcm', this.key, raw.subarray(0, IV_BYTES));
      decipher.setAAD(Buffer.from(VERSION));
      decipher.setAuthTag(raw.subarray(IV_BYTES, IV_BYTES + TAG_BYTES));
      return Buffer.concat([decipher.update(raw.subarray(IV_BYTES + TAG_BYTES)), decipher.final()]).toString('utf8');
    } catch {
      throw new SecretBoxError('stored secret cannot be decrypted (AI_SETTINGS_KEY changed?)');
    }
  }
}

export function secretBoxFor(settingsKey: string | undefined, sharedSecret: string, isProduction: boolean): SecretBox {
  if (settingsKey && settingsKey.trim() !== '') return new SecretBox(settingsKey);
  if (isProduction) throw new SecretBoxError('AI_SETTINGS_KEY must be set in production');
  return new SecretBox(`dev-derived::${sharedSecret}`);
}
