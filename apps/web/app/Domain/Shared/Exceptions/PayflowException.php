<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use App\Support\Flash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PayflowException extends RuntimeException
{
    /** @param  array<string, string|int>  $replace */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status = 409,
        public readonly array $replace = [],
    ) {
        parent::__construct($errorCode);
    }

    public function userMessage(): string
    {
        $key = 'payflow.errors.'.$this->errorCode;
        $message = trans($key, $this->replace);

        return is_string($message) && $message !== $key ? $message : (string) trans('payflow.errors.generic');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && $request->header('X-Inertia') === null) {
            return response()->json(['message' => $this->userMessage(), 'code' => $this->errorCode], $this->status);
        }

        Flash::errorMessage($this->userMessage());

        return redirect()->back(303);
    }
}
