<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Clients;

use App\Domain\Collections\Models\ReminderPolicy;
use App\Domain\Receivables\LateFees\LateFeeTerms;
use App\Domain\Receivables\Models\Client;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Models\Workspace;

final class ClientTerms
{
    public static function paymentTermsDays(Client $client): int
    {
        return $client->payment_terms_days ?? WorkspaceProvisioner::settingsFor($client->workspace_id)->default_payment_terms_days;
    }

    public static function lateFeeTerms(Client $client): LateFeeTerms
    {
        return $client->lateFeeOverride() ?? WorkspaceProvisioner::settingsFor($client->workspace_id)->lateFeeTerms();
    }

    public static function lateFeeSource(Client $client): string
    {
        return $client->late_fee_type !== null ? 'client' : 'workspace';
    }

    public static function policy(Client $client): ?ReminderPolicy
    {
        if ($client->reminder_policy_id !== null) {
            $policy = ReminderPolicy::query()->forWorkspace($client->workspace_id)->with('steps')->find($client->reminder_policy_id);

            if ($policy !== null) {
                return $policy;
            }
        }

        $settings = WorkspaceProvisioner::settingsFor($client->workspace_id);

        return $settings->default_reminder_policy_id !== null
            ? ReminderPolicy::query()->forWorkspace($client->workspace_id)->with('steps')->find($settings->default_reminder_policy_id)
            : null;
    }

    public static function sendTimezone(Client $client, Workspace $workspace): string
    {
        return WorkspaceClock::zone($client->timezone ?? $workspace->timezone);
    }
}
