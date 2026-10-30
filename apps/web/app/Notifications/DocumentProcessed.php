<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Support\Str;

final class DocumentProcessed extends InAppNotification
{
    public function __construct(public readonly Document $document) {}

    public function inApp(object $notifiable): InAppMessage
    {
        $failed = $this->document->status === DocumentStatus::Failed;

        return new InAppMessage(
            kind: $failed ? 'danger' : 'success',
            titleKey: $failed ? 'notifications.in_app.document_failed.title' : 'notifications.in_app.document_indexed.title',
            titleParams: ['title' => Str::limit($this->document->title, 120)],
            bodyKey: $failed ? 'notifications.in_app.document_failed.body' : 'notifications.in_app.document_indexed.body',
            bodyParams: $failed
                ? ['error' => Str::limit((string) $this->document->error, 200)]
                : ['chunks' => (int) $this->document->chunks],
            href: route('knowledge.show', ['document' => $this->document->id], absolute: false),
            workspaceId: $this->document->workspace_id,
        );
    }
}
