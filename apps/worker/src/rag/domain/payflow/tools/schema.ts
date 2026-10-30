export interface JsonSchema {
  type?: 'object' | 'string' | 'integer' | 'number' | 'boolean';
  properties?: Record<string, JsonSchema>;
  required?: readonly string[];
  additionalProperties?: boolean;
  anyOf?: readonly JsonSchema[];
  enum?: readonly unknown[];
  pattern?: string;
  minLength?: number;
  maxLength?: number;
  minimum?: number;
  maximum?: number;
  default?: unknown;
  description?: string;
}

export type ValidationResult = { ok: true; value: Record<string, unknown> } | { ok: false; errors: string[] };

export function validateArgs(schema: JsonSchema, input: unknown): ValidationResult {
  const errors: string[] = [];
  if (input === null || typeof input !== 'object' || Array.isArray(input)) return { ok: false, errors: ['arguments must be an object'] };
  const source = input as Record<string, unknown>;
  const value: Record<string, unknown> = {};
  const properties = schema.properties ?? {};
  for (const key of Object.keys(source)) {
    if (!Object.hasOwn(properties, key) && schema.additionalProperties === false) errors.push(`${key}: unexpected property`);
  }
  for (const [key, property] of Object.entries(properties)) {
    const raw = source[key];
    if (raw === undefined || raw === null) {
      if (property.default !== undefined) value[key] = property.default;
      continue;
    }
    const error = checkValue(property, raw);
    if (error) errors.push(`${key}: ${error}`);
    else value[key] = typeof raw === 'string' ? raw.trim() : raw;
  }
  for (const key of schema.required ?? []) {
    if (value[key] === undefined) errors.push(`${key}: is required`);
  }
  if (schema.anyOf && !schema.anyOf.some((alternative) => (alternative.required ?? []).every((key) => value[key] !== undefined))) {
    errors.push(`one of ${schema.anyOf.map((alternative) => (alternative.required ?? []).join('+')).join(', ')} is required`);
  }
  return errors.length === 0 ? { ok: true, value } : { ok: false, errors };
}

function checkValue(schema: JsonSchema, value: unknown): string | null {
  if (schema.enum && !schema.enum.includes(value)) return `must be one of ${schema.enum.map(String).join(', ')}`;
  switch (schema.type) {
    case 'string': {
      if (typeof value !== 'string') return 'must be a string';
      const text = value.trim();
      if (schema.minLength !== undefined && text.length < schema.minLength) return `must have at least ${schema.minLength} characters`;
      if (schema.maxLength !== undefined && text.length > schema.maxLength) return `must have at most ${schema.maxLength} characters`;
      if (schema.pattern !== undefined && !new RegExp(schema.pattern, 'u').test(text)) return `must match ${schema.pattern}`;
      return null;
    }
    case 'integer':
    case 'number': {
      if (typeof value !== 'number' || !Number.isFinite(value)) return 'must be a number';
      if (schema.type === 'integer' && !Number.isInteger(value)) return 'must be an integer';
      if (schema.minimum !== undefined && value < schema.minimum) return `must be >= ${schema.minimum}`;
      if (schema.maximum !== undefined && value > schema.maximum) return `must be <= ${schema.maximum}`;
      return null;
    }
    case 'boolean':
      return typeof value === 'boolean' ? null : 'must be a boolean';
    default:
      return null;
  }
}
