<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;

it('grants owners every ability of the catalog', function (): void {
    expect(WorkspaceRole::Owner->abilities())->toEqualCanonicalizing(config('permissions.abilities'));
});

it('grants admins management abilities except deleting the workspace and billing changes', function (): void {
    $admin = WorkspaceRole::Admin;

    expect($admin->allows('members.manage'))->toBeTrue()
        ->and($admin->allows('audit.view'))->toBeTrue()
        ->and($admin->allows('billing.view'))->toBeTrue()
        ->and($admin->allows('billing.manage'))->toBeFalse()
        ->and($admin->allows('workspace.delete'))->toBeFalse();
});

it('limits members to using the product', function (): void {
    expect(WorkspaceRole::Member->abilities())->toEqualCanonicalizing([
        'knowledge.view', 'assistant.use', 'integrations.manage', 'clients.view', 'invoices.view', 'analytics.view',
        'clients.manage', 'invoices.manage', 'reminders.send',
    ]);
});

it('never grants abilities missing from the catalog', function (): void {
    config(['permissions.roles.member' => ['assistant.use', 'not.in.catalog']]);

    expect(WorkspaceRole::Member->abilities())->toBe(['assistant.use']);
});

it('applies the management hierarchy', function (): void {
    expect(WorkspaceRole::Owner->canManage(WorkspaceRole::Owner))->toBeTrue()
        ->and(WorkspaceRole::Admin->canManage(WorkspaceRole::Member))->toBeTrue()
        ->and(WorkspaceRole::Admin->canManage(WorkspaceRole::Admin))->toBeFalse()
        ->and(WorkspaceRole::Admin->canManage(WorkspaceRole::Owner))->toBeFalse()
        ->and(WorkspaceRole::Member->canManage(WorkspaceRole::Member))->toBeFalse();
});

it('lets owners assign any role and admins only lower ones', function (): void {
    expect(WorkspaceRole::Owner->assignableRoles())->toBe(WorkspaceRole::cases())
        ->and(WorkspaceRole::Admin->assignableRoles())->toBe([WorkspaceRole::Member])
        ->and(WorkspaceRole::Member->assignableRoles())->toBe([]);
});

it('falls back to a readable label when no translation exists', function (): void {
    expect(WorkspaceRole::Admin->label())->toBeString()->not->toBe('roles.admin');
});
