import { Counter, Histogram, Registry } from 'prom-client';

export class RagMetrics {
  readonly llmCalls: Counter<'provider' | 'purpose' | 'status'>;
  readonly llmTokens: Counter<'provider' | 'kind'>;
  readonly chatAnswers: Counter<'channel' | 'refused' | 'degraded'>;
  readonly ingestResults: Counter<'status' | 'ocr'>;
  readonly ingestDuration: Histogram;
  readonly retrievalDuration: Histogram;

  constructor(registry: Registry = new Registry(), prefix = 'worker_rag_') {
    this.llmCalls = new Counter({
      name: `${prefix}llm_calls_total`,
      help: 'LLM calls by provider, purpose and outcome.',
      labelNames: ['provider', 'purpose', 'status'],
      registers: [registry],
    });
    this.llmTokens = new Counter({
      name: `${prefix}llm_tokens_total`,
      help: 'LLM tokens by provider and kind.',
      labelNames: ['provider', 'kind'],
      registers: [registry],
    });
    this.chatAnswers = new Counter({
      name: `${prefix}chat_answers_total`,
      help: 'Assistant answers by channel, refusal and degraded mode.',
      labelNames: ['channel', 'refused', 'degraded'],
      registers: [registry],
    });
    this.ingestResults = new Counter({
      name: `${prefix}ingest_total`,
      help: 'Document ingest results.',
      labelNames: ['status', 'ocr'],
      registers: [registry],
    });
    this.ingestDuration = new Histogram({
      name: `${prefix}ingest_duration_seconds`,
      help: 'Document ingest duration in seconds.',
      buckets: [0.1, 0.5, 1, 2.5, 5, 10, 30, 60, 180, 600],
      registers: [registry],
    });
    this.retrievalDuration = new Histogram({
      name: `${prefix}retrieval_duration_seconds`,
      help: 'Hybrid retrieval duration in seconds.',
      buckets: [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5],
      registers: [registry],
    });
  }
}
