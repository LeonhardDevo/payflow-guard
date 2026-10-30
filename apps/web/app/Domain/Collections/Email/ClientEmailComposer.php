<?php

declare(strict_types=1);

namespace App\Domain\Collections\Email;

use App\Domain\Collections\Enums\TemplateKey;
use App\Domain\Collections\Templates\DefaultTemplates;
use App\Domain\Collections\Templates\TemplateRenderer;
use App\Domain\Notifications\Email\EmailSendV2;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Models\PayflowSettings;
use App\Domain\Shared\Money\MoneyFormatter;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\BusinessDate;
use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class ClientEmailComposer
{
    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    public function variables(Invoice $invoice, CarbonInterface $today, ?int $lateFeeMinor = null, array $extra = []): array
    {
        $workspace = $invoice->workspace;
        $settings = WorkspaceProvisioner::settingsFor($workspace);
        $client = $invoice->client;
        $locale = $invoice->locale;
        $d = BusinessDate::diff($invoice->dueDate(), $today);
        $daysOverdue = max(0, $d);

        $duePhrase = match (true) {
            $d < 0 => trans_choice('payflow.phrases.due_in', -$d, ['count' => -$d], $locale),
            $d === 0 => (string) trans('payflow.phrases.due_today', [], $locale),
            default => trans_choice('payflow.phrases.was_due', $d, ['count' => $d], $locale),
        };

        $lateFee = $lateFeeMinor !== null && $lateFeeMinor > 0 ? MoneyFormatter::format($lateFeeMinor, $invoice->currency, $locale) : '';
        $lateFeeNotice = $lateFee !== ''
            ? str_replace('{{late_fee}}', $lateFee, (string) trans('payflow.templates.late_fee_notice', [], $locale))
            : '';

        return [
            'greeting_name' => $client->contact_name ?? $client->name,
            'client_name' => $client->name,
            'invoice_number' => $invoice->label(),
            'po_number' => (string) $invoice->po_number,
            'amount_due' => MoneyFormatter::format($invoice->balanceDue(), $invoice->currency, $locale),
            'total' => MoneyFormatter::format($invoice->total_minor, $invoice->currency, $locale),
            'due_date' => self::longDate($invoice->dueDate(), $locale),
            'due_phrase' => $duePhrase,
            'days_overdue' => (string) $daysOverdue,
            'days_overdue_phrase' => trans_choice('payflow.phrases.days', $daysOverdue, ['count' => $daysOverdue], $locale),
            'invoice_link' => self::publicUrl($invoice),
            'payment_link' => self::publicUrl($invoice),
            'company_name' => self::companyName($workspace, $settings),
            'sender_name' => self::senderName($workspace, $settings),
            'late_fee' => $lateFee,
            'late_fee_notice' => $lateFeeNotice,
            'payment_amount' => '',
            'balance_phrase' => '',
            ...$extra,
        ];
    }

    /** @param  array<string, string>  $variables */
    public function compose(Invoice $invoice, TemplateKey $key, array $variables, ?string $subject = null, ?string $body = null): RenderedEmail
    {
        $template = DefaultTemplates::effective($invoice->workspace_id, $key, $invoice->locale);
        $rendered = TemplateRenderer::render($subject ?? $template['subject'], $body ?? $template['body'], $variables);

        return $this->wrap($invoice, $rendered['subject'], $rendered['markdown'], $rendered['html'], $rendered['text']);
    }

    public function wrap(Invoice $invoice, string $subject, string $markdown, string $bodyHtml, string $bodyText): RenderedEmail
    {
        $workspace = $invoice->workspace;
        $settings = WorkspaceProvisioner::settingsFor($workspace);
        $footer = $this->footer($invoice, $settings);

        $html = view('emails.payflow.layout', [
            'locale' => $invoice->locale,
            'subject' => $subject,
            'company' => self::companyName($workspace, $settings),
            'body' => $bodyHtml,
            'footer' => $footer,
            'primary' => (string) config('product.email.primary', '#059669'),
            'ink' => (string) config('product.email.ink', '#0B1324'),
        ])->render();

        $text = $bodyText."\n\n--\n".implode("\n", array_map(fn (array $line): string => $line['text'], $footer));

        return new RenderedEmail($subject, $markdown, $bodyHtml, $html, $text);
    }

    /** @return list<array{text: string, url: string|null}> */
    public function footer(Invoice $invoice, PayflowSettings $settings): array
    {
        $locale = $invoice->locale;
        $workspace = $invoice->workspace;
        $company = self::companyName($workspace, $settings);
        $identity = array_values(array_filter([
            $settings->company_address !== null ? trim((string) preg_replace('/\s*\n\s*/', ', ', $settings->company_address)) : null,
            $settings->company_tax_id,
        ]));
        $recipientsUrl = self::legalUrl('reminder-recipients', $locale);
        $stopUrl = route('public.invoices.reminders', ['token' => $invoice->public_token, 'lang' => $locale]);

        $lines = [['text' => (string) trans('payflow.email.footer.sender', ['company' => $company], $locale), 'url' => null]];

        if ($identity !== []) {
            $lines[] = ['text' => implode(' · ', $identity), 'url' => null];
        }

        $lines[] = [
            'text' => (string) trans('payflow.email.footer.privacy', [
                'company' => $company,
                'privacy_contact' => self::privacyContact($workspace, $settings),
                'reminder_recipients_url' => $recipientsUrl,
            ], $locale),
            'url' => $recipientsUrl,
        ];
        $lines[] = ['text' => (string) trans('payflow.email.footer.choice', ['stop_url' => $stopUrl], $locale), 'url' => $stopUrl];

        return $lines;
    }

    /**
     * @param  array{type: string, id: string|int}  $reference
     * @param  list<array{filename: string, content_type: string, internal_path?: string, content_base64?: string}>  $attachments
     * @param  list<string>|null  $cc
     */
    public function envelope(Invoice $invoice, RenderedEmail $email, array $reference, bool $reminderHeaders, array $attachments = [], ?string $to = null, ?array $cc = null): EmailSendV2
    {
        $workspace = $invoice->workspace;
        $settings = WorkspaceProvisioner::settingsFor($workspace);
        $client = $invoice->client;
        $headers = $reminderHeaders ? [
            'List-Unsubscribe' => '<'.route('public.reminders.one-click', ['token' => $invoice->public_token]).'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ] : [];

        return new EmailSendV2(
            to: $to ?? (string) $client->email,
            subject: $email->subject,
            html: $email->html,
            text: $email->text,
            reference: $reference,
            workspaceId: $invoice->workspace_id,
            toName: $client->contact_name ?? $client->name,
            cc: $cc ?? $client->cc_emails,
            fromName: self::senderName($workspace, $settings),
            replyTo: $settings->reply_to_email ?? $settings->company_email,
            locale: $invoice->locale,
            headers: $headers,
            attachments: $attachments,
            retryProfile: 'client_mail',
        );
    }

    /** @return array{filename: string, content_type: string, internal_path: string} */
    public static function pdfAttachment(Invoice $invoice): array
    {
        return [
            'filename' => self::fileName($invoice),
            'content_type' => 'application/pdf',
            'internal_path' => '/internal/v1/payflow/invoices/'.$invoice->id.'/pdf?workspace_id='.$invoice->workspace_id,
        ];
    }

    public static function fileName(Invoice $invoice): string
    {
        return preg_replace('/[^A-Za-z0-9\-_.]/', '-', $invoice->label()).'.pdf';
    }

    public static function publicUrl(Invoice $invoice): string
    {
        return route('public.invoices.show', ['token' => $invoice->public_token]);
    }

    public static function legalUrl(string $doc, string $locale): string
    {
        return route('legal', ['doc' => $doc, 'lang' => $locale]);
    }

    public static function companyName(Workspace $workspace, PayflowSettings $settings): string
    {
        return $settings->company_legal_name ?? $workspace->name;
    }

    public static function senderName(Workspace $workspace, PayflowSettings $settings): string
    {
        return $settings->sender_display_name ?? $workspace->name;
    }

    public static function privacyContact(Workspace $workspace, PayflowSettings $settings): string
    {
        if ($settings->privacy_contact_email !== null) {
            return $settings->privacy_contact_email;
        }

        if ($settings->company_email !== null) {
            return $settings->company_email;
        }

        $owner = $workspace->members()
            ->wherePivot('role', WorkspaceRole::Owner->value)
            ->orderBy('workspace_user.created_at')
            ->first();

        return $owner->email ?? (string) config('product.support_email');
    }

    public static function longDate(string $date, string $locale): string
    {
        $day = CarbonImmutable::parse($date)->locale($locale);

        return $day instanceof CarbonImmutable ? $day->isoFormat('LL') : $date;
    }
}
