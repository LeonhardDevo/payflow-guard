<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Mail\AutomatedMailable;
use App\Mail\DeletionScheduledMail;
use App\Models\AuditLog;
use App\Models\DeletionRequest;
use App\Models\EmailSuppression;
use App\Models\OutboxMessage;
use App\Privacy\EmailSuppressions;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\Mail;

function automatedMail(?int $workspaceId): Mailable
{
    return new class($workspaceId) extends AutomatedMailable
    {
        public function __construct(public ?int $workspaceId) {}

        protected function senderWorkspaceId(): ?int
        {
            return $this->workspaceId;
        }

        public function envelope(): Envelope
        {
            return new Envelope(subject: 'Automated reminder');
        }

        public function headers(): Headers
        {
            return new Headers(text: $this->automatedHeaders());
        }

        public function build(): static
        {
            return $this->html('<p>Reminder</p>');
        }
    };
}

beforeEach(fn () => config(['mail.default' => 'outbox']));

it('round-trips tamper-proof tokens', function (): void {
    $token = EmailSuppressions::token('Client@Example.com', 12);

    expect(EmailSuppressions::parse($token))->toBe(['email' => 'client@example.com', 'workspace_id' => 12])
        ->and(EmailSuppressions::parse($token.'x'))->toBeNull()
        ->and(EmailSuppressions::parse('abc.def'))->toBeNull()
        ->and(EmailSuppressions::mask('client@example.com'))->toBe('c***@example.com');
});

it('asks for confirmation on GET and opts out on POST, notifying the workspace owners', function (): void {
    [$owner, $workspace] = $this->member(WorkspaceRole::Owner);
    $token = EmailSuppressions::token('client@example.com', $workspace->id);

    $this->get(route('email.opt-out', $token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('email/OptOut', false)
            ->where('status', 'confirm')
            ->where('email', 'c***@example.com')
            ->where('workspace', $workspace->name)
            ->etc());

    expect(EmailSuppression::query()->count())->toBe(0);

    $this->post(route('email.opt-out.store', $token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('status', 'done')->etc());

    $suppression = EmailSuppression::query()->sole();
    expect($suppression->email)->toBe('client@example.com')
        ->and($suppression->workspace_id)->toBe($workspace->id)
        ->and(AuditLog::query()->where('action', 'email.opted_out')->where('workspace_id', $workspace->id)->sole()->subject_label)->toBe('c***@example.com')
        ->and($owner->notifications()->count())->toBe(1);

    $this->post(route('email.opt-out.store', $token))->assertOk();
    expect(EmailSuppression::query()->count())->toBe(1)
        ->and($owner->notifications()->count())->toBe(1);
});

it('accepts RFC 8058 one-click unsubscribe without a CSRF token', function (): void {
    $token = EmailSuppressions::token('one@example.com', null);

    $this->post(route('email.opt-out.store', $token), ['List-Unsubscribe' => 'One-Click'])
        ->assertOk()
        ->assertContent('');

    expect(EmailSuppression::query()->sole()->source)->toBe('one_click');
});

it('rejects tampered tokens', function (): void {
    $this->get('/email/opt-out/abc.def')->assertNotFound();
    $this->post('/email/opt-out/abc.def')->assertNotFound();
});

it('skips suppressed recipients of automated mail and adds unsubscribe headers', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    app(EmailSuppressions::class)->suppress('gone@example.com', $workspace->id);

    Mail::to('gone@example.com')->send(automatedMail($workspace->id));
    Mail::to('stay@example.com')->send(automatedMail($workspace->id));

    $messages = OutboxMessage::query()->topic('email.send')->get();
    expect($messages)->toHaveCount(1);

    $payload = $messages->first()->payload;
    expect($payload['to'])->toBe('stay@example.com')
        ->and($payload['headers']['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
        ->and($payload['headers']['List-Unsubscribe'])->toStartWith('<'.url('/email/opt-out/'));
});

it('applies a global opt-out to every workspace but never to transactional mail', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    app(EmailSuppressions::class)->suppress('global@example.com', null);

    Mail::to('global@example.com')->send(automatedMail($workspace->id));
    expect(OutboxMessage::query()->topic('email.send')->count())->toBe(0);

    Mail::to('global@example.com')->send((new class extends Mailable
    {
        public function build(): static
        {
            return $this->subject('Password reset')->html('<p>Reset</p>');
        }
    }));

    $payload = OutboxMessage::query()->topic('email.send')->sole()->payload;
    expect($payload)->not->toHaveKey('headers');
});

it('puts sender identity and a privacy link in every e-mail footer', function (): void {
    config(['legal.company.name' => 'Fixture Operator Ltd']);

    Mail::to('someone@example.com')->send(new DeletionScheduledMail(
        new DeletionRequest(['scheduled_for' => now()->addDays(14)]),
        'Someone',
        null,
    ));

    $payload = OutboxMessage::query()->topic('email.send')->sole()->payload;
    expect($payload['html'])->toContain('Fixture Operator Ltd')
        ->and($payload['html'])->toContain(route('legal', 'privacy'))
        ->and($payload['text'])->toContain(route('legal', 'privacy'));
});
