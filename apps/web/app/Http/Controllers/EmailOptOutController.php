<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Privacy\EmailSuppressions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class EmailOptOutController extends Controller
{
    public function show(string $token): Response|HttpResponse
    {
        $data = EmailSuppressions::parse($token);

        if ($data === null) {
            return $this->invalid();
        }

        return Inertia::render('email/OptOut', $this->props($token, $data, 'confirm'));
    }

    public function store(Request $request, string $token, EmailSuppressions $suppressions): Response|HttpResponse
    {
        $data = EmailSuppressions::parse($token);

        if ($data === null) {
            return $this->invalid();
        }

        $suppressions->suppress($data['email'], $data['workspace_id'], $request->input('List-Unsubscribe') === 'One-Click' ? 'one_click' : 'link');

        if ($request->input('List-Unsubscribe') === 'One-Click') {
            return response('', 200);
        }

        return Inertia::render('email/OptOut', $this->props($token, $data, 'done'));
    }

    /**
     * @param  array{email: string, workspace_id: int|null}  $data
     * @return array<string, mixed>
     */
    private function props(string $token, array $data, string $status): array
    {
        return [
            'status' => $status,
            'token' => $token,
            'email' => EmailSuppressions::mask($data['email']),
            'workspace' => $data['workspace_id'] !== null ? Workspace::query()->whereKey($data['workspace_id'])->value('name') : null,
        ];
    }

    private function invalid(): HttpResponse
    {
        return Inertia::render('email/OptOut', ['status' => 'invalid', 'token' => null, 'email' => null, 'workspace' => null])
            ->toResponse(request())
            ->setStatusCode(404);
    }
}
