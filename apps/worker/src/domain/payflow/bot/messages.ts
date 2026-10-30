import en from '../../../i18n/en.json' with { type: 'json' };
import ru from '../../../i18n/ru.json' with { type: 'json' };
import type { Locale, MessageTree } from '../../../i18n/index.js';

const NOTICE_LINE: Record<Locale, string> = {
  en: 'Payout decisions you make here are recorded with channel Telegram in the workspace audit log.',
  ru: 'Решения по выплатам, принятые здесь, записываются в журнал аудита с каналом Telegram.',
};

export const PAYFLOW_MESSAGES: Record<Locale, MessageTree> = {
  en: {
    pf: {
      commands: {
        overdue: 'Most overdue invoices',
        due: 'Invoices due in the next 7 days',
        approvals: 'Payouts waiting for approval',
        changes: 'Bank detail changes to verify',
        client: 'Payment profile of a client (add the client name)',
        remind: 'Send a reminder (add the invoice number)',
        digest: 'Today’s summary',
      },
      errors: {
        forbidden: 'Your role in this workspace does not allow this. Ask an owner or an admin.',
        no_workspace: 'Select a workspace in {product} first, then try again.',
        invalid_button: 'This button is no longer valid. Send /approvals or /overdue for a fresh list.',
      },
      usage: {
        client: 'Add the client name after the command, for example: /client Helios Media',
        remind: 'Add the invoice number after the command, for example: /remind NS-2026-0118',
      },
      reject: {
        prompt: 'Reply to this message with the reason for rejecting {number}.',
        prompt_generic: 'Reply to this message with the reason for rejecting the payout.',
        placeholder: 'Reason for rejecting',
        empty: 'The reason cannot be empty. Tap Reject again to start over.',
      },
      cancelled: 'Cancelled.',
    },
    actions: {
      expired: 'This button has expired. Send /approvals or /overdue for a fresh list.',
    },
    privacy: {
      notice: `${en.privacy.notice}\n\n${NOTICE_LINE.en}`,
    },
  },
  ru: {
    pf: {
      commands: {
        overdue: 'Самые просроченные счета',
        due: 'Счета со сроком оплаты в ближайшие 7 дней',
        approvals: 'Выплаты, ожидающие согласования',
        changes: 'Изменения реквизитов на проверке',
        client: 'Платёжный профиль клиента (укажите название)',
        remind: 'Отправить напоминание (укажите номер счёта)',
        digest: 'Сводка на сегодня',
      },
      errors: {
        forbidden: 'Ваша роль в этом пространстве не позволяет это сделать. Обратитесь к владельцу или администратору.',
        no_workspace: 'Сначала выберите рабочее пространство в {product}, затем повторите.',
        invalid_button: 'Эта кнопка больше не действует. Отправьте /approvals или /overdue, чтобы получить актуальный список.',
      },
      usage: {
        client: 'Укажите название клиента после команды, например: /client Helios Media',
        remind: 'Укажите номер счёта после команды, например: /remind NS-2026-0118',
      },
      reject: {
        prompt: 'Ответьте на это сообщение и укажите причину отклонения {number}.',
        prompt_generic: 'Ответьте на это сообщение и укажите причину отклонения выплаты.',
        placeholder: 'Причина отклонения',
        empty: 'Причина не может быть пустой. Нажмите «Отклонить» ещё раз, чтобы начать заново.',
      },
      cancelled: 'Отменено.',
    },
    actions: {
      expired: 'Срок действия кнопки истёк. Отправьте /approvals или /overdue, чтобы получить актуальный список.',
    },
    privacy: {
      notice: `${ru.privacy.notice}\n\n${NOTICE_LINE.ru}`,
    },
  },
};
