import { z } from 'zod';
import { EMBEDDING_PROVIDERS } from '../config.js';

const ID_PATTERN = /^[A-Za-z0-9_\-:.]+$/;

function cleanList(values: readonly string[]): string[] {
  const seen = new Set<string>();
  for (const value of values) {
    const cleaned = value.trim();
    if (cleaned !== '') seen.add(cleaned);
  }
  return [...seen];
}

const blankToNull = z
  .string()
  .max(120)
  .nullish()
  .transform((value) => (value && value.trim() !== '' ? value.trim() : null));

export const chatBodySchema = z.object({
  workspace_id: z.number().int().positive(),
  user: z.object({
    id: z.number().int().nullish().transform((value) => value ?? null),
    role: z.string().min(1).max(64),
    locale: z.string().max(16).default('en'),
    permissions: z.array(z.string().max(120)).max(200).default([]),
  }),
  channel: z.enum(['telegram', 'web']),
  conversation_id: z.string().max(120).regex(ID_PATTERN).nullish(),
  message: z
    .string()
    .min(1)
    .max(4000)
    .refine((value) => value.trim() !== '', 'message must not be blank'),
  ai_external_allowed: z.boolean().nullish(),
});

export const searchBodySchema = z.object({
  workspace_id: z.number().int().positive(),
  query: z.string().min(1).max(1000),
  k: z.number().int().min(1).max(50).default(8),
  filters: z
    .object({
      department: blankToNull,
      tags: z.array(z.string().max(120)).max(50).default([]),
      document_ids: z.array(z.number().int().positive()).max(500).default([]),
      roles: z.array(z.string().max(64)).max(50).nullish(),
    })
    .nullish(),
  ai_external_allowed: z.boolean().nullish(),
});

export const ingestBodySchema = z.object({
  workspace_id: z.number().int().positive(),
  title: z
    .string()
    .min(1)
    .max(500)
    .refine((value) => value.trim() !== '', 'title must not be blank'),
  mime: z.string().max(255).default('application/octet-stream'),
  metadata: z
    .object({
      department: blankToNull,
      roles: z.array(z.string().max(64)).max(50).default([]).transform(cleanList),
      tags: z.array(z.string().max(120)).max(50).default([]).transform(cleanList),
      version: z
        .string()
        .max(64)
        .nullish()
        .transform((value) => (value && value.trim() !== '' ? value.trim() : null)),
      effective_date: z
        .string()
        .regex(/^\d{4}-\d{2}-\d{2}/, 'effective_date must be YYYY-MM-DD')
        .nullish()
        .transform((value) => (value ? value.slice(0, 10) : null)),
    })
    .default({ department: null, roles: [], tags: [], version: null, effective_date: null }),
  ai_external_allowed: z.boolean().nullish(),
});

export const reembedBodySchema = z.object({
  ai_external_allowed: z.boolean().nullish(),
});

export const memoryPruneBodySchema = z.object({
  default_days: z.number().int().min(1).max(3650),
  workspaces: z
    .array(z.object({ workspace_id: z.number().int().positive(), days: z.number().int().min(1).max(3650) }))
    .max(100_000)
    .default([]),
});

export const aiSettingsBodySchema = z.object({
  provider: z.string().max(64).nullish(),
  model: z.string().max(200).nullish(),
  fallback_model: z.string().max(200).nullish(),
  temperature: z.number().min(0).max(2).nullish(),
  embeddings: z
    .object({
      provider: z.enum(EMBEDDING_PROVIDERS).nullish(),
      model: z.string().max(200).nullish(),
    })
    .nullish(),
});

export const providerKeyBodySchema = z.object({
  api_key: z.string().max(1000).nullish(),
});

export const workspaceQuerySchema = z.object({
  workspace_id: z.coerce.number().int().positive(),
  limit: z.coerce.number().int().min(1).max(50).default(5),
});

export const usageQuerySchema = z.object({
  days: z.coerce.number().int().min(1).max(366).default(30),
});

export const positiveId = z.coerce.number().int().positive();

export function validationPayload(error: z.ZodError): { detail: string; message: string; errors: Record<string, string[]> } {
  const errors: Record<string, string[]> = {};
  for (const issue of error.issues) {
    const field = issue.path.map(String).join('.') || 'body';
    (errors[field] ??= []).push(issue.message);
  }
  const detail = Object.entries(errors)
    .map(([field, messages]) => `${field}: ${messages[0] ?? 'invalid'}`)
    .join('; ');
  return { detail, message: 'validation failed', errors };
}
