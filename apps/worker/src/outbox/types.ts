export interface OutboxRow {
  id: string;
  topic: string;
  payload: unknown;
  workspace_id: number | null;
  attempts: number;
}

export interface OutboxJobData<TPayload = unknown> {
  outbox_id: string;
  topic: string;
  workspace_id: number | null;
  payload: TPayload;
}

export interface DrainResult {
  claimed: number;
  dispatched: number;
  failed: number;
}
