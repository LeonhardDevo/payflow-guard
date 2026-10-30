<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Legal\LegalAcceptances;
use App\Legal\LegalRepository;
use App\Models\User;
use App\Support\Flash;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;

final class LegalController extends Controller
{
    public function __construct(private readonly LegalRepository $legal) {}

    public function index(): Response
    {
        $locale = App::getLocale();
        $docs = [];

        foreach ($this->legal->keys() as $key) {
            $document = $this->legal->current($key, $locale);

            if ($document !== null) {
                $docs[] = $document->summary();
            }
        }

        return Inertia::render('marketing/LegalIndex', [
            'docs' => $docs,
            'reviewed' => $this->legal->reviewed(),
        ]);
    }

    public function show(Request $request, string $doc): Response
    {
        $locale = App::getLocale();
        $version = $request->query('version');

        $document = is_string($version) && $version !== ''
            ? $this->legal->version($doc, $version, $locale)
            : $this->legal->current($doc, $locale);

        abort_if($document === null, 404);

        return Inertia::render('marketing/Legal', [
            'doc' => $document->toPage(),
            'versions' => $this->legal->versions($doc, $locale),
            'reviewed' => $this->legal->reviewed(),
        ]);
    }

    public function pending(Request $request, LegalAcceptances $acceptances, CurrentWorkspace $current): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $pending = $acceptances->pending($user, $current->get(), App::getLocale());

        if ($pending === []) {
            return redirect()->intended(route('dashboard'));
        }

        return Inertia::render('legal/Accept', [
            'pending' => array_map(fn (array $item): array => [
                'key' => $item['document']->key,
                'title' => $item['document']->title,
                'version' => $item['document']->version,
                'summary_of_changes' => $item['document']->summaryOfChanges,
                'url' => $item['document']->url(),
                'workspace' => $item['workspace']?->name,
            ], $pending),
        ]);
    }

    public function accept(Request $request, LegalAcceptances $acceptances, CurrentWorkspace $current): RedirectResponse
    {
        $request->validate(['accept' => ['accepted']]);

        /** @var User $user */
        $user = $request->user();

        if ($acceptances->acceptPending($user, $current->get(), App::getLocale(), $request) > 0) {
            Flash::success('notifications.privacy.legal_accepted');
        }

        return redirect()->intended(route('dashboard'));
    }
}
