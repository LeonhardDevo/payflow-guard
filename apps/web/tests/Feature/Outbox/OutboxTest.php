<?php

declare(strict_types=1);

use App\Models\OutboxMessage;
use App\Models\User;
use App\Outbox\Outbox;
use App\Outbox\OutboxMailTransport;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

function recordNotifies(): ArrayObject
{
    $notifies = new ArrayObject;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($notifies): void {
        if (str_starts_with($query->sql, 'NOTIFY')) {
            $notifies->append($query->sql);
        }
    });

    return $notifies;
}

it('stores the contract columns', function (): void {
    $message = app(Outbox::class)->publish('document.uploaded', ['document_id' => 1, 'workspace_id' => 2], 2);

    $row = (array) DB::table('outbox_messages')->where('id', $message->id)->first();

    expect($row['topic'])->toBe('document.uploaded')
        ->and(json_decode($row['payload'], true))->toEqual(['document_id' => 1, 'workspace_id' => 2])
        ->and((int) $row['workspace_id'])->toBe(2)
        ->and($row['dispatched_at'])->toBeNull()
        ->and((int) $row['attempts'])->toBe(0)
        ->and($row['available_at'])->not->toBeNull()
        ->and($message->id)->toMatch('/^[0-9a-f-]{36}$/');
});

it('supports delayed availability', function (): void {
    $message = app(Outbox::class)->publish('telegram.notify', ['chat_id' => 1], null, now()->addHour());

    expect($message->fresh()->available_at->isFuture())->toBeTrue();
});

it('sends NOTIFY outbox only after the surrounding transaction commits', function (): void {
    $notifies = recordNotifies();

    DB::transaction(function () use ($notifies): void {
        app(Outbox::class)->publish('workspace.reembed', ['workspace_id' => 1]);
        expect($notifies->count())->toBe(0);
    });

    expect($notifies->getArrayCopy())->toBe(['NOTIFY outbox']);
});

it('does not notify (nor keep the row) when the transaction rolls back', function (): void {
    $notifies = recordNotifies();

    try {
        DB::transaction(function (): void {
            app(Outbox::class)->publish('workspace.reembed', ['workspace_id' => 1]);

            throw new RuntimeException('business failure');
        });
    } catch (RuntimeException) {
    }

    expect($notifies->count())->toBe(0)
        ->and(OutboxMessage::query()->count())->toBe(0);
});

it('has a partial index for pending rows', function (): void {
    $definition = DB::table('pg_indexes')->where('indexname', 'outbox_messages_pending_idx')->value('indexdef');

    expect($definition)->toContain('(available_at)')->toContain('WHERE (dispatched_at IS NULL)');
});

final class OutboxProbeMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Probe subject');
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            OutboxMailTransport::HEADER_REFERENCE_TYPE => 'probe',
            OutboxMailTransport::HEADER_REFERENCE_ID => '99',
            OutboxMailTransport::HEADER_WORKSPACE => '7',
        ]);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Hello <strong>probe</strong></p>');
    }
}

it('turns every mail into one email.send message per recipient', function (): void {
    config(['mail.default' => 'outbox']);

    Mail::to(['a@example.com', 'b@example.com'])->bcc('audit@example.com')->send(new OutboxProbeMail);

    $messages = OutboxMessage::query()->topic('email.send')->orderBy('created_at')->get();

    expect($messages)->toHaveCount(3)
        ->and($messages->pluck('payload.to')->sort()->values()->all())->toBe(['a@example.com', 'audit@example.com', 'b@example.com']);

    $payload = $messages->first()->payload;

    expect($payload)->toMatchArray([
        'subject' => 'Probe subject',
        'locale' => 'en',
        'reference' => ['type' => 'probe', 'id' => '99'],
        'workspace_id' => 7,
        'from' => ['address' => config('mail.from.address'), 'name' => config('mail.from.name')],
    ])->and($payload['html'])->toContain('<strong>probe</strong>')
        ->and($messages->first()->workspace_id)->toBe(7);
});

it('delivers Fortify verification emails through the outbox in the user locale', function (): void {
    config(['mail.default' => 'outbox']);
    $user = User::factory()->unverified()->russian()->create(['name' => 'Ольга']);

    $user->sendEmailVerificationNotification();

    $payload = OutboxMessage::query()->topic('email.send')->sole()->payload;

    expect($payload['to'])->toBe($user->email)
        ->and($payload['locale'])->toBe('ru')
        ->and($payload['subject'])->toBe('Подтвердите почту для '.config('app.name'))
        ->and($payload['html'])->toContain('Здравствуйте, Ольга!')
        ->and($payload['html'])->toContain('Подтвердить почту')
        ->and($payload['html'])->toContain('lang="ru"')
        ->and($payload['html'])->not->toContain('laravel.com')
        ->and($payload['text'])->toContain('Подтвердить почту')
        ->and($payload['reference']['type'])->toBe('verify_email');

    preg_match('#href="([^"]+/email/verify/[^"]+)"#', $payload['html'], $match);
    expect($match)->not->toBeEmpty();
});

it('delivers password reset emails localized in English', function (): void {
    config(['mail.default' => 'outbox']);
    $user = User::factory()->create(['name' => 'Sam']);

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status');

    $payload = OutboxMessage::query()->topic('email.send')->sole()->payload;

    expect($payload['locale'])->toBe('en')
        ->and($payload['subject'])->toBe('Reset your '.config('app.name').' password')
        ->and($payload['html'])->toContain('Hi Sam,')
        ->and($payload['html'])->toContain('This link expires in 60 minutes.')
        ->and($payload['html'])->toContain('/reset-password/');
});

it('keeps the verification link valid', function (): void {
    config(['mail.default' => 'outbox']);
    $user = User::factory()->unverified()->create();
    $user->sendEmailVerificationNotification();

    $html = OutboxMessage::query()->topic('email.send')->sole()->payload['html'];
    preg_match('#href="(http[^"]+/email/verify/[^"]+)"#', $html, $match);
    $url = html_entity_decode($match[1]);

    $request = Request::create($url);
    expect(URL::hasValidSignature($request))->toBeTrue();

    $this->actingAs($user)->get($url)->assertRedirect();
    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
});

/** @return array<string, array<string, mixed>> */
function emailSendContractCases(): array
{
    /** @var array{valid: list<array{name: string, payload: array<string, mixed>}>} $fixture */
    $fixture = json_decode((string) file_get_contents(base_path('../../contracts/outbox/email-send.v1.samples.json')), true, flags: JSON_THROW_ON_ERROR);

    return collect($fixture['valid'])->mapWithKeys(fn (array $case): array => [$case['name'] => $case['payload']])->all();
}

it('publishes email.send payloads that match the shared worker contract fixture', function (): void {
    config([
        'mail.default' => 'outbox',
        'mail.from' => ['address' => 'hello@contract.test', 'name' => 'Contract Sender'],
    ]);
    $cases = emailSendContractCases();

    expect(array_keys($cases))->toBe(['html_only_no_reply_to', 'text_only_with_extras']);

    Mail::to('new.user@example.com')->send(new ContractHtmlMail);

    Mail::raw('Plain only', function (Message $message): void {
        $message->to('anna@example.com', 'Анна Петрова')
            ->subject('Отчёт за месяц')
            ->replyTo('owner@acme.test')
            ->attachData('hello', 'report.txt', ['mime' => 'text/plain']);
        $message->getHeaders()
            ->addTextHeader(OutboxMailTransport::HEADER_REFERENCE_TYPE, 'report')
            ->addTextHeader(OutboxMailTransport::HEADER_REFERENCE_ID, 'contract-2')
            ->addTextHeader(OutboxMailTransport::HEADER_WORKSPACE, '7')
            ->addTextHeader(OutboxMailTransport::HEADER_LOCALE, 'ru');
    });

    $payloads = OutboxMessage::query()->topic('email.send')->orderBy('created_at')->orderBy('id')->get()
        ->keyBy(fn (OutboxMessage $message): string => (string) $message->payload['reference']['id'])
        ->map(fn (OutboxMessage $message): array => $message->payload);

    expect($payloads)->toHaveCount(2)
        ->and($payloads['contract-1'])->toEqual($cases['html_only_no_reply_to'])
        ->and($payloads['contract-2'])->toEqual($cases['text_only_with_extras']);
});

final class ContractHtmlMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify Email Address');
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            OutboxMailTransport::HEADER_REFERENCE_TYPE => 'verify_email',
            OutboxMailTransport::HEADER_REFERENCE_ID => 'contract-1',
        ]);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Click the button below to verify your email address.</p>');
    }
}
