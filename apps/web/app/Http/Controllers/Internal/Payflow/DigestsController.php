<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal\Payflow;

use App\Domain\Notifications\Digest\DigestService;
use App\Domain\Shared\Time\Clock;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class DigestsController extends Controller
{
    public function __invoke(DigestService $digests, Clock $clock): JsonResponse
    {
        return response()->json($digests->run($clock->now()));
    }
}
