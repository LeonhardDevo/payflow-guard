import type { ChatUsage } from './types.js';

export const PRICES: Readonly<Record<string, readonly [number, number, number]>> = {
  'claude-fable-5-1': [10, 50, 0.25],
  'claude-fable-5': [10, 50, 1],
  'claude-opus-5-5': [4, 20, 0.2],
  'claude-opus-5': [5, 25, 0.5],
  'claude-sonnet-5-5': [2, 10, 0.2],
  'claude-sonnet-5': [2, 10, 0.2],
  'claude-haiku-5-5': [0.1, 0.5, 0.01],
  'claude-haiku-4-5': [1, 5, 0.1],
  'gpt-5-nano': [0.05, 0.4, 0.005],
  'gpt-5-mini': [0.25, 2, 0.025],
  'gpt-5': [1.25, 10, 0.125],
  'deepseek-chat': [0.14, 0.28, 0.0028],
  'deepseek-v4-flash': [0.14, 0.28, 0.0028],
  'deepseek/deepseek-v4-flash': [0.14, 0.28, 0.0028],
};

const ORDERED_KEYS = Object.keys(PRICES).sort((a, b) => b.length - a.length);

export function priceFor(model: string): readonly [number, number, number] | null {
  const lowered = model.toLowerCase();
  if (lowered.endsWith(':free') || lowered.endsWith('-free')) return null;
  const key = ORDERED_KEYS.find((candidate) => lowered.includes(candidate));
  return key ? (PRICES[key] ?? null) : null;
}

export function estimateCostUsd(model: string, usage: ChatUsage): number {
  const price = priceFor(model);
  if (!price) return 0;
  const freshPrompt = Math.max(0, usage.promptTokens - usage.cachedTokens);
  const total = freshPrompt * price[0] + usage.completionTokens * price[1] + usage.cachedTokens * price[2];
  return Math.round((total / 1_000_000) * 1_000_000) / 1_000_000;
}
