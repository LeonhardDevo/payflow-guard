import type { Conflict } from '../retrieval/conflicts.js';

export type RagLocale = 'ru' | 'en';

const STRINGS = {
  refusal: {
    en: "I could not find an answer to this question in the approved documents. I won't guess: please ask a specialist, or rephrase the question.",
    ru: 'Я не нашёл ответа на этот вопрос в утверждённых документах. Гадать не буду: обратитесь к специалисту или переформулируйте вопрос.',
  },
  unavailable: {
    en: 'The assistant is temporarily unavailable. Please try again a little later or ask a specialist.',
    ru: 'Ассистент временно недоступен. Попробуйте чуть позже или обратитесь к специалисту.',
  },
  model_refusal: {
    en: "I can't help with this request. Please contact a specialist.",
    ru: 'Я не могу помочь с этим запросом. Обратитесь, пожалуйста, к специалисту.',
  },
  escalate: { en: 'Ask a specialist', ru: 'Спросить специалиста' },
  conflict_title: { en: 'Note: the documents differ.', ru: 'Обратите внимание: документы расходятся.' },
  conflict_line: {
    en: '«{winner}»{winner_meta} supersedes «{loser}»{loser_meta}; the answer follows the newer one.',
    ru: '«{winner}»{winner_meta} заменяет «{loser}»{loser_meta}; ответ дан по более новому документу.',
  },
  effective: { en: 'effective {date}', ru: 'действует с {date}' },
  version: { en: 'version {version}', ru: 'версия {version}' },
  question: { en: 'Question', ru: 'Вопрос' },
  budget_exhausted: {
    en: 'Tool budget exhausted. Answer now using only the sources and tool results above.',
    ru: 'Лимит вызовов инструментов исчерпан. Ответь сейчас, используя только источники и результаты выше.',
  },
} as const;

export type StringKey = keyof typeof STRINGS;

export function normalizeLocale(locale: string | null | undefined): RagLocale {
  return (locale ?? 'en').trim().toLowerCase().replace('_', '-').startsWith('ru') ? 'ru' : 'en';
}

export function t(key: StringKey, locale: RagLocale, params: Record<string, string> = {}): string {
  const template: string = STRINGS[key][locale] ?? STRINGS[key].en;
  return template.replace(/\{(\w+)\}/g, (match, name: string) => params[name] ?? match);
}

function formatDate(value: string, locale: RagLocale): string {
  if (locale !== 'ru') return value;
  const [year, month, day] = value.split('-');
  return year && month && day ? `${day}.${month}.${year}` : value;
}

function meta(version: string | null, effective: string | null, locale: RagLocale): string {
  const parts: string[] = [];
  if (effective) parts.push(t('effective', locale, { date: formatDate(effective, locale) }));
  if (version) parts.push(t('version', locale, { version }));
  return parts.length > 0 ? ` (${parts.join(', ')})` : '';
}

export function conflictNote(conflicts: readonly Conflict[], locale: RagLocale): string {
  const seen = new Set<string>();
  const lines: string[] = [];
  for (const conflict of conflicts) {
    const key = `${conflict.winnerDocumentId}:${conflict.supersededDocumentId}`;
    if (seen.has(key)) continue;
    seen.add(key);
    lines.push(
      `- ${t('conflict_line', locale, {
        winner: conflict.winnerTitle,
        winner_meta: meta(conflict.winnerVersion, conflict.winnerEffectiveDate, locale),
        loser: conflict.supersededTitle,
        loser_meta: meta(conflict.supersededVersion, conflict.supersededEffectiveDate, locale),
      })}`,
    );
  }
  return `> **${t('conflict_title', locale)}**\n>\n${lines.map((line) => `> ${line}`).join('\n')}`;
}
