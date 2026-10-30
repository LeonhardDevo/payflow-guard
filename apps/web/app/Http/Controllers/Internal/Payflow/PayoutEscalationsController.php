<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal\Payflow;

use App\Domain\Guard\Escalation\GuardEscalations;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class PayoutEscalationsController extends Controller
{
    public function __invoke(GuardEscalations $escalations): JsonResponse
    {
        return response()->json($escalations->run());
    }
}
