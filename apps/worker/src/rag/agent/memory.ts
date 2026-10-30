import type { RagDb } from '../db/ragDb.js';

export const MAX_TURN_CHARS = 4000;
export const RETENTION_DAYS = 30;

export interface Turn {
  role: 'user' | 'assistant';
  content: string;
}

export class ConversationMemory {
  constructor(
    private readonly db: RagDb,
    private readonly maxTurns = 6,
  ) {}

  async load(workspaceId: number, conversationId: string | null, userId: number | null): Promise<Turn[]> {
    if (!conversationId || this.maxTurns <= 0) return [];
    const params: unknown[] = [workspaceId, conversationId, RETENTION_DAYS, this.maxTurns];
    let userClause = '';
    if (userId !== null) {
      params.push(userId);
      userClause = 'AND user_id = $5';
    }
    const { rows } = await this.db.query<{ role: string; content: string }>(
      `SELECT role, content FROM ${this.db.table('conversation_turns')}
       WHERE workspace_id = $1 AND conversation_id = $2 AND created_at > now() - make_interval(days => $3::int) ${userClause}
       ORDER BY id DESC LIMIT $4`,
      params,
    );
    const turns: Turn[] = rows.reverse().map((row) => ({ role: row.role === 'user' ? 'user' : 'assistant', content: row.content }));
    while (turns.length > 0 && turns[0]!.role !== 'user') turns.shift();
    return turns;
  }

  async append(workspaceId: number, conversationId: string | null, userId: number | null, question: string, answer: string): Promise<void> {
    if (!conversationId) return;
    await this.db.query(
      `INSERT INTO ${this.db.table('conversation_turns')} (workspace_id, conversation_id, user_id, role, content)
       VALUES ($1, $2, $3, 'user', $4), ($1, $2, $3, 'assistant', $5)`,
      [workspaceId, conversationId, userId, question.slice(0, MAX_TURN_CHARS), answer.slice(0, MAX_TURN_CHARS)],
    );
  }

  async prune(olderThanDays = RETENTION_DAYS): Promise<number> {
    const result = await this.db.query(
      `DELETE FROM ${this.db.table('conversation_turns')} WHERE created_at < now() - make_interval(days => $1::int)`,
      [olderThanDays],
    );
    return result.rowCount ?? 0;
  }
}
