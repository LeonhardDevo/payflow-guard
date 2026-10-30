import { parseEnv } from 'node:util';

export type EnvValues = Record<string, string>;

export function parseEnvText(text: string): EnvValues {
  const parsed = parseEnv(text) as Record<string, string | undefined>;
  const values: EnvValues = {};
  for (const [key, value] of Object.entries(parsed)) {
    if (value !== undefined) values[key] = value;
  }
  return values;
}

const SAFE_VALUE = /^[A-Za-z0-9_\-.:/@+=,%~]*$/;

export function formatEnvValue(value: string): string {
  if (SAFE_VALUE.test(value)) return value;
  if (!/["\\$\n]/.test(value)) return `"${value}"`;
  if (!/['\n]/.test(value)) return `'${value}'`;
  return `"${value.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n')}"`;
}

const PLACEHOLDER = /^(?:|null|none|todo|secret|generate.*|x{3,}|<[^>]*>|\$\{[^}]*\}|base64:)$/i;
const PLACEHOLDER_MARKER = /(?:change|replace)[-_ ]?me/i;

export function isPlaceholder(value: string | undefined): boolean {
  if (value === undefined) return true;
  const trimmed = value.trim();
  return PLACEHOLDER.test(trimmed) || PLACEHOLDER_MARKER.test(trimmed);
}

function keyPattern(key: string): RegExp {
  const escaped = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return new RegExp(`^\\s*(?:export\\s+)?${escaped}\\s*=`);
}

export function upsertEnv(text: string, updates: Readonly<Record<string, string>>): string {
  const eol = text.includes('\r\n') ? '\r\n' : '\n';
  const lines = text.length === 0 ? [] : text.split(/\r?\n/);
  const hadTrailingNewline = lines.length > 0 && lines[lines.length - 1] === '';
  if (hadTrailingNewline) lines.pop();

  const pending = new Map(Object.entries(updates));
  const output: string[] = [];
  const written = new Set<string>();

  for (const line of lines) {
    let handled = false;
    for (const [key, value] of pending) {
      if (keyPattern(key).test(line)) {
        if (!written.has(key)) {
          output.push(`${key}=${formatEnvValue(value)}`);
          written.add(key);
        }
        handled = true;
        break;
      }
    }
    if (!handled) output.push(line);
  }

  const missing = [...pending.keys()].filter((key) => !written.has(key));
  if (missing.length > 0) {
    if (output.length > 0 && output[output.length - 1]?.trim() !== '') output.push('');
    for (const key of missing) output.push(`${key}=${formatEnvValue(pending.get(key)!)}`);
  }

  return output.join(eol) + eol;
}
