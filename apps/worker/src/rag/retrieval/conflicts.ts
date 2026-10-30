import { fold } from '../text.js';

const VERSION_MARKERS =
  /(?<![\p{L}\p{N}_])(?:v|ver|version|rev|revision|версия|ред|редакция|вер)\.?\s*\d+(?:[.-]\d+)*(?![\p{L}\p{N}_])|(?<!\d)\d{1,2}[./]\d{1,2}[./]\d{2,4}(?!\d)|(?<!\d)(?:19|20)\d{2}(?!\d)/giu;
const NON_WORD = /[^\p{L}\p{N}_]+/gu;

export interface HitLike {
  documentId: number;
  title: string;
  department: string | null;
  version: string | null;
  effectiveDate: string | null;
}

export interface Conflict {
  winnerDocumentId: number;
  winnerTitle: string;
  winnerVersion: string | null;
  winnerEffectiveDate: string | null;
  supersededDocumentId: number;
  supersededTitle: string;
  supersededVersion: string | null;
  supersededEffectiveDate: string | null;
}

export function familyKey(title: string, department: string | null): string {
  const cleaned = fold(title).replace(VERSION_MARKERS, ' ').replace(NON_WORD, ' ').trim();
  return `${fold(department ?? '')}::${cleaned}`;
}

type VersionPart = [0, number] | [1, string];

export function versionKey(version: string | null): VersionPart[] {
  if (!version) return [];
  const parts = version.toLowerCase().match(/\d+|\p{L}+/gu) ?? [];
  return parts.map((part) => (/^\d+$/.test(part) ? [0, Number(part)] : [1, part]));
}

function compareVersion(a: VersionPart[], b: VersionPart[]): number {
  for (let index = 0; index < Math.min(a.length, b.length); index++) {
    const left = a[index]!;
    const right = b[index]!;
    if (left[0] !== right[0]) return left[0] - right[0];
    if (left[1] < right[1]) return -1;
    if (left[1] > right[1]) return 1;
  }
  return a.length - b.length;
}

export function compareRecency(a: HitLike, b: HitLike): number {
  const dateA = a.effectiveDate ?? '0000-00-00';
  const dateB = b.effectiveDate ?? '0000-00-00';
  if (dateA !== dateB) return dateA < dateB ? -1 : 1;
  const version = compareVersion(versionKey(a.version), versionKey(b.version));
  if (version !== 0) return version;
  return a.documentId - b.documentId;
}

export function resolveConflicts<T extends HitLike>(hits: readonly T[]): { kept: T[]; conflicts: Conflict[] } {
  const families = new Map<string, T[]>();
  for (const hit of hits) {
    const key = familyKey(hit.title, hit.department);
    const members = families.get(key) ?? [];
    members.push(hit);
    families.set(key, members);
  }
  const superseded = new Set<number>();
  const conflicts: Conflict[] = [];
  for (const members of families.values()) {
    const documents = new Map<number, T>();
    for (const member of members) if (!documents.has(member.documentId)) documents.set(member.documentId, member);
    if (documents.size < 2) continue;
    const ordered = [...documents.values()].sort((a, b) => compareRecency(b, a));
    const winner = ordered[0]!;
    for (const loser of ordered.slice(1)) {
      superseded.add(loser.documentId);
      conflicts.push({
        winnerDocumentId: winner.documentId,
        winnerTitle: winner.title,
        winnerVersion: winner.version,
        winnerEffectiveDate: winner.effectiveDate,
        supersededDocumentId: loser.documentId,
        supersededTitle: loser.title,
        supersededVersion: loser.version,
        supersededEffectiveDate: loser.effectiveDate,
      });
    }
  }
  return { kept: hits.filter((hit) => !superseded.has(hit.documentId)), conflicts };
}
