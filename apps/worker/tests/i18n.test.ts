import { describe, expect, it } from 'vitest';
import en from '../src/i18n/en.json' with { type: 'json' };
import ru from '../src/i18n/ru.json' with { type: 'json' };
import { Translator, localeFromLanguageCode, type MessageTree } from '../src/i18n/index.js';

function leafKeys(tree: MessageTree, prefix = ''): string[] {
  return Object.entries(tree).flatMap(([key, value]) => {
    const path = prefix ? `${prefix}.${key}` : key;
    if (typeof value === 'string') return [path];
    if (Object.keys(value).some((k) => ['one', 'few', 'many', 'other'].includes(k))) return [path];
    return leafKeys(value, path);
  });
}

function placeholders(text: string): string[] {
  return [...text.matchAll(/\{(\w+)\}/g)].map((match) => match[1] ?? '').sort();
}

describe('bot i18n', () => {
  it('has the same keys in ru and en', () => {
    expect(leafKeys(ru as MessageTree).sort()).toEqual(leafKeys(en as MessageTree).sort());
  });

  it('uses the same placeholders in both locales', () => {
    const translator = new Translator('X');
    for (const key of leafKeys(en)) {
      const enText = translator.t('en', key, { count: 5 });
      const ruText = translator.t('ru', key, { count: 5 });
      expect(placeholders(ruText), key).toEqual(placeholders(enText));
    }
  });

  it('never hard-codes a product name', () => {
    const raw = JSON.stringify(en) + JSON.stringify(ru);
    expect(raw).not.toMatch(/payflow|opsbot|scampause/i);
  });

  it('fills {product} and params', () => {
    const translator = new Translator('Acme Desk');
    expect(translator.t('en', 'start.linked', { name: 'Ann' })).toContain('Acme Desk as <b>Ann</b>');
    expect(translator.t('ru', 'help.title')).toBe('<b>Ассистент Acme Desk</b>');
  });

  it('uses correct Russian plural forms', () => {
    const translator = new Translator('X');
    expect(translator.plural('ru', 'assistant.sources', 1)).toBe('<b>1 источник</b>');
    expect(translator.plural('ru', 'assistant.sources', 2)).toBe('<b>2 источника</b>');
    expect(translator.plural('ru', 'assistant.sources', 5)).toBe('<b>5 источников</b>');
    expect(translator.plural('ru', 'assistant.sources', 21)).toBe('<b>21 источник</b>');
    expect(translator.plural('ru', 'assistant.sources', 11)).toBe('<b>11 источников</b>');
    expect(translator.plural('en', 'assistant.sources', 1)).toBe('<b>Source</b>');
    expect(translator.plural('en', 'assistant.sources', 3)).toBe('<b>Sources (3)</b>');
  });

  it('falls back to English, then to the key', () => {
    const translator = new Translator('X', { ru: { only: { ru: 'да' } }, en: { only: { en: 'yes' } } });
    expect(translator.t('ru', 'only.en')).toBe('yes');
    expect(translator.t('en', 'missing.key')).toBe('missing.key');
  });

  it('lets modules extend catalogs', () => {
    const translator = new Translator('X');
    translator.extend({ en: { invoices: { approved: 'Invoice {number} approved' } }, ru: { invoices: { approved: 'Счёт {number} согласован' } } });
    expect(translator.t('ru', 'invoices.approved', { number: 'A-1' })).toBe('Счёт A-1 согласован');
    expect(translator.t('en', 'common.error')).toContain('Something went wrong');
  });

  it('maps Telegram language codes', () => {
    expect(localeFromLanguageCode('ru')).toBe('ru');
    expect(localeFromLanguageCode('ru-RU')).toBe('ru');
    expect(localeFromLanguageCode('en-GB')).toBe('en');
    expect(localeFromLanguageCode('de')).toBeNull();
    expect(localeFromLanguageCode(undefined)).toBeNull();
  });
});
