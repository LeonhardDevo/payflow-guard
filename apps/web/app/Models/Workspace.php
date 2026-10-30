<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceRole;
use Carbon\CarbonInterface;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property string $currency
 * @property string|null $country
 * @property string $locale
 * @property string|null $stripe_customer_id
 * @property int|null $created_by
 * @property bool $ai_external_allowed
 * @property array<string, int> $retention
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Subscription|null $subscription
 * @property-read WorkspaceMembership|null $membership
 */
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug', 'timezone', 'currency', 'country', 'locale', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'ai_external_allowed' => false,
        'retention' => '{}',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ai_external_allowed' => 'boolean',
            'retention' => 'array',
        ];
    }

    /** @return BelongsToMany<User, $this, WorkspaceMembership, 'membership'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->using(WorkspaceMembership::class)
            ->as('membership')
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    /** @return HasMany<WorkspaceInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    /** @return HasOne<Subscription, $this> */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function planKey(): string
    {
        $subscription = $this->subscription;

        if ($subscription !== null && $subscription->grantsPlan()) {
            return $subscription->plan;
        }

        return (string) config('billing.default_plan', 'free');
    }

    public function addMember(User $user, WorkspaceRole $role): void
    {
        $this->members()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);
    }

    public function ownersCount(): int
    {
        return $this->members()->wherePivot('role', WorkspaceRole::Owner->value)->count();
    }
}
