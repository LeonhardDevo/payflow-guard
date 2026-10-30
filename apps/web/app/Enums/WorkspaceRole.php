<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Finance = 'finance';
    case Approver = 'approver';
    case Member = 'member';
    case ExternalApprover = 'external_approver';

    public const array GUARD_PRIVILEGED_ABILITIES = [
        'payouts.approve',
        'bank_changes.verify',
        'payouts.release',
        'guard.configure',
        'bank_details.reveal',
        'payments.configure',
    ];

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 100,
            self::Admin => 50,
            self::Finance => 40,
            self::Approver => 40,
            self::Member => 10,
            self::ExternalApprover => 5,
        };
    }

    public function label(): string
    {
        $key = 'roles.'.$this->value;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : ucfirst(str_replace('_', ' ', $this->value));
    }

    /** @return list<string> */
    public function abilities(): array
    {
        /** @var list<string> $catalog */
        $catalog = config('permissions.abilities', []);
        /** @var list<string> $granted */
        $granted = config('permissions.roles.'.$this->value, []);

        if (in_array('*', $granted, true)) {
            return $catalog;
        }

        return array_values(array_intersect($catalog, $granted));
    }

    public function allows(string $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }

    public function isGuardPrivileged(): bool
    {
        return array_intersect(self::GUARD_PRIVILEGED_ABILITIES, $this->abilities()) !== [];
    }

    public function countsTowardSeats(): bool
    {
        return $this !== self::ExternalApprover;
    }

    public function canManage(self $other): bool
    {
        if ($this === self::Owner) {
            return true;
        }

        return $this->rank() > $other->rank();
    }

    /** @return list<self> */
    public function assignableRoles(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $role): bool => $this === self::Owner || ($role->rank() < $this->rank() && ! $role->isGuardPrivileged()),
        ));
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $role): array => ['value' => $role->value, 'label' => $role->label()],
            self::cases(),
        );
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }

    /** @return list<self> */
    public static function guardPrivileged(): array
    {
        return array_values(array_filter(self::cases(), fn (self $role): bool => $role->isGuardPrivileged()));
    }
}
