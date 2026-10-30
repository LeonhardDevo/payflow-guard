import { BlockList, isIP } from 'node:net';

export function normalizeIp(address: string): string {
  const trimmed = address.trim();
  return trimmed.toLowerCase().startsWith('::ffff:') && isIP(trimmed.slice(7)) === 4 ? trimmed.slice(7) : trimmed;
}

export function createAllowlist(entries: readonly string[]): (address: string) => boolean {
  const list = new BlockList();
  for (const entry of entries) {
    const [network = '', prefixText] = entry.split('/');
    const family = isIP(network);
    if (family === 0) throw new Error(`Invalid allowlist entry: ${entry}`);
    const type = family === 4 ? 'ipv4' : 'ipv6';
    const prefix = prefixText === undefined ? (family === 4 ? 32 : 128) : Number(prefixText);
    if (!Number.isInteger(prefix) || prefix < 0 || prefix > (family === 4 ? 32 : 128)) throw new Error(`Invalid allowlist entry: ${entry}`);
    list.addSubnet(network, prefix, type);
  }
  return (address: string) => {
    const ip = normalizeIp(address);
    const family = isIP(ip);
    if (family === 0) return false;
    return list.check(ip, family === 4 ? 'ipv4' : 'ipv6');
  };
}

export function clientIp(socketAddress: string | undefined, forwardedFor: string | string[] | undefined, trustedHops: number): string {
  const header = Array.isArray(forwardedFor) ? forwardedFor.join(',') : (forwardedFor ?? '');
  const forwarded = header
    .split(',')
    .map((part) => part.trim())
    .filter((part) => part !== '')
    .reverse();
  const chain = [socketAddress ?? '', ...forwarded];
  return normalizeIp(chain[Math.min(trustedHops, chain.length - 1)] ?? '');
}
