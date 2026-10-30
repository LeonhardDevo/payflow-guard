import { randomBytes } from 'node:crypto';

export function randomHex(bytes: number): string {
  return randomBytes(bytes).toString('hex');
}

export function laravelAppKey(): string {
  return `base64:${randomBytes(32).toString('base64')}`;
}

export function fernetKey(): string {
  return randomBytes(32).toString('base64').replace(/\+/g, '-').replace(/\//g, '_');
}

export function stripeWebhookSecret(): string {
  return `whsec_${randomBytes(24).toString('hex')}`;
}

export function isFernetKey(value: string): boolean {
  if (!/^[A-Za-z0-9_-]{43}=$/.test(value)) return false;
  return Buffer.from(value.replace(/-/g, '+').replace(/_/g, '/'), 'base64').length === 32;
}

export function isLaravelAppKey(value: string): boolean {
  if (!value.startsWith('base64:')) return false;
  return Buffer.from(value.slice('base64:'.length), 'base64').length === 32;
}
