import { RETENTION_DAYS } from '../agent/memory.js';
import type { RagDb } from '../db/ragDb.js';

export interface WorkspacePurgeCounts {
  documents: number;
  chunks: number;
  conversation_turns: number;
  usage_rows: number;
}

export interface WorkspaceRetention {
  workspace_id: number;
  days: number;
}

export class PrivacyPurger {
  constructor(
    private readonly db: RagDb,
    private readonly memoryCapDays = RETENTION_DAYS,
  ) {}

  async purgeWorkspace(workspaceId: number): Promise<WorkspacePurgeCounts> {
    return this.db.transaction(async (client) => {
      const count = async (table: 'documents' | 'chunks' | 'conversation_turns' | 'ai_usage'): Promise<number> => {
        const { rows } = await client.query<{ n: number }>(`SELECT count(*)::int AS n FROM ${this.db.table(table)} WHERE workspace_id = $1`, [
          workspaceId,
        ]);
        return rows[0]?.n ?? 0;
      };
      const counts: WorkspacePurgeCounts = {
        documents: await count('documents'),
        chunks: await count('chunks'),
        conversation_turns: await count('conversation_turns'),
        usage_rows: await count('ai_usage'),
      };
      for (const table of ['chunks', 'documents', 'conversation_turns', 'ai_usage'] as const) {
        await client.query(`DELETE FROM ${this.db.table(table)} WHERE workspace_id = $1`, [workspaceId]);
      }
      return counts;
    });
  }

  async purgeUser(userId: number): Promise<number> {
    const result = await this.db.query(`DELETE FROM ${this.db.table('conversation_turns')} WHERE user_id = $1`, [userId]);
    return result.rowCount ?? 0;
  }

  async pruneMemory(defaultDays: number, overrides: readonly WorkspaceRetention[]): Promise<number> {
    const cap = (days: number): number => Math.min(days, this.memoryCapDays);
    const fallback = cap(defaultDays);
    const special = new Map<number, number>();
    for (const item of overrides) {
      if (cap(item.days) !== fallback) special.set(item.workspace_id, cap(item.days));
    }
    return this.db.transaction(async (client) => {
      const table = this.db.table('conversation_turns');
      const excluded = [...special.keys()];
      const base = await client.query(
        `DELETE FROM ${table} WHERE created_at < now() - make_interval(days => $1::int) AND NOT (workspace_id = ANY($2::bigint[]))`,
        [fallback, excluded],
      );
      let total = base.rowCount ?? 0;
      for (const [workspaceId, days] of special) {
        const result = await client.query(`DELETE FROM ${table} WHERE workspace_id = $1 AND created_at < now() - make_interval(days => $2::int)`, [
          workspaceId,
          days,
        ]);
        total += result.rowCount ?? 0;
      }
      return total;
    });
  }
}
