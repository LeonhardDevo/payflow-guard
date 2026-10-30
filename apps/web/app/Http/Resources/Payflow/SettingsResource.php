<?php

declare(strict_types=1);

namespace App\Http\Resources\Payflow;

use App\Domain\Collections\Models\MessageTemplate;
use App\Domain\Collections\Models\ReminderPolicy;
use App\Domain\Collections\Models\ReminderPolicyStep;
use App\Domain\Guard\Enums\ReceivingDetailsStatus;
use App\Domain\Guard\Models\ReceivingDetailsVersion;
use App\Domain\Receivables\Models\Client;
use App\Domain\Shared\Models\PayflowSettings;
use App\Domain\Shared\Support\Iso;
use App\Domain\Shared\Support\UserRef;
use App\Models\Workspace;

final class SettingsResource
{
    /** @return array<string, mixed> */
    public static function collections(PayflowSettings $settings, Workspace $workspace): array
    {
        $active = $settings->active_receiving_details_id !== null
            ? ReceivingDetailsVersion::query()->forWorkspace($workspace)->find($settings->active_receiving_details_id)
            : null;
        $pending = ReceivingDetailsVersion::query()->forWorkspace($workspace)
            ->whereIn('status', [ReceivingDetailsStatus::PendingConfirmation->value, ReceivingDetailsStatus::Scheduled->value])
            ->latest('id')
            ->first();

        return [
            'invoice_prefix' => $settings->invoice_prefix,
            'next_invoice_seq' => $settings->next_invoice_seq,
            'default_payment_terms_days' => $settings->default_payment_terms_days,
            'default_reminder_policy_id' => $settings->default_reminder_policy_id,
            'send_window_start' => $settings->windowStart(),
            'send_window_end' => $settings->windowEnd(),
            'business_days_only' => $settings->business_days_only,
            'first_contact_max_per_hour' => $settings->first_contact_max_per_hour,
            'sender_display_name' => $settings->sender_display_name,
            'reply_to_email' => $settings->reply_to_email,
            'privacy_contact_email' => $settings->privacy_contact_email,
            'track_invoice_views' => $settings->track_invoice_views,
            'late_fee' => $settings->lateFeeTerms()->toContract($workspace->currency),
            'company' => [
                'legal_name' => $settings->company_legal_name,
                'address' => $settings->company_address,
                'tax_id' => $settings->company_tax_id,
                'email' => $settings->company_email,
                'phone' => $settings->company_phone,
            ],
            'receiving_details' => [
                'active' => $active !== null ? self::receivingDetails($active) : null,
                'pending' => $pending !== null ? self::receivingDetails($pending) : null,
            ],
            'digest_enabled' => $settings->digest_enabled,
            'digest_hour' => $settings->digest_hour,
        ];
    }

    /** @return array<string, mixed> */
    public static function receivingDetails(ReceivingDetailsVersion $version): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'details' => $version->details,
            'status' => $version->status->value,
            'requested_by' => UserRef::of($version->requester),
            'requested_at' => Iso::dateTime($version->requested_at),
            'confirmation_mode' => $version->confirmation_mode?->value,
            'confirmed_by' => UserRef::of($version->confirmer),
            'confirmed_at' => Iso::dateTime($version->confirmed_at),
            'effective_at' => Iso::dateTime($version->effective_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function policy(ReminderPolicy $policy, ?int $clientsCount = null): array
    {
        return [
            'id' => $policy->id,
            'name' => $policy->name,
            'description' => $policy->description,
            'is_default' => $policy->is_default,
            'max_reminders' => $policy->max_reminders,
            'min_interval_days' => $policy->min_interval_days,
            'escalate_after_days' => $policy->escalate_after_days,
            'steps' => $policy->steps->sortBy('position')->map(fn (ReminderPolicyStep $step): array => [
                'id' => $step->id,
                'position' => $step->position,
                'offset_days' => $step->offset_days,
                'tone' => $step->tone->value,
                'attach_pdf' => $step->attach_pdf,
                'include_payment_link' => $step->include_payment_link,
                'include_late_fee_notice' => $step->include_late_fee_notice,
                'notify_owner' => $step->notify_owner,
            ])->values()->all(),
            'clients_count' => $clientsCount ?? Client::query()->forWorkspace($policy->workspace_id)->where('reminder_policy_id', $policy->id)->count(),
        ];
    }

    /** @return array<string, mixed> */
    public static function template(MessageTemplate $template): array
    {
        return [
            'id' => $template->id,
            'key' => $template->key->value,
            'locale' => $template->locale,
            'subject' => $template->subject,
            'body_markdown' => $template->body_markdown,
            'is_customized' => $template->is_customized,
            'updated_by' => UserRef::of($template->editor),
            'updated_at' => Iso::dateTime($template->updated_at),
        ];
    }
}
