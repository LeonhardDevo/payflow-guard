import { mkdir } from 'node:fs/promises';
import { EmbeddingError, l2Normalize, type Embedder } from './types.js';

interface LocalModelSpec {
  dims: number;
  windowWords: number;
  overlapWords: number;
  queryPrefix: string;
  passagePrefix: string;
  calibration: readonly [number, number];
}

const LOCAL_MODEL_SPECS: Readonly<Record<string, LocalModelSpec>> = {
  'Xenova/paraphrase-multilingual-MiniLM-L12-v2': {
    dims: 384,
    windowWords: 64,
    overlapWords: 16,
    queryPrefix: '',
    passagePrefix: '',
    calibration: [0.2, 0.7],
  },
  'Xenova/paraphrase-multilingual-mpnet-base-v2': {
    dims: 768,
    windowWords: 256,
    overlapWords: 32,
    queryPrefix: '',
    passagePrefix: '',
    calibration: [0.2, 0.7],
  },
  'Xenova/multilingual-e5-small': {
    dims: 384,
    windowWords: 300,
    overlapWords: 40,
    queryPrefix: 'query: ',
    passagePrefix: 'passage: ',
    calibration: [0.74, 0.9],
  },
  'Xenova/bge-small-en-v1.5': {
    dims: 384,
    windowWords: 300,
    overlapWords: 40,
    queryPrefix: '',
    passagePrefix: '',
    calibration: [0.45, 0.85],
  },
};

const DEFAULT_SPEC: LocalModelSpec = { dims: 384, windowWords: 200, overlapWords: 30, queryPrefix: '', passagePrefix: '', calibration: [0.2, 0.75] };

export interface FeatureExtractor {
  (texts: string[], options: { pooling: 'mean'; normalize: boolean }): Promise<{ tolist(): number[][] }>;
}

export type ExtractorLoader = (model: string, cacheDir: string) => Promise<FeatureExtractor>;

interface TransformersModule {
  env: { cacheDir: string; allowRemoteModels: boolean };
  pipeline(task: 'feature-extraction', model: string): Promise<FeatureExtractor>;
}

const TRANSFORMERS_PACKAGE = '@huggingface/transformers';

export const loadTransformersExtractor: ExtractorLoader = async (model, cacheDir) => {
  let transformers: TransformersModule;
  try {
    transformers = (await import(TRANSFORMERS_PACKAGE)) as TransformersModule;
  } catch {
    throw new EmbeddingError(`local embeddings need the optional package ${TRANSFORMERS_PACKAGE} (npm install ${TRANSFORMERS_PACKAGE})`);
  }
  await mkdir(cacheDir, { recursive: true });
  transformers.env.cacheDir = cacheDir;
  return transformers.pipeline('feature-extraction', model);
};

export function wordWindows(text: string, size: number, overlap: number): string[] {
  const tokens = text.split(/\s+/).filter(Boolean);
  if (tokens.length <= size) return [text];
  const step = Math.max(1, size - overlap);
  const windows: string[] = [];
  for (let start = 0; start < tokens.length; start += step) {
    windows.push(tokens.slice(start, start + size).join(' '));
    if (start + size >= tokens.length) break;
  }
  return windows;
}

export class LocalEmbedder implements Embedder {
  private readonly spec: LocalModelSpec;
  private extractor: FeatureExtractor | null = null;
  private loading: Promise<FeatureExtractor> | null = null;

  constructor(
    private readonly modelName: string,
    private readonly cacheDir: string,
    private readonly batchSize = 32,
    private readonly loader: ExtractorLoader = loadTransformersExtractor,
  ) {
    this.spec = LOCAL_MODEL_SPECS[modelName] ?? DEFAULT_SPEC;
  }

  get modelId(): string {
    return `local:${this.modelName}`;
  }

  get dims(): number {
    return this.spec.dims;
  }

  get calibration(): readonly [number, number] {
    return this.spec.calibration;
  }

  async load(): Promise<void> {
    if (this.extractor) return;
    this.loading ??= this.loader(this.modelName, this.cacheDir).catch((error: unknown) => {
      this.loading = null;
      if (error instanceof EmbeddingError) throw error;
      throw new EmbeddingError(`cannot load local embedding model ${this.modelName}: ${error instanceof Error ? error.message : String(error)}`);
    });
    this.extractor = await this.loading;
  }

  private async embed(texts: readonly string[], prefix: string): Promise<number[][]> {
    await this.load();
    const windows: string[] = [];
    const owners: number[] = [];
    texts.forEach((text, index) => {
      for (const window of wordWindows(text, this.spec.windowWords, this.spec.overlapWords)) {
        windows.push(prefix + window);
        owners.push(index);
      }
    });
    const sums = texts.map(() => new Array<number>(this.spec.dims).fill(0));
    const counts = texts.map(() => 0);
    try {
      for (let start = 0; start < windows.length; start += this.batchSize) {
        const batch = windows.slice(start, start + this.batchSize);
        const vectors = (await this.extractor!(batch, { pooling: 'mean', normalize: true })).tolist();
        vectors.forEach((vector, offset) => {
          if (vector.length !== this.spec.dims) throw new EmbeddingError(`model returned ${vector.length} dims, expected ${this.spec.dims}`);
          const owner = owners[start + offset]!;
          const acc = sums[owner]!;
          vector.forEach((value, index) => {
            acc[index] = (acc[index] ?? 0) + value;
          });
          counts[owner] = (counts[owner] ?? 0) + 1;
        });
      }
    } catch (error) {
      if (error instanceof EmbeddingError) throw error;
      throw new EmbeddingError(`local embedding failed: ${error instanceof Error ? error.message : String(error)}`);
    }
    return sums.map((acc, index) => l2Normalize(acc.map((value) => value / Math.max(1, counts[index] ?? 0))));
  }

  async embedDocuments(texts: readonly string[]): Promise<number[][]> {
    return texts.length === 0 ? [] : this.embed(texts, this.spec.passagePrefix);
  }

  async embedQuery(text: string): Promise<number[]> {
    const [vector] = await this.embed([text], this.spec.queryPrefix);
    return vector!;
  }
}
