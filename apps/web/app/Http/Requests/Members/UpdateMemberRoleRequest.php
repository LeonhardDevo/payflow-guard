<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use App\Enums\WorkspaceRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class UpdateMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('members.manage') ?? false;
    }

    /** @return array<string, array<int, ValidationRule|string|Enum>> */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::enum(WorkspaceRole::class)],
        ];
    }

    public function role(): WorkspaceRole
    {
        return WorkspaceRole::from((string) $this->validated('role'));
    }
}
