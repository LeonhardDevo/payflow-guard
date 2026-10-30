<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Locale\WithLocale;
use App\Models\DataExport;
use App\Privacy\DataExports;
use App\Privacy\PrivacyPurge;
use Illuminate\Http\JsonResponse;

final class PrivacyController extends Controller
{
    public function purge(PrivacyPurge $purge): JsonResponse
    {
        return response()->json($purge->run());
    }

    public function buildExport(DataExport $export, DataExports $exports): JsonResponse
    {
        $export = WithLocale::run($export->user->locale, fn (): DataExport => $exports->build($export));

        return response()->json([
            'id' => $export->id,
            'status' => $export->status,
            'size_bytes' => $export->size_bytes,
            'expires_at' => $export->expires_at?->toIso8601String(),
        ], $export->status === DataExport::FAILED ? 500 : 200);
    }
}
