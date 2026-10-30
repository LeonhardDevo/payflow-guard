import { EmbeddingError, l2Normalize, type Embedder } from './types.js';

export interface RemoteEmbedderOptions {
  baseUrl: string;
  model: string;
  apiKey: string | null;
  dims: number;
  batchSize?: number;
  timeoutMs?: number;
  fetch?: typeof fetch;
}

export class RemoteEmbedder implements Embedder {
  readonly dims: number;
  readonly calibration = [0.15, 0.65] as const;
  private readonly fetchImpl: typeof fetch;

  constructor(private readonly options: RemoteEmbedderOptions) {
    this.dims = options.dims;
    this.fetchImpl = options.fetch ?? fetch;
  }

  get modelId(): string {
    return `openai-compatible:${this.options.model}`;
  }

  private async request(inputs: string[]): Promise<number[][]> {
    let response: Response;
    try {
      response = await this.fetchImpl(`${this.options.baseUrl.replace(/\/+$/, '')}/embeddings`, {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          ...(this.options.apiKey ? { authorization: `Bearer ${this.options.apiKey}` } : {}),
        },
        body: JSON.stringify({ model: this.options.model, input: inputs }),
        signal: AbortSignal.timeout(this.options.timeoutMs ?? 60_000),
      });
    } catch (error) {
      throw new EmbeddingError(`embeddings request failed: ${error instanceof Error ? error.message : String(error)}`);
    }
    if (!response.ok) {
      const text = await response.text().catch(() => '');
      throw new EmbeddingError(`embeddings HTTP ${response.status}: ${text.slice(0, 300)}`);
    }
    const body = (await response.json().catch(() => ({}))) as { data?: Array<{ index?: number; embedding?: number[] }> };
    const data = [...(body.data ?? [])].sort((a, b) => (a.index ?? 0) - (b.index ?? 0));
    const vectors = data.map((item) => l2Normalize(item.embedding ?? []));
    if (vectors.length !== inputs.length) throw new EmbeddingError('embeddings response size mismatch');
    for (const vector of vectors) {
      if (vector.length !== this.dims) throw new EmbeddingError(`embedding has ${vector.length} dims, EMBEDDINGS_DIMS is ${this.dims}`);
    }
    return vectors;
  }

  async embedDocuments(texts: readonly string[]): Promise<number[][]> {
    const out: number[][] = [];
    const size = this.options.batchSize ?? 64;
    for (let start = 0; start < texts.length; start += size) out.push(...(await this.request(texts.slice(start, start + size))));
    return out;
  }

  async embedQuery(text: string): Promise<number[]> {
    const [vector] = await this.request([text]);
    return vector!;
  }
}
