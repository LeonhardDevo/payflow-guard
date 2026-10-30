<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal\Payflow;

use App\Domain\Collections\Dispatch\ReminderDispatcher;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Collections\Planning\ReminderPlanner;
use App\Domain\Shared\Time\Clock;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class RemindersController extends Controller
{
    public function plan(int $workspace, ReminderPlanner $planner, Clock $clock): JsonResponse
    {
        $model = Workspace::query()->find($workspace);

        if ($model === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json($planner->plan($model, $clock->now()));
    }

    public function dispatch(Request $request, int $reminder, ReminderDispatcher $dispatcher, Clock $clock): JsonResponse
    {
        $expected = $request->input('expected_scheduled_for');

        if (! is_string($expected)) {
            return response()->json(['error' => 'validation_failed', 'errors' => ['expected_scheduled_for' => ['required']]], 422);
        }

        try {
            $expectedAt = CarbonImmutable::parse($expected)->utc();
        } catch (Throwable) {
            return response()->json(['error' => 'validation_failed', 'errors' => ['expected_scheduled_for' => ['invalid']]], 422);
        }

        if (! Reminder::query()->withoutGlobalScopes()->whereKey($reminder)->exists()) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json($dispatcher->dispatch($reminder, $expectedAt, $clock->now()));
    }
}
