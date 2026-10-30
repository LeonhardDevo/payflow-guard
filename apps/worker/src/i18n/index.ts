import en from './en.json' with { type: 'json' };
import ru from './ru.json' with { type: 'json' };

export const LOCALES = ['ru', 'en'] as const;
export type Locale = (typeof LOCALES)[number];
export const DEFAULT_LOCALE: Locale = 'en';

export type MessageTree = { [key: string]: string | MessageTree };
export type Params = Record<string, string | number>;

export function isLocale(value: unknown): value is Locale {
  return value === 'ru' || value === 'en';
}

export function localeFromLanguageCode(code: string | null | undefined): Locale | null {
  if (!code) return null;
  const base = code.toLowerCase().split(/[-_]/)[0];
  return isLocale(base) ? base : null;
}

export class Translator {
  private readonly catalogs: Record<Locale, MessageTree>;
  private readonly pluralRules: Record<Locale, Intl.PluralRules>;

  constructor(
    private readonly productName: string,
    catalogs: Partial<Record<Locale, MessageTree>> = {},
  ) {
    this.catalogs = {
      ru: deepMerge({}, catalogs.ru ?? ru),
      en: deepMerge({}, catalogs.en ?? en),
    };
    this.pluralRules = { ru: new Intl.PluralRules('ru'), en: new Intl.PluralRules('en') };
  }

  extend(messages: Partial<Record<Locale, MessageTree>>): void {
    for (const locale of LOCALES) {
      const extra = messages[locale];
      if (extra) deepMerge(this.catalogs[locale], extra);
    }
  }

  has(locale: Locale, key: string): boolean {
    return lookup(this.catalogs[locale], key) !== undefined;
  }

  t(locale: Locale, key: string, params: Params = {}): string {
    const node = lookup(this.catalogs[locale], key) ?? lookup(this.catalogs[DEFAULT_LOCALE], key);
    if (node === undefined) return key;
    if (typeof node !== 'string') {
      const count = typeof params.count === 'number' ? params.count : Number(params.count ?? 0);
      return this.plural(locale, key, count, params);
    }
    return this.interpolate(node, params);
  }

  plural(locale: Locale, key: string, count: number, params: Params = {}): string {
    const node = lookup(this.catalogs[locale], key) ?? lookup(this.catalogs[DEFAULT_LOCALE], key);
    if (node === undefined) return key;
    if (typeof node === 'string') return this.interpolate(node, { ...params, count });
    const category = this.pluralRules[locale].select(count);
    const form = node[category] ?? node.other ?? node.many ?? node.one;
    return typeof form === 'string' ? this.interpolate(form, { ...params, count }) : key;
  }

  forLocale(locale: Locale): (key: string, params?: Params) => string {
    return (key, params) => this.t(locale, key, params);
  }

  private interpolate(template: string, params: Params): string {
    const values: Params = { product: this.productName, ...params };
    return template.replace(/\{(\w+)\}/g, (match, name: string) => (name in values ? String(values[name]) : match));
  }
}

function lookup(tree: MessageTree, key: string): string | MessageTree | undefined {
  let node: string | MessageTree | undefined = tree;
  for (const part of key.split('.')) {
    if (node === undefined || typeof node === 'string') return undefined;
    node = node[part];
  }
  return node;
}

function deepMerge(target: MessageTree, source: MessageTree): MessageTree {
  for (const [key, value] of Object.entries(source)) {
    const current = target[key];
    if (typeof value === 'object' && typeof current === 'object') {
      deepMerge(current, value);
    } else if (typeof value === 'object') {
      target[key] = deepMerge({}, value);
    } else {
      target[key] = value;
    }
  }
  return target;
}
