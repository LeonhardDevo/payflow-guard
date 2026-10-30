<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal\Payflow;

use App\Audit\Auditor;
use App\Domain\Analytics\Enums\RiskBand;
use App\Domain\Collections\Dispatch\ManualReminders;
use App\Domain\Collections\Enums\Tone;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Guard\Enums\BankAccountStatus;
use App\Domain\Guard\Enums\PayoutStatus;
use App\Domain\Guard\GuardException;
use App\Domain\Guard\Models\GuardAlert;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Guard\Payouts\DecisionContext;
use App\Domain\Guard\Payouts\PayoutService;
use App\Domain\Guard\Text\NameNormalizer;
use App\Domain\Guard\Text\TrigramSimilarity;
use App\Domain\Notifications\Digest\DigestService;
use App\Domain\Receivables\Invoices\InvoiceDisplayStatusResolver;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Exceptions\PayflowException;
use App\Domain\Shared\Money\MoneyData;
use App\Domain\Shared\Money\MoneyFormatter;
use App\Domain\Shared\Support\UserRef;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Locale\WithLocale;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

final class BotController extends Controller
{
    public const array VIEWS = ['overdue', 'due', 'approvals', 'client', 'digest', 'changes', 'payout'];

    public function view(Request $request, string $view, Clock $clock, PayoutService $payouts, DigestService $digests): JsonResponse
    {
        abort_unless(in_array($view, self::VIEWS, true), 404);
        $arg = $request->query('arg');
        $arg = is_string($arg) ? mb_substr(trim($arg), 0, 120) : null;

        return $this->asUser($request, null, function (User $user, Workspace $workspace, WorkspaceRole $role) use ($view, $arg, $clock, $payouts, $digests): array {
            $today = WorkspaceClock::todayIn($workspace->timezone, $clock->now())->toDateString();

            return match ($view) {
                'overdue' => $this->invoiceList($workspace, $role, $today, true),
                'due' => $this->invoiceList($workspace, $role, $today, false),
                'approvals' => $this->approvals($workspace, $user, $role, $payouts),
                'changes' => $this->changes($workspace, $role),
                'client' => $this->client($workspace, $role, $arg, $today),
                'payout' => $this->payoutView($user, $role, $arg, $payouts, $workspace),
                default => self::digest($digests, $workspace, $user, $role, $today, $clock),
            };
        });
    }

    public function payoutDecision(Request $request, int $payout, PayoutService $service): JsonResponse
    {
        $data = $request->validate([
            'telegram_user_id' => ['required', 'integer'],
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'fp8' => ['required', 'string', 'regex:/^[0-9a-f]{8}$/'],
            'comment' => ['nullable', 'string', 'max:500'],
            'confirmed' => ['required', 'boolean'],
        ]);
        $model = PayoutRequest::query()->withoutGlobalScopes()->with(['vendor', 'bankAccount'])->find($payout);

        if ($model === null) {
            return $this->asUser($request, null, fn (): array => self::failure('not_found', (string) __('payflow.bot.not_found')));
        }

        return $this->asUser($request, $model->workspace_id, function (User $user, Workspace $workspace, WorkspaceRole $role) use ($model, $data, $service): array {
            $url = route('payouts.show', $model->id);
            $open = [['text' => (string) __('payflow.telegram.buttons.open_in_app'), 'url' => $url]];

            if (! $role->allows('payouts.approve')) {
                return self::failure('forbidden', (string) __('payflow.bot.forbidden'));
            }

            $approve = $data['decision'] === 'approve';
            $fp8 = (string) $data['fp8'];
            $denial = $service->approvalDenial($model, $user);

            if ($denial !== null) {
                return self::decisionFailure($denial === 'no_permission' ? 'forbidden' : $denial, $model, $open);
            }

            if (! hash_equals(substr((string) $model->bank_fingerprint, 0, 8), $fp8)) {
                return self::decisionFailure('details_changed', $model, $open);
            }

            if ($approve && ! PayoutService::telegramApprovalAllowed($model)) {
                return self::decisionFailure('web_only', $model, $open);
            }

            $amount = MoneyFormatter::format($model->amount_minor, $model->currency, app()->getLocale());

            if (! $approve && ! (bool) $data['confirmed']) {
                return [
                    'ok' => false,
                    'code' => 'reason_required',
                    'status' => $model->status->value,
                    'number' => $model->number,
                    'text_html' => (string) __('payflow.bot.reject_reason', ['number' => e($model->number)]),
                    'buttons' => [],
                ];
            }

            if (! (bool) $data['confirmed']) {
                return [
                    'ok' => false,
                    'code' => 'confirm_required',
                    'status' => $model->status->value,
                    'text_html' => (string) __('payflow.bot.confirm_approve', [
                        'number' => e($model->number),
                        'amount' => e($amount),
                        'vendor' => e($model->vendor->name),
                        'last4' => e($model->bankAccount !== null ? $model->bankAccount->last4 : '----'),
                    ]),
                    'buttons' => [
                        ['text' => (string) __('payflow.bot.buttons.confirm_approval'), 'callback' => 'pf.pac:'.$model->id.':'.$fp8],
                        ['text' => (string) __('payflow.bot.buttons.cancel'), 'callback' => 'pf.x'],
                    ],
                ];
            }

            try {
                $updated = $approve
                    ? $service->approve($model, $user, DecisionContext::telegram(), null, $fp8)
                    : $service->reject($model, $user, DecisionContext::telegram(), (string) ($data['comment'] ?? ''), $fp8);
            } catch (GuardException $exception) {
                $code = match ($exception->errorCode) {
                    'no_permission' => 'forbidden',
                    'comment_required' => 'comment_required',
                    default => $exception->errorCode,
                };

                return self::decisionFailure($code, $model, $open);
            }

            if (! $approve) {
                $text = (string) __('payflow.bot.rejected', ['number' => e($updated->number)]);
            } elseif ($updated->status === PayoutStatus::Approved) {
                $text = (string) __('payflow.bot.approved_ready', ['number' => e($updated->number), 'count' => $updated->approvals_count, 'required' => $updated->required_approvals]);
            } else {
                $text = (string) __('payflow.bot.approved_waiting', ['number' => e($updated->number), 'count' => $updated->approvals_count, 'required' => $updated->required_approvals])
                    .' '.trans_choice('payflow.bot.more_needed', $updated->required_approvals - $updated->approvals_count, ['count' => $updated->required_approvals - $updated->approvals_count]);
            }

            return ['ok' => true, 'status' => $updated->status->value, 'text_html' => $text, 'buttons' => $open];
        });
    }

    public function remind(Request $request, ManualReminders $reminders): JsonResponse
    {
        $data = $request->validate([
            'telegram_user_id' => ['required', 'integer'],
            'invoice' => ['nullable', 'string', 'max:40'],
            'invoice_id' => ['nullable', 'integer'],
            'confirmed' => ['required', 'boolean'],
            'force' => ['sometimes', 'boolean'],
        ]);
        $byId = isset($data['invoice_id']) ? Invoice::query()->withoutGlobalScopes()->find((int) $data['invoice_id']) : null;

        return $this->asUser($request, $byId?->workspace_id, function (User $user, Workspace $workspace, WorkspaceRole $role) use ($data, $byId, $reminders): array {
            if (! $role->allows('reminders.send')) {
                return self::failure('forbidden', (string) __('payflow.bot.forbidden'));
            }

            $invoice = $byId ?? (isset($data['invoice']) ? Invoice::query()->forWorkspace($workspace)->whereRaw('upper(number) = ?', [mb_strtoupper(trim((string) $data['invoice']))])->first() : null);

            if ($invoice === null || $invoice->workspace_id !== $workspace->id) {
                return self::failure('not_found', (string) __('payflow.bot.invoice_not_found'));
            }

            $invoice->loadMissing(['client', 'workspace']);
            $open = ['text' => (string) __('payflow.telegram.open_invoice'), 'url' => route('invoices.show', $invoice->id)];

            if (! $invoice->isOpen()) {
                return ['ok' => false, 'code' => 'not_eligible', 'text_html' => (string) __('payflow.bot.not_eligible', ['number' => e($invoice->label())]), 'buttons' => [$open]];
            }

            $preview = $reminders->preview($invoice);
            $tone = Tone::from((string) $preview['tone']);

            if (! (bool) $data['confirmed']) {
                return [
                    'ok' => false,
                    'code' => 'confirm_required',
                    'text_html' => (string) __('payflow.bot.remind_preview', [
                        'number' => e($invoice->label()),
                        'client' => e($invoice->client->name),
                        'tone' => mb_strtolower((string) __('payflow.tones.'.$tone->value)),
                        'subject' => e((string) $preview['subject']),
                    ]),
                    'buttons' => [
                        ['text' => (string) __('payflow.bot.buttons.send'), 'callback' => 'pf.rmc:'.$invoice->id],
                        ['text' => (string) __('payflow.bot.buttons.cancel'), 'callback' => 'pf.x'],
                    ],
                ];
            }

            try {
                $reminders->send($invoice, $user, $tone, null, null, (bool) ($data['force'] ?? false));
            } catch (PayflowException $exception) {
                if (in_array($exception->errorCode, ['recent_reminder', 'recipient_opted_out'], true)) {
                    return [
                        'ok' => false,
                        'code' => $exception->errorCode,
                        'text_html' => e($exception->userMessage()),
                        'buttons' => [
                            ['text' => (string) __('payflow.bot.buttons.send_anyway'), 'callback' => 'pf.rmf:'.$invoice->id],
                            ['text' => (string) __('payflow.bot.buttons.cancel'), 'callback' => 'pf.x'],
                        ],
                    ];
                }

                return ['ok' => false, 'code' => 'not_eligible', 'text_html' => e($exception->userMessage()), 'buttons' => [$open]];
            }

            return ['ok' => true, 'text_html' => (string) __('payflow.bot.reminder_queued', ['number' => e($invoice->label())]), 'buttons' => [$open]];
        });
    }

    public function acknowledge(Request $request, int $alert, GuardAlerts $alerts): JsonResponse
    {
        $request->validate(['telegram_user_id' => ['required', 'integer']]);
        $model = GuardAlert::query()->withoutGlobalScopes()->find($alert);

        if ($model === null) {
            return $this->asUser($request, null, fn (): array => self::failure('not_found', (string) __('payflow.bot.not_found')));
        }

        return $this->asUser($request, $model->workspace_id, function (User $user, Workspace $workspace, WorkspaceRole $role) use ($model, $alerts): array {
            if (! $role->allows('guard.alerts')) {
                return self::failure('forbidden', (string) __('payflow.bot.forbidden'));
            }

            $alerts->acknowledge($model, $user);
            app(Auditor::class)->record('payflow.alert.acknowledged', $model, metadata: ['type' => $model->type->value, 'channel' => 'telegram'], actor: $user, workspace: $workspace, subjectLabel: $model->type->value);

            return ['ok' => true, 'text_html' => (string) __('payflow.bot.acknowledged', ['name' => e(UserRef::name($user))]), 'buttons' => []];
        });
    }

    /** @param  Closure(User, Workspace, WorkspaceRole): array<string, mixed>  $handler */
    private function asUser(Request $request, ?int $entityWorkspaceId, Closure $handler): JsonResponse
    {
        $telegramUserId = $request->input('telegram_user_id', $request->query('telegram_user_id'));
        $account = is_numeric($telegramUserId) ? TelegramAccount::query()->with('user')->where('telegram_user_id', (int) $telegramUserId)->first() : null;
        $user = $account?->user;

        if ($account === null || ! $user instanceof User || $user->getAttribute('pseudonymised_at') !== null) {
            return response()->json(['code' => 'not_linked'], 404);
        }

        $locale = in_array($user->locale, ['en', 'ru'], true) ? $user->locale : 'en';
        $workspaceId = $entityWorkspaceId ?? $this->contextWorkspaceId($account, $user);
        $workspace = $workspaceId !== null ? Workspace::query()->find($workspaceId) : null;
        $role = $workspace !== null ? $user->roleIn($workspace) : null;

        if ($workspace === null || $role === null) {
            return response()->json(WithLocale::run($locale, fn (): array => self::failure('forbidden', (string) __('payflow.bot.forbidden'))));
        }

        $result = app(CurrentWorkspace::class)->runAs($workspace, fn (): array => WithLocale::run($locale, fn (): array => $handler($user, $workspace, $role)), $role);

        return response()->json($result);
    }

    private function contextWorkspaceId(TelegramAccount $account, User $user): ?int
    {
        foreach (array_filter([$account->workspace_id, $user->current_workspace_id]) as $candidate) {
            if ($user->belongsToWorkspace((int) $candidate)) {
                return (int) $candidate;
            }
        }

        $first = $user->workspaces()->orderBy('workspace_user.created_at')->first();

        return $first?->id;
    }

    /** @return array<string, mixed> */
    private function invoiceList(Workspace $workspace, WorkspaceRole $role, string $today, bool $overdue): array
    {
        if (! $role->allows('invoices.view')) {
            return self::failure('forbidden', (string) __('payflow.bot.forbidden'));
        }

        $locale = app()->getLocale();
        $query = Invoice::query()->forWorkspace($workspace)->with('client')->whereIn('status', Invoice::OPEN_STATUSES);
        $query = $overdue
            ? $query->where('due_date', '<', $today)->orderBy('due_date')
            : $query->where('due_date', '>=', $today)->where('due_date', '<=', WorkspaceClock::todayIn($workspace->timezone, app(Clock::class)->now())->addDays(7)->toDateString())->orderBy('due_date');
        $all = $query->get();

        if ($all->isEmpty()) {
            return ['text_html' => (string) __($overdue ? 'payflow.bot.overdue_empty' : 'payflow.bot.due_empty'), 'buttons' => []];
        }

        $lines = ['<b>'.e((string) __($overdue ? 'payflow.bot.overdue_title' : 'payflow.bot.due_title')).'</b>'];
        $buttons = [];

        foreach ($all->take(5)->values() as $index => $invoice) {
            $days = $overdue ? InvoiceDisplayStatusResolver::daysOverdue($invoice->dueDate(), $today) : (int) InvoiceDisplayStatusResolver::daysUntilDue($invoice->dueDate(), $today);
            $lines[] = (string) __($overdue ? 'payflow.bot.overdue_line' : 'payflow.bot.due_line', [
                'position' => $index + 1,
                'number' => e($invoice->label()),
                'client' => e($invoice->client->name),
                'days' => $days,
                'amount' => e(MoneyFormatter::format($invoice->balanceDue(), $invoice->currency, $locale)),
                'risk' => $invoice->risk_band !== null ? e((string) __('payflow.risk_bands.'.$invoice->risk_band->value)) : '—',
            ]);

            if ($role->allows('reminders.send')) {
                $buttons[] = ['text' => mb_substr((string) __('payflow.bot.buttons.remind', ['number' => $invoice->label()]), 0, 64), 'callback' => 'pf.rm:'.$invoice->id];
            }

            $buttons[] = ['text' => mb_substr((string) __('payflow.bot.buttons.open', ['number' => $invoice->label()]), 0, 64), 'url' => route('invoices.show', $invoice->id)];
        }

        $totals = $all->groupBy('currency')->map(fn (Collection $group): int => (int) $group->sum(fn (Invoice $invoice): int => $invoice->balanceDue()))->all();
        $lines[] = (string) __('payflow.bot.total', ['count' => $all->count(), 'amount' => e(MoneyData::joined($totals, $locale))]);

        return ['text_html' => implode("\n", $lines), 'buttons' => array_slice($buttons, 0, 10)];
    }

    /** @return array<string, mixed> */
    private function approvals(Workspace $workspace, User $user, WorkspaceRole $role, PayoutService $service): array
    {
        if (! $role->allows('payouts.view')) {
            return self::failure('forbidden', (string) __('payflow.bot.forbidden'));
        }

        $locale = app()->getLocale();
        $pending = PayoutRequest::query()->forWorkspace($workspace)->with(['vendor', 'bankAccount'])
            ->where('status', PayoutStatus::PendingApproval->value)->orderBy('submitted_at')->get()
            ->map(fn (PayoutRequest $payout): array => ['payout' => $payout, 'mine' => $service->approvalDenial($payout, $user) === null])
            ->sortByDesc(fn (array $row): int => $row['mine'] ? 1 : 0)
            ->values();

        if ($pending->isEmpty()) {
            return ['text_html' => (string) __('payflow.bot.approvals_empty'), 'buttons' => []];
        }

        $lines = ['<b>'.e((string) __('payflow.bot.approvals_title')).'</b>'];
        $buttons = [];

        foreach ($pending->take(5) as $index => $row) {
            $payout = $row['payout'];
            $lines[] = (string) __('payflow.bot.approval_line', [
                'position' => $index + 1,
                'number' => e($payout->number),
                'vendor' => e($payout->vendor->name),
                'amount' => e(MoneyFormatter::format($payout->amount_minor, $payout->currency, $locale)),
                'risk' => e((string) __('payflow.risk_bands.'.($payout->risk_band ?? RiskBand::Low)->value)),
                'count' => $payout->approvals_count,
                'required' => $payout->required_approvals,
                'mine' => $row['mine'] ? ' — '.e((string) __('payflow.bot.awaiting_you')) : '',
            ]);

            if (count($buttons) >= 9) {
                continue;
            }

            $fp8 = substr((string) $payout->bank_fingerprint, 0, 8);

            if ($row['mine'] && PayoutService::telegramApprovalAllowed($payout) && $fp8 !== '') {
                $buttons[] = ['text' => mb_substr((string) __('payflow.bot.buttons.approve_number', ['number' => $payout->number]), 0, 64), 'callback' => 'pf.pa:'.$payout->id.':'.$fp8];
                $buttons[] = ['text' => mb_substr((string) __('payflow.bot.buttons.reject_number', ['number' => $payout->number]), 0, 64), 'callback' => 'pf.pr:'.$payout->id.':'.$fp8];
            }

            $buttons[] = ['text' => mb_substr((string) __('payflow.bot.buttons.open', ['number' => $payout->number]), 0, 64), 'url' => route('payouts.show', $payout->id)];
        }

        return ['text_html' => implode("\n", $lines), 'buttons' => array_slice($buttons, 0, 10)];
    }

    /** @return array<string, mixed> */
    private function payoutView(User $user, WorkspaceRole $role, ?string $arg, PayoutService $service, Workspace $workspace): array
    {
        if (! $role->allows('payouts.view')) {
            return self::failure('forbidden', (string) __('payflow.bot.forbidden'));
        }

        $payout = $arg !== null && ctype_digit($arg) ? PayoutRequest::query()->forWorkspace($workspace)->with(['vendor', 'bankAccount'])->find((int) $arg) : null;

        if ($payout === null) {
            return self::failure('not_found', (string) __('payflow.bot.not_found'));
        }

        $locale = app()->getLocale();
        $lines = [
            '<b>'.e($payout->number).'</b> — '.e((string) __('payflow.payout_statuses.'.$payout->status->value)),
            e($payout->vendor->name).' — '.e(MoneyFormatter::format($payout->amount_minor, $payout->currency, $locale)).($payout->bankAccount !== null ? ' (•••• '.e($payout->bankAccount->last4).')' : ''),
            e($payout->description),
            (string) __('payflow.bot.approvals_count', ['count' => $payout->approvals_count, 'required' => $payout->required_approvals]),
        ];
        $buttons = [];
        $fp8 = substr((string) $payout->bank_fingerprint, 0, 8);

        if ($service->approvalDenial($payout, $user) === null && PayoutService::telegramApprovalAllowed($payout) && $fp8 !== '') {
            $buttons[] = ['text' => (string) __('payflow.telegram.buttons.approve'), 'callback' => 'pf.pa:'.$payout->id.':'.$fp8];
            $buttons[] = ['text' => (string) __('payflow.telegram.buttons.reject'), 'callback' => 'pf.pr:'.$payout->id.':'.$fp8];
        }

        $buttons[] = ['text' => (string) __('payflow.telegram.buttons.open_in_app'), 'url' => route('payouts.show', $payout->id)];

        return ['text_html' => implode("\n", $lines), 'buttons' => $buttons];
    }

    /** @return array<string, mixed> */
    private function changes(Workspace $workspace, WorkspaceRole $role): array
    {
        if (! $role->allows('vendors.view') && ! $role->allows('bank_changes.verify')) {
            return self::failure('forbidden', (string) __('payflow.bot.forbidden'));
        }

        $changes = VendorBankAccount::query()->forWorkspace($workspace)->with('vendor')
            ->where('status', BankAccountStatus::PendingVerification->value)
            ->orderByRaw("case risk_band when 'high' then 0 when 'medium' then 1 else 2 end")
            ->orderBy('requested_at')
            ->limit(5)
            ->get();

        if ($changes->isEmpty()) {
            return ['text_html' => (string) __('payflow.bot.changes_empty'), 'buttons' => []];
        }

        $lines = ['<b>'.e((string) __('payflow.bot.changes_title')).'</b>'];
        $buttons = [];

        foreach ($changes->values() as $index => $account) {
            $lines[] = (string) __('payflow.bot.change_line', [
                'position' => $index + 1,
                'vendor' => e($account->vendor->name),
                'country' => e($account->bank_country),
                'last4' => e($account->last4),
                'band' => e((string) __('payflow.risk_bands.'.$account->risk_band->value)),
                'score' => $account->risk_score,
            ]);
            $buttons[] = ['text' => mb_substr((string) __('payflow.bot.buttons.review', ['vendor' => $account->vendor->name]), 0, 64), 'url' => route('guard.bank-changes.show', $account->id)];
        }

        $lines[] = e((string) __('payflow.telegram.bank_change_requested.callback'));
        $lines[] = e((string) __('payflow.telegram.risk_disclaimer'));

        return ['text_html' => implode("\n", $lines), 'buttons' => $buttons];
    }

    /** @return array<string, mixed> */
    private function client(Workspace $workspace, WorkspaceRole $role, ?string $arg, string $today): array
    {
        if (! $role->allows('clients.view')) {
            return self::failure('forbidden', (string) __('payflow.bot.forbidden'));
        }

        $arg = trim((string) $arg);
        $client = ctype_digit($arg) ? Client::query()->forWorkspace($workspace)->find((int) $arg) : null;

        if ($client === null && $arg !== '') {
            $exact = Client::query()->forWorkspace($workspace)->where(fn ($query) => $query->whereRaw('lower(name) = ?', [mb_strtolower($arg)])->orWhereRaw('lower(coalesce(legal_name, \'\')) = ?', [mb_strtolower($arg)]))->first();
            $client = $exact;

            if ($client === null) {
                $normalized = NameNormalizer::normalize($arg);
                $scored = Client::query()->forWorkspace($workspace)->get()
                    ->map(fn (Client $candidate): array => ['client' => $candidate, 'score' => max(
                        TrigramSimilarity::raw($normalized, NameNormalizer::normalize($candidate->name)),
                        $candidate->legal_name !== null ? TrigramSimilarity::raw($normalized, NameNormalizer::normalize($candidate->legal_name)) : 0.0,
                    )])
                    ->filter(fn (array $row): bool => $row['score'] >= 0.3)
                    ->sortByDesc('score')
                    ->values();

                if ($scored->count() > 1 && $scored[0]['score'] - $scored[1]['score'] < 0.05) {
                    return [
                        'code' => 'ambiguous',
                        'text_html' => e((string) __('payflow.bot.ambiguous')),
                        'buttons' => $scored->take(5)->map(fn (array $row): array => ['text' => mb_substr($row['client']->name, 0, 64), 'callback' => 'pf.cl:'.$row['client']->id])->all(),
                    ];
                }

                $client = $scored->isNotEmpty() ? $scored[0]['client'] : null;
            }
        }

        if ($client === null) {
            return self::failure('not_found', (string) __('payflow.bot.client_not_found'));
        }

        $locale = app()->getLocale();
        $invoices = Invoice::query()->forWorkspace($workspace)->where('client_id', $client->id)->whereIn('status', Invoice::OPEN_STATUSES)->get();
        $sum = fn (Collection $items): string => MoneyData::joined($items->groupBy('currency')->map(fn (Collection $group): int => (int) $group->sum(fn (Invoice $invoice): int => $invoice->balanceDue()))->all(), $locale) ?: '—';
        $next = Reminder::query()->forWorkspace($workspace)
            ->where('client_id', $client->id)->where('status', 'scheduled')->orderBy('scheduled_for')->first();

        return [
            'text_html' => implode("\n", [
                '<b>'.e($client->name).'</b> — '.e((string) __('payflow.classes.'.$client->late_payer_class->value)),
                (string) __('payflow.bot.client_avg', ['days' => number_format($client->avgDaysLate(), 1)]),
                (string) __('payflow.bot.client_balance', ['open' => e($sum($invoices)), 'overdue' => e($sum($invoices->filter(fn (Invoice $invoice): bool => $invoice->dueDate() < $today)))]),
                (string) __('payflow.bot.client_next', ['at' => $next !== null ? e($next->scheduled_for->copy()->setTimezone(WorkspaceClock::zone($workspace->timezone))->format('Y-m-d H:i')) : '—']),
            ]),
            'buttons' => [['text' => (string) __('payflow.telegram.open_client'), 'url' => route('clients.show', $client->id)]],
        ];
    }

    /** @return array<string, mixed> */
    private static function digest(DigestService $digests, Workspace $workspace, User $user, WorkspaceRole $role, string $today, Clock $clock): array
    {
        $content = $digests->content($workspace, $user, $role, $today, $clock->now(), app()->getLocale());

        return [
            'text_html' => '<b>'.$content['title'].'</b>'."\n".$content['body'],
            'buttons' => [['text' => (string) __('payflow.telegram.open_dashboard'), 'url' => route('dashboard')]],
        ];
    }

    /**
     * @param  list<array{text: string, url?: string, callback?: string}>  $buttons
     * @return array<string, mixed>
     */
    private static function decisionFailure(string $code, PayoutRequest $payout, array $buttons): array
    {
        $key = 'payflow.bot.decision.'.$code;
        $text = (string) __($key, ['number' => e($payout->number)]);

        return [
            'ok' => false,
            'code' => $code,
            'status' => $payout->status->value,
            'text_html' => $text === $key ? e((string) __('payflow.errors.generic')) : $text,
            'buttons' => $buttons,
        ];
    }

    /** @return array<string, mixed> */
    private static function failure(string $code, string $text): array
    {
        return ['ok' => false, 'code' => $code, 'text_html' => e($text), 'buttons' => []];
    }
}
