<?php

declare(strict_types=1);

namespace App\Domain\Guard;

use App\Domain\Shared\Exceptions\PayflowException;
use App\Support\Flash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class GuardException extends PayflowException
{
    /**
     * @param  array<string, string|int>  $replace
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $errorCode, int $status = 409, array $replace = [], public readonly array $details = [])
    {
        parent::__construct($errorCode, $status, $replace);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && $request->header('X-Inertia') === null) {
            return response()->json(['message' => $this->userMessage(), 'code' => $this->errorCode, ...$this->details], $this->status);
        }

        Flash::errorMessage($this->userMessage());

        if ($request->hasSession()) {
            $request->session()->flash('pf_guard_error', ['code' => $this->errorCode, ...$this->details]);
        }

        return redirect()->back(303);
    }
}
