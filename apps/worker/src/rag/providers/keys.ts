import type { Logger } from '../../logger.js';
import { SecretBoxError, type SecretBox } from '../crypto.js';
import { PROVIDER_KEYS_SETTING, type RawSettingsStore } from '../settings/store.js';
import { KEYLESS_PROVIDERS } from './catalog.js';

export type KeySource = 'settings' | 'env' | null;

export interface KeyInfo {
  source: KeySource;
  hint: string | null;
}

const SHARED_KEY_PROVIDERS: Readonly<Record<string, string>> = {
  'opencode-zen': 'opencode-go',
  'opencode-go': 'opencode-zen',
};

export function keyHint(key: string): string {
  return `…${key.slice(-4)}`;
}

export class ProviderKeys {
  constructor(
    private readonly store: RawSettingsStore,
    private readonly envKeys: Readonly<Record<string, string | undefined>>,
    private readonly box: SecretBox,
    private readonly logger: Logger,
  ) {}

  private envKey(providerId: string): string | null {
    const value = this.envKeys[providerId]?.trim();
    return value ? value : null;
  }

  private async storedTokens(): Promise<Record<string, string>> {
    const raw = await this.store.getRaw<Record<string, unknown>>(PROVIDER_KEYS_SETTING, {});
    const tokens: Record<string, string> = {};
    for (const [key, value] of Object.entries(raw ?? {})) {
      if (typeof value === 'string' && value !== '') tokens[key] = value;
    }
    return tokens;
  }

  private decrypt(providerId: string, token: string | undefined): string | null {
    if (!token) return null;
    try {
      const value = this.box.decrypt(token).trim();
      return value === '' ? null : value;
    } catch (error) {
      if (error instanceof SecretBoxError) {
        this.logger.warn({ provider: providerId }, 'stored provider key cannot be decrypted');
        return null;
      }
      throw error;
    }
  }

  private async storedKey(providerId: string): Promise<string | null> {
    const tokens = await this.storedTokens();
    const own = this.decrypt(providerId, tokens[providerId]);
    if (own) return own;
    const sharedWith = SHARED_KEY_PROVIDERS[providerId];
    return sharedWith ? this.decrypt(sharedWith, tokens[sharedWith]) : null;
  }

  async key(providerId: string): Promise<string | null> {
    return (await this.storedKey(providerId)) ?? this.envKey(providerId);
  }

  async info(providerId: string): Promise<KeyInfo> {
    const stored = await this.storedKey(providerId);
    if (stored) return { source: 'settings', hint: keyHint(stored) };
    const env = this.envKey(providerId);
    if (env) return { source: 'env', hint: keyHint(env) };
    return { source: null, hint: null };
  }

  async isConfigured(providerId: string): Promise<boolean> {
    return KEYLESS_PROVIDERS.has(providerId) || (await this.key(providerId)) !== null;
  }

  async setKey(providerId: string, apiKey: string | null): Promise<KeyInfo> {
    const tokens = await this.storedTokens();
    const trimmed = (apiKey ?? '').trim();
    if (trimmed !== '') tokens[providerId] = this.box.encrypt(trimmed);
    else delete tokens[providerId];
    await this.store.setRaw(PROVIDER_KEYS_SETTING, tokens);
    return this.info(providerId);
  }
}
