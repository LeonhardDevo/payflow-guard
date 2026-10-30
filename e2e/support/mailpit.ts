export interface MailSummary {
  ID: string;
  Subject: string;
  To: Array<{ Name: string; Address: string }>;
  Created: string;
}

export interface MailMessage extends MailSummary {
  HTML: string;
  Text: string;
}

export interface WaitForMailOptions {
  to: string;
  subject?: RegExp;
  timeoutMs?: number;
  since?: Date;
}

const decodeEntities = (value: string): string =>
  value
    .replace(/&amp;/g, '&')
    .replace(/&#x2F;/gi, '/')
    .replace(/&#47;/g, '/')
    .replace(/&#x3D;/gi, '=')
    .replace(/&#61;/g, '=')
    .replace(/&quot;/g, '"');

export function extractLinks(html: string, text = ''): string[] {
  const links: string[] = [];
  for (const match of html.matchAll(/href\s*=\s*["']([^"']+)["']/gi)) links.push(decodeEntities(match[1]!));
  for (const match of `${html}\n${text}`.matchAll(/https?:\/\/[^\s"'<>)\]]+/g)) links.push(decodeEntities(match[0]));
  return [...new Set(links.filter((link) => /^https?:\/\//i.test(link)))];
}

export function findLink(message: Pick<MailMessage, 'HTML' | 'Text'>, pattern: RegExp): string | null {
  return extractLinks(message.HTML ?? '', message.Text ?? '').find((link) => pattern.test(link)) ?? null;
}

export class Mailpit {
  readonly baseURL: string;

  constructor(baseURL: string) {
    this.baseURL = baseURL.replace(/\/+$/, '');
  }

  private async get<T>(path: string): Promise<T> {
    const response = await fetch(`${this.baseURL}${path}`, { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error(`Mailpit ${path} → HTTP ${response.status}`);
    return (await response.json()) as T;
  }

  async isUp(): Promise<boolean> {
    try {
      const response = await fetch(`${this.baseURL}/readyz`);
      return response.ok;
    } catch {
      return false;
    }
  }

  async search(to: string): Promise<MailSummary[]> {
    const query = encodeURIComponent(`to:"${to}"`);
    const result = await this.get<{ messages: MailSummary[] | null }>(`/api/v1/search?query=${query}&limit=50`);
    return result.messages ?? [];
  }

  async searchSince(to: string, since: Date): Promise<MailSummary[]> {
    return (await this.search(to)).filter((message) => Date.parse(message.Created) >= since.getTime() - 1_000);
  }

  message(id: string): Promise<MailMessage> {
    return this.get<MailMessage>(`/api/v1/message/${encodeURIComponent(id)}`);
  }

  async waitForMail({ to, subject, timeoutMs = 30_000, since }: WaitForMailOptions): Promise<MailMessage> {
    const deadline = Date.now() + timeoutMs;
    let lastSeen: string[] = [];
    while (Date.now() < deadline) {
      const messages = await this.search(to);
      lastSeen = messages.map((message) => message.Subject);
      const hit = messages.find(
        (message) => (!subject || subject.test(message.Subject)) && (!since || Date.parse(message.Created) >= since.getTime() - 1_000),
      );
      if (hit) return this.message(hit.ID);
      await new Promise((resolve) => setTimeout(resolve, 500));
    }
    throw new Error(
      `No mail to ${to}${subject ? ` matching ${subject}` : ''} within ${timeoutMs} ms` +
        (lastSeen.length ? ` (seen: ${lastSeen.join(' | ')})` : ' — is the worker running and SMTP pointing at Mailpit?'),
    );
  }

  async waitForLink(to: string, pattern: RegExp, timeoutMs?: number): Promise<string> {
    const deadline = Date.now() + (timeoutMs ?? 30_000);
    while (Date.now() < deadline) {
      for (const summary of await this.search(to)) {
        const link = findLink(await this.message(summary.ID), pattern);
        if (link) return link;
      }
      await new Promise((resolve) => setTimeout(resolve, 500));
    }
    throw new Error(`No mail to ${to} contains a link matching ${pattern}`);
  }
}
