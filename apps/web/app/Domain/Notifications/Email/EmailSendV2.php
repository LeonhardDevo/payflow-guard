<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Email;

use InvalidArgumentException;

final readonly class EmailSendV2
{
    public const array HEADER_ALLOW_LIST = ['List-Unsubscribe', 'List-Unsubscribe-Post'];

    /**
     * @param  list<string>  $cc
     * @param  array<string, string>  $headers
     * @param  list<array{filename: string, content_type: string, internal_path?: string, content_base64?: string}>  $attachments
     * @param  array{type: string, id: string|int}  $reference
     */
    public function __construct(
        public string $to,
        public string $subject,
        public ?string $html,
        public ?string $text,
        public array $reference,
        public ?int $workspaceId = null,
        public ?string $toName = null,
        public array $cc = [],
        public ?string $fromName = null,
        public ?string $replyTo = null,
        public ?string $locale = null,
        public array $headers = [],
        public array $attachments = [],
        public string $retryProfile = 'default',
    ) {
        if ($html === null && $text === null) {
            throw new InvalidArgumentException('An e-mail needs an HTML or a text body.');
        }

        foreach (array_keys($headers) as $name) {
            if (! in_array($name, self::HEADER_ALLOW_LIST, true) && ! str_starts_with($name, 'X-PF-')) {
                throw new InvalidArgumentException("Header [{$name}] is not allowed.");
            }
        }
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        $payload = [
            'v' => 2,
            'to' => $this->to,
            'to_name' => $this->toName,
            'cc' => array_slice($this->cc, 0, 5),
            'from_name' => $this->fromName !== null ? mb_substr($this->fromName, 0, 120) : null,
            'reply_to' => $this->replyTo,
            'subject' => mb_substr($this->subject, 0, 998),
            'locale' => $this->locale,
            'headers' => $this->headers,
            'attachments' => $this->attachments,
            'reference' => ['type' => $this->reference['type'], 'id' => $this->reference['id']],
            'workspace_id' => $this->workspaceId,
            'retry_profile' => $this->retryProfile,
        ];

        if ($this->html !== null) {
            $payload['html'] = $this->html;
        }

        if ($this->text !== null) {
            $payload['text'] = $this->text;
        }

        if ($this->headers === []) {
            unset($payload['headers']);
        }

        return $payload;
    }
}
