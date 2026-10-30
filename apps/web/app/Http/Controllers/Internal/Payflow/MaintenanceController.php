<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal\Payflow;

use App\Domain\Privacy\PayflowRetention;
use App\Domain\Shared\Time\Clock;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class MaintenanceController extends Controller
{
    public function __invoke(PayflowRetention $retention, Clock $clock): JsonResponse
    {
        return response()->json(['purged' => $retention->purge($clock->now())]);
    }
}
