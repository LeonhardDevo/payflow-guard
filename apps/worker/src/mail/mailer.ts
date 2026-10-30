import nodemailer, { type Transporter } from 'nodemailer';

export type MailAddress = string | { name: string; address: string };

export interface MailAttachment {
  filename: string;
  contentType: string;
  content: Buffer;
}

export interface OutgoingMail {
  from: MailAddress;
  to: MailAddress | MailAddress[];
  cc?: MailAddress[];
  subject: string;
  html?: string;
  text?: string;
  replyTo?: MailAddress;
  attachments?: MailAttachment[];
  messageId: string;
  headers?: Record<string, string>;
}

export interface Mailer {
  send(mail: OutgoingMail): Promise<{ messageId: string }>;
  verify(): Promise<boolean>;
  close(): void;
}

export interface SmtpSettings {
  host: string;
  port: number;
  secure: boolean;
  user?: string;
  password?: string;
}

export class SmtpMailer implements Mailer {
  private readonly transporter: Transporter;

  constructor(settings: SmtpSettings) {
    this.transporter = nodemailer.createTransport({
      host: settings.host,
      port: settings.port,
      secure: settings.secure,
      ...(settings.user ? { auth: { user: settings.user, pass: settings.password ?? '' } } : {}),
      connectionTimeout: 10_000,
      greetingTimeout: 10_000,
      socketTimeout: 30_000,
    });
  }

  async send(mail: OutgoingMail): Promise<{ messageId: string }> {
    const info = (await this.transporter.sendMail({
      from: mail.from,
      to: mail.to,
      cc: mail.cc,
      subject: mail.subject,
      html: mail.html,
      text: mail.text,
      replyTo: mail.replyTo,
      attachments: mail.attachments,
      messageId: mail.messageId,
      headers: mail.headers,
    })) as { messageId?: string };
    return { messageId: info.messageId ?? mail.messageId };
  }

  async verify(): Promise<boolean> {
    try {
      await this.transporter.verify();
      return true;
    } catch {
      return false;
    }
  }

  close(): void {
    this.transporter.close();
  }
}

export function isPermanentSmtpError(error: unknown): boolean {
  const responseCode = (error as { responseCode?: unknown } | null)?.responseCode;
  const code = (error as { code?: unknown } | null)?.code;
  if (typeof responseCode === 'number' && responseCode >= 500) return true;
  return code === 'EENVELOPE' || code === 'EMESSAGE';
}
