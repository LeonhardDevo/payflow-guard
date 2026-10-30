<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

use App\Audit\Auditor;
use App\Domain\Guard\Models\Vendor;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Shared\Support\Iso;
use App\Models\User;
use App\Models\Workspace;

final readonly class UnmaskedBankExport
{
    public function __construct(private Auditor $auditor) {}

    /** @return array{workspace: array{id: int, name: string}, generated_at: string, vendors: list<array<string, mixed>>} */
    public function build(Workspace $workspace, User $owner, string $generatedAt): array
    {
        $vendors = Vendor::query()->forWorkspace($workspace)->withTrashed()->orderBy('id')->get();
        $rows = [];

        foreach ($vendors as $vendor) {
            $rows[] = [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'legal_name' => $vendor->legal_name,
                'tax_id' => $vendor->tax_id,
                'accounts' => VendorBankAccount::query()->forWorkspace($workspace)
                    ->where('vendor_id', $vendor->id)
                    ->orderBy('version')
                    ->get()
                    ->map(fn (VendorBankAccount $account): array => [
                        'version' => $account->version,
                        'status' => $account->status->value,
                        'scheme' => $account->scheme->value,
                        'account_holder_name' => $account->account_holder_name,
                        'iban' => $account->iban,
                        'account_number' => $account->account_number,
                        'routing_number' => $account->routing_number,
                        'swift_bic' => $account->swift_bic,
                        'bank_name' => $account->bank_name,
                        'bank_country' => $account->bank_country,
                        'currency' => $account->currency,
                        'verified_at' => Iso::dateTime($account->verified_at),
                    ])
                    ->values()
                    ->all(),
            ];
        }

        $this->auditor->record('payflow.export.unmasked', $workspace, metadata: ['vendors' => count($rows)], actor: $owner, workspace: $workspace, subjectLabel: $workspace->name);

        return ['workspace' => ['id' => $workspace->id, 'name' => $workspace->name], 'generated_at' => $generatedAt, 'vendors' => $rows];
    }
}
