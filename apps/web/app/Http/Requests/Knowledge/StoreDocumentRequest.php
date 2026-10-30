<?php

declare(strict_types=1);

namespace App\Http\Requests\Knowledge;

use App\Knowledge\DocumentFileInspector;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

final class StoreDocumentRequest extends FormRequest
{
    use DocumentMetadataRules;

    private ?string $sniffedMime = null;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Document::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.DocumentFileInspector::maxSizeKilobytes()],
            ...$this->metadataRules(titleRequired: false),
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('file')) {
                    return;
                }

                $file = $this->file('file');
                $mime = $file instanceof UploadedFile ? app(DocumentFileInspector::class)->inspect($file) : null;

                if ($mime === null) {
                    $validator->errors()->add('file', (string) __('validation.document_type', [
                        'types' => implode(', ', DocumentFileInspector::acceptList()),
                    ]));

                    return;
                }

                $this->sniffedMime = $mime;
            },
        ];
    }

    public function uploadedFile(): UploadedFile
    {
        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            throw new \LogicException('Validated request has no file.');
        }

        return $file;
    }

    public function sniffedMime(): string
    {
        return $this->sniffedMime ?? throw new \LogicException('MIME not inspected yet.');
    }
}
