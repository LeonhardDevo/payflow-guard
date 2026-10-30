<?php

declare(strict_types=1);

namespace App\Policies\Payflow;

use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Models\Invoice;
use App\Models\User;

final class InvoicePolicy
{
    use WorkspaceAbilities;

    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice, 'invoices.view');
    }

    public function create(User $user): bool
    {
        return $user->can('invoices.manage');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice, 'invoices.manage')
            && in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Open, InvoiceStatus::PartiallyPaid], true);
    }

    public function manage(User $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice, 'invoices.manage');
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice, 'invoices.manage') && $invoice->status === InvoiceStatus::Draft;
    }

    public function recordPayment(User $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice, 'invoices.record_payment');
    }

    public function writeOff(User $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice, 'invoices.write_off');
    }

    public function applyLateFee(User $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice, 'invoices.apply_late_fee');
    }

    public function remind(User $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice, 'reminders.send');
    }

    public function configureReminders(User $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice, 'reminders.configure');
    }
}
