<?php

declare(strict_types=1);

namespace App\Domain\Collections\Templates;

use App\Domain\Collections\Enums\TemplateKey;
use App\Domain\Collections\Models\MessageTemplate;
use App\Models\Workspace;

final class DefaultTemplates
{
    public const array LOCALES = ['en', 'ru'];

    /** @return array{subject: string, body: string} */
    public static function text(TemplateKey $key, string $locale): array
    {
        return [
            'subject' => (string) trans("payflow.templates.{$key->value}.subject", [], $locale),
            'body' => (string) trans("payflow.templates.{$key->value}.body", [], $locale),
        ];
    }

    public static function ensureFor(Workspace $workspace): void
    {
        foreach (self::LOCALES as $locale) {
            foreach (TemplateKey::cases() as $key) {
                $exists = MessageTemplate::query()
                    ->forWorkspace($workspace)
                    ->where('key', $key->value)
                    ->where('locale', $locale)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $text = self::text($key, $locale);
                $template = new MessageTemplate;
                $template->forceFill([
                    'workspace_id' => $workspace->id,
                    'key' => $key,
                    'locale' => $locale,
                    'subject' => $text['subject'],
                    'body_markdown' => $text['body'],
                    'is_customized' => false,
                ])->save();
            }
        }
    }

    /** @return array{subject: string, body: string} */
    public static function effective(int $workspaceId, TemplateKey $key, string $locale): array
    {
        $template = MessageTemplate::query()
            ->forWorkspace($workspaceId)
            ->where('key', $key->value)
            ->where('locale', $locale)
            ->first();

        if ($template === null || ! $template->is_customized) {
            return self::text($key, $locale);
        }

        return ['subject' => $template->subject, 'body' => $template->body_markdown];
    }
}
