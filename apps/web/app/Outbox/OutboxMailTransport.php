<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Privacy\EmailSuppressions;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

final class OutboxMailTransport extends AbstractTransport
{
    public const string HEADER_REFERENCE_TYPE = 'X-Outbox-Reference-Type';

    public const string HEADER_REFERENCE_ID = 'X-Outbox-Reference-Id';

    public const string HEADER_WORKSPACE = 'X-Workspace-Id';

    public const string HEADER_LOCALE = 'X-Locale';

    public const string HEADER_AUTOMATED = 'X-Automated';

    public function __construct(
        private readonly Outbox $outbox,
        private readonly EmailSuppressions $suppressions,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        if (! $original instanceof Message) {
            throw new TransportException('The outbox transport only accepts structured (non-raw) messages.');
        }

        $email = MessageConverter::toEmail($original);
        $headers = $email->getHeaders();

        $referenceType = $headers->getHeaderBody(self::HEADER_REFERENCE_TYPE);
        $referenceId = $headers->getHeaderBody(self::HEADER_REFERENCE_ID);
        $workspaceHeader = $headers->getHeaderBody(self::HEADER_WORKSPACE);
        $localeHeader = $headers->getHeaderBody(self::HEADER_LOCALE);

        $workspaceId = is_numeric($workspaceHeader) ? (int) $workspaceHeader : null;
        $reference = [
            'type' => is_string($referenceType) && $referenceType !== '' ? $referenceType : 'mail',
            'id' => is_string($referenceId) && $referenceId !== '' ? $referenceId : $message->getMessageId(),
        ];

        $from = $email->getFrom()[0] ?? null;
        $replyTo = $email->getReplyTo()[0] ?? null;

        $base = [
            'subject' => (string) $email->getSubject(),
            'html' => self::bodyToString($email->getHtmlBody()),
            'text' => self::bodyToString($email->getTextBody()),
            'locale' => is_string($localeHeader) && $localeHeader !== '' ? $localeHeader : App::getLocale(),
            'reference' => $reference,
            'workspace_id' => $workspaceId,
            'from' => $from !== null ? self::address($from) : null,
            'reply_to' => $replyTo !== null ? self::address($replyTo) : null,
            'attachments' => self::attachments($email),
        ];

        $automated = in_array(strtolower((string) $headers->getHeaderBody(self::HEADER_AUTOMATED)), ['1', 'true', 'yes'], true);

        foreach ($message->getEnvelope()->getRecipients() as $recipient) {
            $address = $recipient->getAddress();
            $extraHeaders = [];

            if ($automated) {
                if ($this->suppressions->isSuppressed($address, $workspaceId)) {
                    Log::info('automated e-mail skipped: recipient opted out', ['reference' => $reference, 'workspace_id' => $workspaceId]);

                    continue;
                }

                $extraHeaders = [
                    'List-Unsubscribe' => '<'.EmailSuppressions::url($address, $workspaceId).'>',
                    'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                    'Auto-Submitted' => 'auto-generated',
                ];
            }

            $this->outbox->publish(
                Topics::EMAIL_SEND,
                [
                    'to' => $address,
                    'to_name' => $recipient->getName() ?: null,
                    ...$base,
                    ...($extraHeaders !== [] ? ['headers' => $extraHeaders] : []),
                ],
                $workspaceId,
            );
        }
    }

    public function __toString(): string
    {
        return 'outbox://default';
    }

    /** @param  resource|string|null  $body */
    private static function bodyToString(mixed $body): ?string
    {
        if (is_resource($body)) {
            $contents = stream_get_contents($body);

            return $contents === false ? null : $contents;
        }

        return is_string($body) ? $body : null;
    }

    /** @return array{address: string, name: string|null} */
    private static function address(Address $address): array
    {
        return ['address' => $address->getAddress(), 'name' => $address->getName() ?: null];
    }

    /** @return list<array{filename: string|null, content_type: string, content_base64: string}> */
    private static function attachments(Email $email): array
    {
        return array_values(array_map(
            fn (DataPart $part): array => [
                'filename' => $part->getFilename(),
                'content_type' => $part->getMediaType().'/'.$part->getMediaSubtype(),
                'content_base64' => base64_encode($part->getBody()),
            ],
            $email->getAttachments(),
        ));
    }
}
