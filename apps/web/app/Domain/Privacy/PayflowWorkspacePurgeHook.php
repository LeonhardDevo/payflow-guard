<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Imports\ImportService;
use App\Domain\Payments\Enums\LinkDeactivationReason;
use App\Domain\Payments\Links\PaymentLinkService;
use App\Domain\Payments\Models\InvoicePaymentLink;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Pdf\InvoicePdf;
use App\Models\PaymentAccount;
use App\Models\Workspace;
use App\Workspaces\Contracts\WorkspacePurgeHook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class PayflowWorkspacePurgeHook implements WorkspacePurgeHook
{
    public function __construct(private PaymentLinkService $links) {}

    public function beforePurge(Workspace $workspace): void
    {
        InvoicePaymentLink::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('invoice_id')
            ->where('active', true)
            ->get()
            ->each(function (InvoicePaymentLink $link) use ($workspace): void {
                $invoice = Invoice::query()->forWorkspace($workspace)->withTrashed()->find($link->invoice_id);

                if ($invoice !== null) {
                    $this->links->deactivateLink($invoice, $link, LinkDeactivationReason::AccountDisconnected);
                }
            });

        PaymentAccount::query()->where('workspace_id', $workspace->id)->get()->each(
            fn (PaymentAccount $account): bool => $account->forceFill(['credentials' => [], 'connected_at' => null])->save(),
        );

        Reminder::query()->forWorkspace($workspace)
            ->where('status', ReminderStatus::Scheduled->value)
            ->update(['status' => ReminderStatus::Canceled->value, 'policy_step_id' => null]);

        DB::table('trusted_confirmations')->where('workspace_id', $workspace->id)->where('status', 'pending')->update(['status' => 'canceled']);

        Storage::disk(ImportService::disk())->deleteDirectory('imports/'.$workspace->id);
        Storage::disk(InvoicePdf::disk())->deleteDirectory('invoices/'.$workspace->id);
    }
}
