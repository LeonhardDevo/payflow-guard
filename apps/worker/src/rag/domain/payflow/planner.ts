import type { InputGuard, InputGuardOutcome } from '../../agent/pipeline.js';
import type { MockPlan, MockPlanner, PlanInput, RenderInput } from '../../providers/mock.js';
import type { ChatAction } from '../../tools/types.js';
import { renderAnswer } from './answers.js';
import { matchIntent, type IntentMatch, type RefusalCode } from './intents.js';
import type { Lang } from './labels.js';
import { PAYFLOW_TOOL_PERMISSIONS } from './tools/definitions.js';

export const REFUSALS: Record<RefusalCode | 'forbidden', Record<Lang, string>> = {
  mutation_not_allowed: {
    en: 'I can’t approve, reject, release, pay, transfer, void, write off or change bank details. These actions must be done in PayFlow Guard by an authorized person, so that they pass the approval rules and are recorded in the audit log.',
    ru: 'Я не могу одобрять, отклонять, проводить или переводить платежи, аннулировать или списывать счета и менять реквизиты. Такие действия выполняет уполномоченный сотрудник в PayFlow Guard — так они проходят правила согласования и попадают в журнал аудита.',
  },
  secret_requested: {
    en: 'I can’t share full account or card numbers, passwords or API keys. I can tell you the last 4 digits of an account, its country and its verification status.',
    ru: 'Я не могу показывать полные номера счетов и карт, пароли или ключи API. Могу сообщить последние 4 цифры счёта, страну банка и статус проверки.',
  },
  advice_not_allowed: {
    en: 'I can’t give legal, tax or investment advice. I can quote what your contracts and policies say; for advice, please consult a qualified professional.',
    ru: 'Я не даю юридических, налоговых и инвестиционных консультаций. Могу процитировать, что сказано в ваших договорах и регламентах, а за советом обратитесь к профильному специалисту.',
  },
  other_workspace: {
    en: 'I can only answer about your own workspace. Data of other workspaces and other people’s private data are not available to me.',
    ru: 'Я отвечаю только по вашему рабочему пространству. Данные других пространств и личные данные других людей мне недоступны.',
  },
  forbidden: {
    en: 'Your role does not give access to this information. Please ask an owner or an approver of your workspace.',
    ru: 'Ваша роль не даёт доступа к этой информации. Обратитесь к владельцу или согласующему вашего пространства.',
  },
};

const CLARIFY: Record<Lang, string> = {
  en: 'Which client or invoice do you mean? Please add the client name or the invoice number.',
  ru: 'Уточните, пожалуйста, о каком клиенте или счёте идёт речь — укажите название клиента или номер счёта.',
};

const OPEN_APP: Record<Lang, string> = { en: 'Open PayFlow Guard', ru: 'Открыть PayFlow Guard' };

export function refusalActions(code: string, lang: Lang, publicWebUrl: string): ChatAction[] {
  if (code !== 'mutation_not_allowed' && code !== 'secret_requested') return [];
  const path = code === 'mutation_not_allowed' ? '/payouts' : '/vendors';
  return [{ type: 'open_url', label: OPEN_APP[lang], payload: { url: `${publicWebUrl}${path}`, code } }];
}

export function createPayflowInputGuard(publicWebUrl: string): InputGuard {
  return ({ input, question, locale, history }) => {
    const match = matchIntent(
      question,
      history.filter((turn) => turn.role === 'user').map((turn) => turn.content),
    );
    if (!match) return null;
    return verdict(match, locale, publicWebUrl, (permission) => input.user.can(permission));
  };
}

export function verdict(
  match: IntentMatch,
  lang: Lang,
  publicWebUrl: string,
  can: (permission: string) => boolean,
): InputGuardOutcome | null {
  const refusal = match.rule.refusal;
  if (refusal) return { refused: true, answer: REFUSALS[refusal][lang], actions: refusalActions(refusal, lang, publicWebUrl), code: refusal };
  const dataSteps = match.steps.filter((step) => PAYFLOW_TOOL_PERMISSIONS[step.tool] !== undefined);
  const firstDataTool = (match.rule.tools ?? []).map(([tool]) => tool).find((tool) => PAYFLOW_TOOL_PERMISSIONS[tool] !== undefined);
  const permission = firstDataTool ? PAYFLOW_TOOL_PERMISSIONS[firstDataTool] : undefined;
  const documentBacked = (match.rule.tools ?? []).some(([tool]) => tool === 'search_documents');
  if (permission && !can(permission)) {
    return documentBacked ? null : { refused: true, answer: REFUSALS.forbidden[lang], actions: [], code: 'forbidden' };
  }
  if (match.missingEntity && dataSteps.length === 0) return { refused: false, answer: CLARIFY[lang], actions: [], code: 'ask_client' };
  return null;
}

export const payflowPlanner: MockPlanner = {
  plan(input: PlanInput): MockPlan | null {
    const match = matchIntent(input.question, input.history);
    if (!match || match.rule.refusal || match.steps.length === 0) return null;
    return { intent: match.rule.name, steps: match.steps, payoutNumber: match.question.payoutNumber };
  },
  render(input: RenderInput): string | null {
    return renderAnswer(input);
  },
};
