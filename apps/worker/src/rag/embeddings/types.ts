export class EmbeddingError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'EmbeddingError';
  }
}

export interface Embedder {
  readonly modelId: string;
  readonly dims: number;
  readonly calibration: readonly [number, number];
  embedDocuments(texts: readonly string[]): Promise<number[][]>;
  embedQuery(text: string): Promise<number[]>;
}

export function l2Normalize(vector: readonly number[]): number[] {
  const norm = Math.sqrt(vector.reduce((sum, value) => sum + value * value, 0));
  if (norm === 0) return vector.map(() => 0);
  return vector.map((value) => value / norm);
}

export function calibrate(similarity: number, calibration: readonly [number, number]): number {
  const [floor, ceiling] = calibration;
  if (ceiling <= floor) return Math.max(0, Math.min(1, similarity));
  return Math.max(0, Math.min(1, (similarity - floor) / (ceiling - floor)));
}
