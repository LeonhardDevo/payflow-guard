<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspaces;

use App\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class DeleteWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = app(CurrentWorkspace::class)->get();

        return $workspace !== null && ($this->user()?->can('delete', $workspace) ?? false);
    }

    /** @return array<string, array<int, ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'current_password'],
        ];
    }
}
