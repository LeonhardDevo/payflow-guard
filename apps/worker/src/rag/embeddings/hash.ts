import { createHash } from 'node:crypto';
import { contentTerms } from '../text.js';
import { l2Normalize, type Embedder } from './types.js';

export const HASH_MODEL = 'hash-384';

export class HashEmbedder implements Embedder {
  readonly dims: number;
  readonly calibration = [0.05, 0.55] as const;

  constructor(dims = 384) {
    this.dims = dims;
  }

  get modelId(): string {
    return `hash:${this.dims === 384 ? HASH_MODEL : `hash-${this.dims}`}`;
  }

  vector(text: string): number[] {
    const vector = new Array<number>(this.dims).fill(0);
    const terms = contentTerms(text);
    const features: Array<[string, number]> = terms.map((term) => [term, 1]);
    for (let index = 1; index < terms.length; index++) features.push([`${terms[index - 1]}_${terms[index]}`, 0.5]);
    for (const [feature, weight] of features) {
      const digest = createHash('sha256').update(feature, 'utf8').digest();
      const slot = digest.readUInt32LE(0) % this.dims;
      const sign = (digest[4]! & 1) === 1 ? 1 : -1;
      vector[slot] = (vector[slot] ?? 0) + sign * weight;
    }
    return l2Normalize(vector);
  }

  async embedDocuments(texts: readonly string[]): Promise<number[][]> {
    return texts.map((text) => this.vector(text));
  }

  async embedQuery(text: string): Promise<number[]> {
    return this.vector(text);
  }
}
