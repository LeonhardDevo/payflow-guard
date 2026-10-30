import { GrammyError, type Composer, type InlineKeyboard } from 'grammy';
import { escapeHtml, toTelegramHtml } from '../../../bot/format.js';
import { renderButtons, replyHtml, replyUnlinked, withLines, type ButtonOwner } from '../../../bot/helpers.js';
import type { KeyValueCache, StoredAction } from '../../../bot/stores.js';
import type { ActionHandler, BotCommand, BotContext, BotModule } from '../../../bot/types.js';
import type { TelegramContext } from '../../../clients/web.js';
import { errorFields } from '../../../logger.js';
import { isNotLinked, type BotResult, type BotView, type BotViewName, type PayflowApi } from '../api.js';
import { PAYFLOW_MESSAGES } from './messages.js';

export const REPLY_TTL_S = 600;
export const MAX_COMMENT_LENGTH = 500;
export const INVOICE_NUMBER_RE = /^[A-Za-zА-Яа-яЁё]{1,5}-\d{4}-\d{3,5}$/u;
const FP8_RE = /^[0-9a-f]{8}$/;

export const PF_ACTIONS = {
  approve: 'pf.pa',
  approveConfirm: 'pf.pac',
  reject: 'pf.pr',
  remind: 'pf.rm',
  remindConfirm: 'pf.rmc',
  remindForce: 'pf.rmf',
  client: 'pf.cl',
  acknowledge: 'pf.ack',
  cancel: 'pf.x',
} as const;

interface CommandSpec {
  command: string;
  permission?: string;
  view?: BotViewName;
  argument?: 'client' | 'invoice';
}

const COMMANDS: readonly CommandSpec[] = [
  { command: 'overdue', permission: 'invoices.view', view: 'overdue' },
  { command: 'due', permission: 'invoices.view', view: 'due' },
  { command: 'approvals', permission: 'payouts.view', view: 'approvals' },
  { command: 'changes', permission: 'vendors.view', view: 'changes' },
  { command: 'client', permission: 'clients.view', view: 'client', argument: 'client' },
  { command: 'remind', permission: 'reminders.send', argument: 'invoice' },
  { command: 'digest', view: 'digest' },
];

export interface PendingReply {
  kind: 'reject';
  payout_id: number;
  fp8: string;
}

export interface PayflowModuleDeps {
  api: PayflowApi;
  cache: KeyValueCache;
}

export function replyKey(chatId: number): string {
  return `pf:reply:${chatId}`;
}

export function createPayflowModule(deps: PayflowModuleDeps): BotModule {
  const bot = new PayflowBot(deps);
  const commands: BotCommand[] = COMMANDS.map((spec) => ({
    command: spec.command,
    descriptionKey: `pf.commands.${spec.command}`,
    ...(spec.permission ? { permission: spec.permission } : {}),
  }));
  const actions: Record<string, ActionHandler> = {
    [PF_ACTIONS.approve]: (ctx, action) => bot.decide(ctx, action, false),
    [PF_ACTIONS.approveConfirm]: (ctx, action) => bot.decide(ctx, action, true),
    [PF_ACTIONS.reject]: (ctx, action) => bot.askRejectReason(ctx, action),
    [PF_ACTIONS.remind]: (ctx, action) => bot.remindAction(ctx, action, { confirmed: false }),
    [PF_ACTIONS.remindConfirm]: (ctx, action) => bot.remindAction(ctx, action, { confirmed: true }),
    [PF_ACTIONS.remindForce]: (ctx, action) => bot.remindAction(ctx, action, { confirmed: true, force: true }),
    [PF_ACTIONS.client]: (ctx, action) => bot.clientAction(ctx, action),
    [PF_ACTIONS.acknowledge]: (ctx, action) => bot.acknowledge(ctx, action),
    [PF_ACTIONS.cancel]: (ctx) => bot.cancel(ctx),
  };
  return {
    name: 'payflow',
    commands,
    messages: PAYFLOW_MESSAGES,
    actions,
    register(composer: Composer<BotContext>) {
      for (const spec of COMMANDS) composer.command(spec.command, (ctx) => bot.command(ctx, spec));
      composer.on('message:text', async (ctx, next) => {
        if (!(await bot.consumeReply(ctx))) await next();
      });
    },
  };
}

class PayflowBot {
  constructor(private readonly deps: PayflowModuleDeps) {}

  async command(ctx: BotContext, spec: CommandSpec): Promise<void> {
    const account = await this.account(ctx);
    if (!account) return;
    if (spec.permission && !hasPermission(account, spec.permission)) {
      await replyHtml(ctx, ctx.t('pf.errors.forbidden'));
      return;
    }
    const argument = typeof ctx.match === 'string' ? ctx.match.trim().slice(0, 120) : '';
    if (spec.argument === 'client' && argument.length < 2) {
      await replyHtml(ctx, escapeHtml(ctx.t('pf.usage.client')));
      return;
    }
    if (spec.argument === 'invoice' && !INVOICE_NUMBER_RE.test(argument)) {
      await replyHtml(ctx, escapeHtml(ctx.t('pf.usage.remind')));
      return;
    }
    const from = ctx.from;
    if (!from) return;
    const result = await this.call(ctx, () =>
      spec.argument === 'invoice'
        ? this.deps.api.remindInvoice({ telegram_user_id: from.id, invoice: argument.toUpperCase(), confirmed: false }, ctx.requestId)
        : this.deps.api.botView(spec.view ?? 'digest', from.id, spec.argument === 'client' ? argument : null, ctx.requestId),
    );
    if (result) await this.show(ctx, result, account, 'reply');
  }

  async decide(ctx: BotContext, action: StoredAction, confirmed: boolean): Promise<void> {
    const target = payoutArg(action);
    const from = ctx.from;
    if (!target || !from) {
      await this.invalidButton(ctx);
      return;
    }
    const result = await this.call(ctx, () =>
      this.deps.api.payoutDecision(target.payoutId, { telegram_user_id: from.id, decision: 'approve', fp8: target.fp8, confirmed }, ctx.requestId),
    );
    if (result) await this.show(ctx, result, null, 'edit', action);
  }

  async askRejectReason(ctx: BotContext, action: StoredAction): Promise<void> {
    const target = payoutArg(action);
    const chat = ctx.chat;
    const from = ctx.from;
    if (!target || !chat || !from) {
      await this.invalidButton(ctx);
      return;
    }
    const result = await this.call(ctx, () =>
      this.deps.api.payoutDecision(target.payoutId, { telegram_user_id: from.id, decision: 'reject', fp8: target.fp8, confirmed: false }, ctx.requestId),
    );
    if (!result) return;
    if (isNotLinked(result) || result.code !== 'reason_required') {
      await this.show(ctx, result, null, 'edit', action);
      return;
    }
    const pending: PendingReply = { kind: 'reject', payout_id: target.payoutId, fp8: target.fp8 };
    await this.deps.cache.set(replyKey(chat.id), JSON.stringify(pending), REPLY_TTL_S);
    const number = result.number;
    const prompt = number ? ctx.t('pf.reject.prompt', { number }) : ctx.t('pf.reject.prompt_generic');
    await ctx.reply(escapeHtml(prompt), {
      parse_mode: 'HTML',
      reply_markup: { force_reply: true, selective: true, input_field_placeholder: ctx.t('pf.reject.placeholder') },
    });
  }

  async consumeReply(ctx: BotContext): Promise<boolean> {
    const chat = ctx.chat;
    const from = ctx.from;
    const text = ctx.message?.text;
    if (!chat || !from || text === undefined || text.startsWith('/')) return false;
    const key = replyKey(chat.id);
    const raw = await this.deps.cache.get(key).catch(() => null);
    if (raw === null) return false;
    const pending = parsePending(raw);
    await this.deps.cache.del(key).catch(() => undefined);
    if (!pending) return false;
    const comment = text.trim().slice(0, MAX_COMMENT_LENGTH);
    if (comment === '') {
      await replyHtml(ctx, escapeHtml(ctx.t('pf.reject.empty')));
      return true;
    }
    const result = await this.call(ctx, () =>
      this.deps.api.payoutDecision(
        pending.payout_id,
        { telegram_user_id: from.id, decision: 'reject', fp8: pending.fp8, comment, confirmed: true },
        ctx.requestId,
      ),
    );
    if (result) await this.show(ctx, result, null, 'reply');
    return true;
  }

  async remindAction(ctx: BotContext, action: StoredAction, options: { confirmed: boolean; force?: boolean }): Promise<void> {
    const invoiceId = positiveInt(action.payload.arg);
    const from = ctx.from;
    if (invoiceId === null || !from) {
      await this.invalidButton(ctx);
      return;
    }
    const result = await this.call(ctx, () =>
      this.deps.api.remindInvoice(
        { telegram_user_id: from.id, invoice_id: invoiceId, confirmed: options.confirmed, ...(options.force ? { force: true } : {}) },
        ctx.requestId,
      ),
    );
    if (result) await this.show(ctx, result, null, 'edit', action);
  }

  async clientAction(ctx: BotContext, action: StoredAction): Promise<void> {
    const clientId = positiveInt(action.payload.arg);
    const from = ctx.from;
    if (clientId === null || !from) {
      await this.invalidButton(ctx);
      return;
    }
    const result = await this.call(ctx, () => this.deps.api.botView('client', from.id, String(clientId), ctx.requestId));
    if (result) await this.show(ctx, result, null, 'edit', action);
  }

  async acknowledge(ctx: BotContext, action: StoredAction): Promise<void> {
    const alertId = positiveInt(action.payload.arg);
    const from = ctx.from;
    if (alertId === null || !from) {
      await this.invalidButton(ctx);
      return;
    }
    const result = await this.call(ctx, () => this.deps.api.acknowledgeAlert(alertId, from.id, ctx.requestId));
    if (result) await this.show(ctx, result, null, 'edit', action);
  }

  async cancel(ctx: BotContext): Promise<void> {
    await this.edit(ctx, escapeHtml(ctx.t('pf.cancelled')), undefined);
  }

  private async account(ctx: BotContext): Promise<TelegramContext | null> {
    let account: TelegramContext | null;
    try {
      account = await ctx.account();
    } catch (error) {
      ctx.log.warn(errorFields(error), 'telegram context lookup failed');
      await replyHtml(ctx, ctx.t('common.unavailable'));
      return null;
    }
    if (!account) {
      await replyUnlinked(ctx);
      return null;
    }
    if (!account.workspace) {
      await replyHtml(ctx, ctx.t('pf.errors.no_workspace'));
      return null;
    }
    return account;
  }

  private async call(ctx: BotContext, fn: () => Promise<BotResult>): Promise<BotResult | null> {
    try {
      return await fn();
    } catch (error) {
      ctx.log.warn(errorFields(error), 'payflow bot request failed');
      if (ctx.callbackQuery) await this.edit(ctx, ctx.t('common.unavailable'), undefined, true);
      else await replyHtml(ctx, ctx.t('common.unavailable'));
      return null;
    }
  }

  private async show(ctx: BotContext, result: BotResult, account: TelegramContext | null, mode: 'reply' | 'edit', action?: StoredAction): Promise<void> {
    if (isNotLinked(result)) {
      await replyUnlinked(ctx);
      return;
    }
    const view = result;
    const owner: ButtonOwner = {
      telegramUserId: ctx.from?.id ?? null,
      chatId: ctx.chat?.id ?? null,
      workspaceId: account?.workspace?.id ?? action?.workspace_id ?? null,
      userId: account?.user.id ?? action?.user_id ?? null,
      locale: ctx.locale,
    };
    const { html, keyboard } = await renderView(ctx, view, owner);
    if (mode === 'edit') await this.edit(ctx, html, keyboard);
    else await replyHtml(ctx, html, keyboard);
  }

  private async edit(ctx: BotContext, html: string, keyboard: InlineKeyboard | undefined, keepMarkup = false): Promise<void> {
    if (!ctx.callbackQuery?.message) {
      await replyHtml(ctx, html, keyboard);
      return;
    }
    try {
      await ctx.editMessageText(html, {
        parse_mode: 'HTML',
        link_preview_options: { is_disabled: true },
        ...(keyboard ? { reply_markup: keyboard } : keepMarkup ? {} : { reply_markup: { inline_keyboard: [] } }),
      });
    } catch (error) {
      if (!(error instanceof GrammyError && error.error_code === 400)) throw error;
      if (/not modified/i.test(error.description)) return;
      await replyHtml(ctx, html, keyboard);
    }
  }

  private async invalidButton(ctx: BotContext): Promise<void> {
    await this.edit(ctx, escapeHtml(ctx.t('pf.errors.invalid_button')), undefined);
  }
}

export async function renderView(
  ctx: BotContext,
  view: BotView,
  owner: ButtonOwner,
): Promise<{ html: string; keyboard: InlineKeyboard | undefined }> {
  const buttons = view.buttons.map((button) => ({
    text: button.text,
    ...(button.url ? { url: button.url } : {}),
    ...(button.callback ? { callback: button.callback } : {}),
  }));
  const rendered = await renderButtons(buttons, owner, ctx.services.actions, ctx.services.settings.actionTtlS);
  const html = toTelegramHtml(view.text_html);
  return { html: withLines(html === '' ? escapeHtml(ctx.t('common.error')) : html, rendered.lines), keyboard: rendered.keyboard };
}

function hasPermission(account: TelegramContext, permission: string): boolean {
  return account.permissions.includes(permission) || account.permissions.includes('*');
}

export function payoutArg(action: StoredAction): { payoutId: number; fp8: string } | null {
  const arg = typeof action.payload.arg === 'string' ? action.payload.arg : '';
  const [rawId, fp8] = arg.split(':');
  const payoutId = positiveInt(rawId);
  if (payoutId === null || fp8 === undefined || !FP8_RE.test(fp8)) return null;
  return { payoutId, fp8 };
}

function positiveInt(value: unknown): number | null {
  if (typeof value !== 'string' && typeof value !== 'number') return null;
  const text = String(value);
  if (!/^\d{1,15}$/.test(text)) return null;
  const parsed = Number(text);
  return parsed > 0 ? parsed : null;
}

function parsePending(raw: string): PendingReply | null {
  try {
    const value = JSON.parse(raw) as Partial<PendingReply>;
    if (value.kind === 'reject' && typeof value.payout_id === 'number' && typeof value.fp8 === 'string' && FP8_RE.test(value.fp8)) {
      return { kind: 'reject', payout_id: value.payout_id, fp8: value.fp8 };
    }
  } catch {
    return null;
  }
  return null;
}
