<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Billing\PlanLimits;
use App\Domain\Collections\Dispatch\ManualReminders;
use App\Domain\Collections\Drafts\ReminderDraftAssistant;
use App\Domain\Collections\Enums\Tone;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Exceptions\PayflowException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class InvoiceRemindersController extends PayflowController
{
    public function preview(Request $request, Invoice $invoice, ManualReminders $reminders): JsonResponse
    {
        Gate::authorize('remind', $invoice);
        $data = $request->validate(['tone' => ['nullable', Rule::enum(Tone::class)]]);

        return response()->json($reminders->preview($invoice, isset($data['tone']) ? Tone::from((string) $data['tone']) : null));
    }

    public function store(Request $request, Invoice $invoice, ManualReminders $reminders): RedirectResponse
    {
        Gate::authorize('remind', $invoice);
        $data = $request->validate([
            'tone' => ['required', Rule::enum(Tone::class)],
            'subject' => ['nullable', 'string', 'max:255'],
            'body_markdown' => ['nullable', 'string', 'max:5000'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $reminders->send(
            $invoice,
            $this->user($request),
            Tone::from((string) $data['tone']),
            isset($data['subject']) && $data['subject'] !== '' ? (string) $data['subject'] : null,
            isset($data['body_markdown']) && $data['body_markdown'] !== '' ? (string) $data['body_markdown'] : null,
            (bool) ($data['force'] ?? false),
        );

        return $this->back('reminder_queued');
    }

    public function aiDraft(Request $request, Invoice $invoice, ReminderDraftAssistant $assistant, PlanLimits $limits): JsonResponse
    {
        Gate::authorize('remind', $invoice);

        if (! $limits->hasFeature($this->workspace(), 'ai_drafts')) {
            throw new PayflowException('entitlement_required', 403);
        }

        $data = $request->validate(['tone' => ['required', Rule::enum(Tone::class)]]);

        return response()->json($assistant->draft($invoice, $this->user($request), Tone::from((string) $data['tone'])));
    }
}
