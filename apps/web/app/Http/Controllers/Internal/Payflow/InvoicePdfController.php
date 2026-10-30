<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal\Payflow;

use App\Domain\Collections\Email\ClientEmailComposer;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Pdf\InvoicePdf;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class InvoicePdfController extends Controller
{
    public function __invoke(Request $request, int $invoice): Response
    {
        $model = Invoice::query()->withoutGlobalScopes()->whereKey($invoice)->first();
        $workspaceId = $request->query('workspace_id');

        if ($model === null || ! is_numeric($workspaceId) || (int) $workspaceId !== $model->workspace_id) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response(InvoicePdf::render($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.ClientEmailComposer::fileName($model).'"',
        ]);
    }
}
