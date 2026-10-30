<?php

declare(strict_types=1);

namespace App\Domain\Collections\Drafts;

use App\Domain\Collections\Email\ClientEmailComposer;
use App\Domain\Collections\Enums\Tone;
use App\Domain\Collections\Templates\DefaultTemplates;
use App\Domain\Collections\Templates\TemplateRenderer;
use App\Domain\Receivables\LateFees\LateFees;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Internal\InternalClient;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class ReminderDraftAssistant
{
    public const string GREETING = '[[GREETING]]';

    public const string SENDER = '[[SENDER]]';

    public function __construct(
        private InternalClient $internal,
        private ClientEmailComposer $composer,
        private Clock $clock,
    ) {}

    /** @return array{subject: string, body_markdown: string, fallback: bool, provider: string|null, model: string|null} */
    public function draft(Invoice $invoice, User $user, Tone $tone): array
    {
        $today = WorkspaceClock::todayIn($invoice->workspace->timezone, $this->clock->now());
        $fee = LateFees::suggest($invoice, $today);
        $variables = $this->composer->variables($invoice, $today, $fee !== null ? $fee->amountMinor + $fee->alreadyAppliedMinor : null);
        $placeholders = [...$variables, 'greeting_name' => self::GREETING, 'sender_name' => self::SENDER];
        $template = DefaultTemplates::effective($invoice->workspace_id, $tone->templateKey(), $invoice->locale);
        $base = [
            'subject' => TemplateRenderer::substitute($template['subject'], $placeholders),
            'body_markdown' => TemplateRenderer::substitute($template['body'], $placeholders),
        ];
        $fallback = [
            'subject' => TemplateRenderer::substitute($template['subject'], $variables),
            'body_markdown' => TemplateRenderer::substitute($template['body'], $variables),
            'fallback' => true,
            'provider' => null,
            'model' => null,
        ];

        try {
            $response = $this->internal->to('ai')->timeout(30)->post('/v1/payflow/reminder-draft', [
                'workspace_id' => $invoice->workspace_id,
                'user' => ['id' => $user->id, 'locale' => $user->locale],
                'locale' => $invoice->locale,
                'tone' => $tone->value,
                'ai_external_allowed' => $invoice->workspace->ai_external_allowed,
                'facts' => [
                    'greeting_name' => self::GREETING,
                    'client_name' => $variables['client_name'],
                    'invoice_number' => $variables['invoice_number'],
                    'amount_due' => $variables['amount_due'],
                    'due_date' => $variables['due_date'],
                    'days_overdue' => (int) $variables['days_overdue'],
                    'invoice_link' => $variables['invoice_link'],
                    'company_name' => $variables['company_name'],
                    'sender_name' => self::SENDER,
                    'late_fee' => $variables['late_fee'] !== '' ? $variables['late_fee'] : null,
                    'history' => ['avg_days_late' => $invoice->client->avgDaysLate(), 'reminders_sent' => $invoice->reminders_sent_count],
                ],
                'base' => $base,
            ]);
        } catch (Throwable $exception) {
            Log::warning('payflow.ai_draft_failed', ['invoice_id' => $invoice->id, 'error' => $exception::class]);

            return $fallback;
        }

        if (! $response->successful()) {
            return $fallback;
        }

        $subject = $response->json('subject');
        $body = $response->json('body_markdown');

        if (! is_string($subject) || ! is_string($body) || ! self::passesGuard($subject, $body, $variables, $base)) {
            return $fallback;
        }

        $provider = $response->json('provider');
        $model = $response->json('model');

        return [
            'subject' => str_replace([self::GREETING, self::SENDER], [$variables['greeting_name'], $variables['sender_name']], $subject),
            'body_markdown' => str_replace([self::GREETING, self::SENDER], [$variables['greeting_name'], $variables['sender_name']], $body),
            'fallback' => false,
            'provider' => is_string($provider) ? $provider : null,
            'model' => is_string($model) ? $model : null,
        ];
    }

    /**
     * @param  array<string, string>  $facts
     * @param  array{subject: string, body_markdown: string}  $base
     */
    public static function passesGuard(string $subject, string $body, array $facts, array $base): bool
    {
        $text = $subject."\n".$body;

        if (mb_strlen($body) > 5000 || preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', $text) === 1) {
            return false;
        }

        foreach ([$facts['invoice_number'], $facts['amount_due'], $facts['invoice_link'], self::GREETING, self::SENDER] as $required) {
            if (! str_contains($text, $required)) {
                return false;
            }
        }

        $allowed = self::digitSequences(implode("\n", [...array_values($facts), $base['subject'], $base['body_markdown']]));

        return array_diff(self::digitSequences($text), $allowed) === [];
    }

    /** @return list<string> */
    public static function digitSequences(string $text): array
    {
        preg_match_all('/\d+/', $text, $matches);

        return array_values(array_unique($matches[0]));
    }
}
