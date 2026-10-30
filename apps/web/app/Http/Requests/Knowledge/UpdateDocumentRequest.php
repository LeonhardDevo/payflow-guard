<?php

declare(strict_types=1);

namespace App\Http\Requests\Knowledge;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateDocumentRequest extends FormRequest
{
    use DocumentMetadataRules;

    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof Document && ($this->user()?->can('update', $document) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = $this->metadataRules(titleRequired: true);
        $rules['title'] = ['sometimes', ...$rules['title']];

        return $rules;
    }
}
