import type { RagDb } from '../db/ragDb.js';

export interface UsageReport {
  days: Array<{ date: string; prompt_tokens: number; completion_tokens: number; cost_usd: number; requests: number }>;
  by_model: Array<{ provider: string; model: string; requests: number; cost_usd: number; prompt_tokens: number; completion_tokens: number }>;
  by_purpose: Array<{ purpose: string; requests: number; cost_usd: number; tokens: number }>;
  total_cost_usd: number;
  total_requests: number;
  errors: number;
}

function isoDay(date: Date): string {
  return date.toISOString().slice(0, 10);
}

export async function usageReport(db: RagDb, days: number, now: Date = new Date()): Promise<UsageReport> {
  const table = db.table('ai_usage');
  const today = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
  const start = new Date(today.getTime() - (days - 1) * 86_400_000);
  const since = start.toISOString();
  const perDay = await db.query<{ day: string; prompt: string; completion: string; cost: string; requests: string }>(
    `SELECT (created_at AT TIME ZONE 'UTC')::date::text AS day,
            COALESCE(SUM(prompt_tokens), 0) AS prompt, COALESCE(SUM(completion_tokens), 0) AS completion,
            COALESCE(SUM(cost_usd), 0) AS cost, COUNT(*) AS requests
     FROM ${table} WHERE created_at >= $1 GROUP BY 1`,
    [since],
  );
  const byModel = await db.query<{ provider: string; model: string; requests: string; cost: string; prompt: string; completion: string }>(
    `SELECT provider, model, COUNT(*) AS requests, COALESCE(SUM(cost_usd), 0) AS cost,
            COALESCE(SUM(prompt_tokens), 0) AS prompt, COALESCE(SUM(completion_tokens), 0) AS completion
     FROM ${table} WHERE created_at >= $1 GROUP BY provider, model ORDER BY COUNT(*) DESC, provider, model`,
    [since],
  );
  const byPurpose = await db.query<{ purpose: string; requests: string; cost: string; tokens: string }>(
    `SELECT purpose, COUNT(*) AS requests, COALESCE(SUM(cost_usd), 0) AS cost,
            COALESCE(SUM(prompt_tokens + completion_tokens), 0) AS tokens
     FROM ${table} WHERE created_at >= $1 GROUP BY purpose ORDER BY COUNT(*) DESC, purpose`,
    [since],
  );
  const errors = await db.query<{ n: string }>(`SELECT COUNT(*) AS n FROM ${table} WHERE created_at >= $1 AND NOT ok`, [since]);

  const byDay = new Map(perDay.rows.map((row) => [row.day, row]));
  const series: UsageReport['days'] = [];
  for (let offset = 0; offset < days; offset++) {
    const day = isoDay(new Date(start.getTime() + offset * 86_400_000));
    const row = byDay.get(day);
    series.push({
      date: day,
      prompt_tokens: Number(row?.prompt ?? 0),
      completion_tokens: Number(row?.completion ?? 0),
      cost_usd: Number(row?.cost ?? 0),
      requests: Number(row?.requests ?? 0),
    });
  }
  return {
    days: series,
    by_model: byModel.rows.map((row) => ({
      provider: row.provider,
      model: row.model,
      requests: Number(row.requests),
      cost_usd: Number(row.cost),
      prompt_tokens: Number(row.prompt),
      completion_tokens: Number(row.completion),
    })),
    by_purpose: byPurpose.rows.map((row) => ({
      purpose: row.purpose,
      requests: Number(row.requests),
      cost_usd: Number(row.cost),
      tokens: Number(row.tokens),
    })),
    total_cost_usd: Math.round(perDay.rows.reduce((sum, row) => sum + Number(row.cost), 0) * 1_000_000) / 1_000_000,
    total_requests: perDay.rows.reduce((sum, row) => sum + Number(row.requests), 0),
    errors: Number(errors.rows[0]?.n ?? 0),
  };
}
