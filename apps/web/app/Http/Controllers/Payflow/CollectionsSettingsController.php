<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Billing\PlanLimits;
use App\Domain\Collections\Email\ClientEmailComposer;
use App\Domain\Collections\Enums\StepTone;
use App\Domain\Collections\Enums\TemplateKey;
use App\Domain\Collections\Models\MessageTemplate;
use App\Domain\Collections\Models\ReminderPolicy;
use App\Domain\Collections\Settings\CollectionsSettingsService;
use App\Domain\Collections\Templates\TemplateRenderer;
use App\Domain\Receivables\Enums\LateFeeType;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Exceptions\PayflowException;
use App\Domain\Shared\Money\MoneyFormatter;
use App\Domain\Shared\Money\MoneyParser;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Http\Resources\Payflow\SettingsResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CollectionsSettingsController extends PayflowController
{
    public function show(Request $request, PlanLimits $limits): Response
    {
        $workspace = $this->workspace();
        $user = $this->user($request);
        $settings = WorkspaceProvisioner::settingsFor($workspace);

        return Inertia::render('settings/Collections', [
            'settings' => SettingsResource::collections($settings, $workspace),
            'policies' => ReminderPolicy::query()->with('steps')->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn (ReminderPolicy $policy): array => SettingsResource::policy($policy))->values()->all(),
            'templates' => MessageTemplate::query()->with('editor')->orderBy('key')->orderBy('locale')->get()
                ->map(fn (MessageTemplate $template): array => SettingsResource::template($template))->values()->all(),
            'template_variables' => TemplateRenderer::VARIABLES,
            'timezone' => $workspace->timezone,
            'can_edit_templates' => $limits->hasFeature($workspace, 'custom_templates'),
            'can_change_receiving_details' => $user->can('guard.configure'),
            'can_confirm_receiving_details' => $user->can('guard.configure') || $user->can('payouts.approve'),
            'currencies' => self::currencies(),
        ]);
    }

    public function update(Request $request, CollectionsSettingsService $service): RedirectResponse
    {
        $workspaceId = $this->workspace()->id;
        $data = $request->validate([
            'invoice_prefix' => ['required', 'string', 'regex:/^[A-Z0-9-]{1,12}$/'],
            'default_payment_terms_days' => ['required', 'integer', 'between:0,180'],
            'default_reminder_policy_id' => ['nullable', 'integer', Rule::exists('reminder_policies', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at')],
            'send_window_start' => ['required', 'date_format:H:i'],
            'send_window_end' => ['required', 'date_format:H:i', 'after:send_window_start'],
            'business_days_only' => ['required', 'boolean'],
            'first_contact_max_per_hour' => ['required', 'integer', 'between:1,500'],
            'sender_display_name' => ['nullable', 'string', 'max:120'],
            'reply_to_email' => ['nullable', 'email:rfc', 'max:255'],
            'privacy_contact_email' => ['nullable', 'email:rfc', 'max:255'],
            'track_invoice_views' => ['required', 'boolean'],
            'late_fee.type' => ['required', Rule::enum(LateFeeType::class)],
            'late_fee.rate' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/', 'numeric', 'between:0,100'],
            'late_fee.fixed' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'late_fee.grace_days' => ['nullable', 'integer', 'between:0,90'],
            'late_fee.cap' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/', 'numeric', 'between:0,100'],
            'company.legal_name' => ['nullable', 'string', 'max:200'],
            'company.address' => ['nullable', 'string', 'max:1000'],
            'company.tax_id' => ['nullable', 'string', 'max:64'],
            'company.email' => ['nullable', 'email:rfc', 'max:255'],
            'company.phone' => ['nullable', 'string', 'max:40'],
            'digest_enabled' => ['required', 'boolean'],
            'digest_hour' => ['required', 'integer', 'between:0,23'],
        ]);

        $fee = (array) ($data['late_fee'] ?? []);
        $type = LateFeeType::from((string) $fee['type']);
        $percent = fn (string $key): ?int => isset($fee[$key]) && $fee[$key] !== '' ? MoneyParser::percentToBasisPoints((string) $fee[$key]) : null;
        $company = (array) ($data['company'] ?? []);

        $service->update($this->workspace(), $this->user($request), [
            'invoice_prefix' => $data['invoice_prefix'],
            'default_payment_terms_days' => (int) $data['default_payment_terms_days'],
            'default_reminder_policy_id' => isset($data['default_reminder_policy_id']) ? (int) $data['default_reminder_policy_id'] : WorkspaceProvisioner::settingsFor($workspaceId)->default_reminder_policy_id,
            'send_window_start' => $data['send_window_start'],
            'send_window_end' => $data['send_window_end'],
            'business_days_only' => (bool) $data['business_days_only'],
            'first_contact_max_per_hour' => (int) $data['first_contact_max_per_hour'],
            'sender_display_name' => $data['sender_display_name'] ?? null,
            'reply_to_email' => $data['reply_to_email'] ?? null,
            'privacy_contact_email' => $data['privacy_contact_email'] ?? null,
            'track_invoice_views' => (bool) $data['track_invoice_views'],
            'late_fee_type' => $type,
            'late_fee_rate_bp' => $percent('rate'),
            'late_fee_fixed_minor' => isset($fee['fixed']) && $fee['fixed'] !== '' ? MoneyParser::toMinor((string) $fee['fixed']) : null,
            'late_fee_grace_days' => (int) ($fee['grace_days'] ?? 0),
            'late_fee_cap_bp' => $percent('cap'),
            'company_legal_name' => $company['legal_name'] ?? null,
            'company_address' => $company['address'] ?? null,
            'company_tax_id' => $company['tax_id'] ?? null,
            'company_email' => $company['email'] ?? null,
            'company_phone' => $company['phone'] ?? null,
            'digest_enabled' => (bool) $data['digest_enabled'],
            'digest_hour' => (int) $data['digest_hour'],
        ]);

        return $this->back('settings_saved');
    }

    /** @return array{name: string, description: string|null, is_default: bool, max_reminders: int, min_interval_days: int, escalate_after_days: int, steps: list<array{offset_days: int, tone: string, attach_pdf: bool, include_payment_link: bool, include_late_fee_notice: bool, notify_owner: bool}>} */
    private function policyData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
            'max_reminders' => ['required', 'integer', 'between:1,20'],
            'min_interval_days' => ['required', 'integer', 'between:1,30'],
            'escalate_after_days' => ['required', 'integer', 'between:1,365'],
            'steps' => ['required', 'array', 'min:1', 'max:20'],
            'steps.*.offset_days' => ['required', 'integer', 'between:-30,180', 'distinct'],
            'steps.*.tone' => ['required', Rule::enum(StepTone::class)],
            'steps.*.attach_pdf' => ['required', 'boolean'],
            'steps.*.include_payment_link' => ['required', 'boolean'],
            'steps.*.include_late_fee_notice' => ['required', 'boolean'],
            'steps.*.notify_owner' => ['required', 'boolean'],
        ]);

        return [
            'name' => (string) $data['name'],
            'description' => isset($data['description']) ? (string) $data['description'] : null,
            'is_default' => (bool) ($data['is_default'] ?? false),
            'max_reminders' => (int) $data['max_reminders'],
            'min_interval_days' => (int) $data['min_interval_days'],
            'escalate_after_days' => (int) $data['escalate_after_days'],
            'steps' => array_values(array_map(fn (array $step): array => [
                'offset_days' => (int) $step['offset_days'],
                'tone' => (string) $step['tone'],
                'attach_pdf' => (bool) $step['attach_pdf'],
                'include_payment_link' => (bool) $step['include_payment_link'],
                'include_late_fee_notice' => (bool) $step['include_late_fee_notice'],
                'notify_owner' => (bool) $step['notify_owner'],
            ], (array) $data['steps'])),
        ];
    }

    public function storePolicy(Request $request, CollectionsSettingsService $service): RedirectResponse
    {
        $service->savePolicy($this->workspace(), $this->user($request), $this->policyData($request));

        return $this->back('policy_created');
    }

    public function updatePolicy(Request $request, ReminderPolicy $policy, CollectionsSettingsService $service): RedirectResponse
    {
        Gate::authorize('update', $policy);
        $service->savePolicy($this->workspace(), $this->user($request), $this->policyData($request), $policy);

        return $this->back('policy_updated');
    }

    public function destroyPolicy(Request $request, ReminderPolicy $policy, CollectionsSettingsService $service): RedirectResponse
    {
        Gate::authorize('delete', $policy);
        $service->deletePolicy($this->workspace(), $this->user($request), $policy);

        return $this->back('policy_deleted');
    }

    public function updateTemplate(Request $request, MessageTemplate $template, CollectionsSettingsService $service, PlanLimits $limits): RedirectResponse
    {
        Gate::authorize('update', $template);

        if (! $limits->hasFeature($this->workspace(), 'custom_templates')) {
            throw new PayflowException('entitlement_required', 403);
        }

        $data = $request->validate(['subject' => ['required', 'string', 'max:200'], 'body_markdown' => ['required', 'string', 'max:5000']]);
        $service->updateTemplate($template, $this->user($request), (string) $data['subject'], (string) $data['body_markdown']);

        return $this->back('template_updated');
    }

    public function resetTemplate(Request $request, MessageTemplate $template, CollectionsSettingsService $service): RedirectResponse
    {
        Gate::authorize('update', $template);
        $service->resetTemplate($template, $this->user($request));

        return $this->back('template_reset');
    }

    public function previewTemplate(Request $request, ClientEmailComposer $composer, Clock $clock): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', Rule::enum(TemplateKey::class)],
            'locale' => ['required', Rule::in(['en', 'ru'])],
            'subject' => ['required', 'string', 'max:200'],
            'body_markdown' => ['required', 'string', 'max:5000'],
            'invoice_id' => ['nullable', 'integer'],
        ]);

        $unknown = TemplateRenderer::unknownVariables((string) $data['subject'], (string) $data['body_markdown']);
        $invoice = isset($data['invoice_id']) ? Invoice::query()->find((int) $data['invoice_id']) : null;
        $invoice ??= Invoice::query()->whereIn('status', Invoice::OPEN_STATUSES)->orderByDesc('id')->first();

        if ($invoice === null) {
            $rendered = TemplateRenderer::render((string) $data['subject'], (string) $data['body_markdown'], self::sampleVariables((string) $data['locale']));

            return response()->json(['subject' => $rendered['subject'], 'html' => $rendered['html'], 'text' => $rendered['text'], 'unknown_variables' => $unknown]);
        }

        $preview = $invoice->replicate();
        $preview->id = $invoice->id;
        $preview->locale = (string) $data['locale'];
        $today = WorkspaceClock::todayIn($this->workspace()->timezone, $clock->now());
        $variables = $composer->variables($preview, $today, null, [
            'payment_amount' => MoneyFormatter::format($preview->total_minor, $preview->currency, $preview->locale),
            'balance_phrase' => (string) trans('payflow.templates.balance_paid', [], $preview->locale),
        ]);
        $rendered = TemplateRenderer::render((string) $data['subject'], (string) $data['body_markdown'], $variables);
        $email = $composer->wrap($preview, $rendered['subject'], $rendered['markdown'], $rendered['html'], $rendered['text']);

        return response()->json(['subject' => $email->subject, 'html' => $email->html, 'text' => $email->text, 'unknown_variables' => $unknown]);
    }

    /** @return array<string, string> */
    private static function sampleVariables(string $locale): array
    {
        $variables = [];

        foreach (TemplateRenderer::VARIABLES as $name) {
            $variables[$name] = (string) trans('payflow.templates.sample.'.$name, [], $locale);
        }

        return $variables;
    }
}
