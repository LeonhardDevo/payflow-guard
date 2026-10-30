<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Enums\AiChannel;
use Illuminate\Validation\Rule;

final class AiQueryRequest extends InternalRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'workspace_id' => ['required', 'integer', Rule::exists('workspaces', 'id')],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'channel' => ['required', 'string', Rule::in(AiChannel::values())],
            'question' => ['required', 'string', 'max:10000'],
            'answer' => ['nullable', 'string', 'max:100000'],
            'refused' => ['required', 'boolean'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'citations' => ['present', 'array'],
            'citations.*.document_id' => ['required', 'integer'],
            'citations.*.title' => ['required', 'string', 'max:500'],
            'citations.*.page' => ['nullable', 'integer'],
            'tools_used' => ['present', 'array'],
            'tools_used.*' => ['string', 'max:120'],
            'latency_ms' => ['nullable', 'integer', 'min:0'],
            'provider' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:160'],
        ];
    }
}
