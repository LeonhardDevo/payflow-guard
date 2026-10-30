<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal\Payflow;

use App\Domain\Imports\ImportProcessor;
use App\Domain\Imports\Models\Import;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ImportsController extends Controller
{
    public function processBatch(Request $request, int $import, ImportProcessor $processor): JsonResponse
    {
        $data = $request->validate([
            'offset' => ['required', 'integer', 'min:0'],
            'limit' => ['required', 'integer', 'min:1', 'max:500'],
        ]);

        $model = Import::query()->withoutGlobalScopes()->find($import);

        if ($model === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json($processor->processBatch($model, (int) $data['offset'], (int) $data['limit']));
    }
}
