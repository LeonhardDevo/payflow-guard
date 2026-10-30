<?php

declare(strict_types=1);

namespace App\Domain\Guard\StepUp;

use App\Support\Flash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class StepUpRequired extends RuntimeException
{
    /** @param  list<string>  $methods */
    public function __construct(
        public readonly string $errorCode,
        public readonly array $methods = [],
    ) {
        parent::__construct($errorCode);
    }

    public static function stepUp(Request $request): self
    {
        return new self('step_up_required', StepUpGate::methodsFor($request));
    }

    public static function secondFactor(): self
    {
        return new self('second_factor_required');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        $status = $this->errorCode === 'step_up_required' ? 423 : 403;

        if ($request->expectsJson() && $request->header('X-Inertia') === null) {
            return response()->json($this->errorCode === 'step_up_required'
                ? ['code' => $this->errorCode, 'methods' => $this->methods]
                : ['code' => $this->errorCode, 'message' => (string) __('payflow.errors.second_factor_required'), 'setup_url' => route('security.edit')], $status);
        }

        Flash::error('payflow.errors.'.$this->errorCode);

        if ($request->hasSession()) {
            $request->session()->flash($this->errorCode === 'step_up_required' ? 'pf_step_up_required' : 'pf_second_factor_required', true);
        }

        return redirect()->back(303);
    }
}
