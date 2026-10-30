<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Audit\Auditor;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Legal\LegalAcceptances;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Database\Seeders\Payflow\PayflowDemoSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(Auditor $auditor): void
    {
        $slug = (string) config('product.slug', 'app');

        $admin = $this->user("admin@{$slug}.test", 'Platform Admin', 'en');
        $admin->forceFill(['is_platform_admin' => true])->save();

        $owner = $this->user("demo@{$slug}.test", 'Alex Morgan', 'en');
        $member = $this->user("member@{$slug}.test", 'Мария Иванова', 'ru');

        $workspace = Workspace::query()->firstOrCreate(
            ['slug' => 'demo-studio'],
            [
                'name' => 'Demo Studio',
                'timezone' => 'Europe/Berlin',
                'currency' => 'USD',
                'locale' => 'en',
                'created_by' => $owner->id,
            ],
        );

        $fresh = $workspace->wasRecentlyCreated;

        $workspace->addMember($owner, WorkspaceRole::Owner);
        $workspace->addMember($admin, WorkspaceRole::Admin);
        $workspace->addMember($member, WorkspaceRole::Member);

        foreach ([$owner, $admin, $member] as $user) {
            $user->forceFill(['current_workspace_id' => $workspace->id])->save();
        }

        $this->acceptLegalDocuments([$owner, $admin, $member], $workspace, $owner);

        Subscription::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'gateway' => config('billing.driver') === 'fake' ? 'fake' : 'stripe',
                'plan' => 'pro',
                'status' => SubscriptionStatus::Active,
                'interval' => 'month',
                'currency' => 'USD',
                'payment_failed_at' => null,
                'canceled_at' => null,
                'scheduled_plan' => null,
                'scheduled_interval' => null,
                'stripe_customer_id' => 'cus_fake_demo',
                'stripe_subscription_id' => 'sub_fake_demo',
                'current_period_end' => now()->addMonth(),
                'cancel_at_period_end' => false,
            ],
        );

        $invitation = WorkspaceInvitation::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'email' => "invitee@{$slug}.test"],
            [
                'role' => WorkspaceRole::Member,
                'token_hash' => WorkspaceInvitation::hashToken(Str::random(64)),
                'invited_by' => $owner->id,
                'expires_at' => now()->addDays(WorkspaceInvitation::TTL_DAYS),
            ],
        );

        if ($fresh) {
            $auditor->record('workspace.created', $workspace, actor: $owner, workspace: $workspace, subjectLabel: $workspace->name);
            $auditor->record(
                'member.role_changed',
                $admin,
                ['role' => [WorkspaceRole::Member->value, WorkspaceRole::Admin->value]],
                actor: $owner,
                workspace: $workspace,
                subjectLabel: $admin->name,
            );
            $auditor->record(
                'invitation.accepted',
                $member,
                metadata: ['email' => $member->email, 'role' => WorkspaceRole::Member->value],
                actor: $member,
                workspace: $workspace,
                subjectLabel: $member->email,
            );
            $auditor->record(
                'invitation.created',
                $invitation,
                metadata: ['email' => $invitation->email, 'role' => WorkspaceRole::Member->value],
                actor: $owner,
                workspace: $workspace,
                subjectLabel: $invitation->email,
            );
        }

        $this->call(PayflowDemoSeeder::class);
    }

    /** @param  list<User>  $users */
    private function acceptLegalDocuments(array $users, Workspace $workspace, User $owner): void
    {
        $acceptances = app(LegalAcceptances::class);

        foreach ($users as $user) {
            if ($acceptances->pending($user, null, $user->locale) !== []) {
                $acceptances->acceptRegistration($user, $user->locale, null);
            }
        }

        if ($acceptances->pending($owner, $workspace, $owner->locale) !== []) {
            $acceptances->acceptWorkspace($owner, $workspace, $owner->locale, null);
        }
    }

    private function user(string $email, string $name, string $locale): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->forceFill([
                'name' => $name,
                'password' => 'password',
                'locale' => $locale,
                'email_verified_at' => now(),
            ])->save();
        }

        return $user;
    }
}
