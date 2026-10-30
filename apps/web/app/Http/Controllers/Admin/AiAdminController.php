<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Ai\AiClient;
use App\Ai\AiServiceException;
use App\Ai\ReembedWorkspaces;
use App\Audit\Auditor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAiKeyRequest;
use App\Http\Requests\Admin\UpdateAiSettingsRequest;
use App\Support\Flash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class AiAdminController extends Controller
{
    public const array EMBEDDINGS_PROVIDERS = ['local', 'openai-compatible', 'hash'];

    public function index(AiClient $ai): Response
    {
        $error = null;
        $providers = [];
        $settings = self::defaultSettings();
        $usage = ['days' => [], 'by_model' => [], 'total_cost_usd' => 0];

        try {
            $providers = $ai->providers(App::getLocale());
            $settings = [...$settings, ...$ai->settings()];
            $usage = [...$usage, ...$ai->usage(30)];
        } catch (AiServiceException $exception) {
            $error = (string) __($exception->isUnavailable() ? 'notifications.ai.unavailable' : 'notifications.ai.failed');
        }

        return Inertia::render('admin/ai/Index', [
            'providers' => $providers,
            'settings' => $settings,
            'usage' => $usage,
            'embeddingsProviders' => is_array($settings['embeddings_providers'] ?? null)
                ? array_values($settings['embeddings_providers'])
                : self::EMBEDDINGS_PROVIDERS,
            'serviceError' => $error,
        ]);
    }

    public function updateSettings(
        UpdateAiSettingsRequest $request,
        AiClient $ai,
        Auditor $auditor,
        ReembedWorkspaces $reembed,
    ): SymfonyResponse {
        $new = $request->settings();

        try {
            $before = $this->safeSettings($ai);
            $saved = $ai->updateSettings($new);
        } catch (AiServiceException $exception) {
            return $this->failure($request, $exception);
        }

        $queued = ($saved['reembed_required'] ?? false) === true ? $reembed->publishAll() : 0;

        $auditor->record(
            'ai.settings_updated',
            changes: Auditor::diff(self::flatten($before), self::flatten([...$new, ...$saved])),
            metadata: $queued > 0 ? ['reembed_workspaces' => $queued] : [],
            platform: true,
            subjectLabel: $new['provider'].' / '.$new['model'],
        );

        if ($queued > 0 && ! ($request->expectsJson() && ! $request->header('X-Inertia'))) {
            Flash::success('notifications.ai.settings_saved_reembed', ['count' => $queued]);

            return back();
        }

        return $this->success($request, 'notifications.ai.settings_saved', [...$saved, 'reembed_queued' => $queued]);
    }

    public function updateKey(UpdateAiKeyRequest $request, string $provider, AiClient $ai, Auditor $auditor): SymfonyResponse
    {
        abort_unless(preg_match('/^[a-z0-9_-]{1,64}$/', $provider) === 1, 404);
        $apiKey = $request->apiKey();

        try {
            $result = $ai->updateKey($provider, $apiKey);
        } catch (AiServiceException $exception) {
            return $this->failure($request, $exception);
        }

        $auditor->record(
            $apiKey === null ? 'ai.key_cleared' : 'ai.key_updated',
            metadata: ['provider' => $provider],
            platform: true,
            subjectLabel: $provider,
        );

        return $this->success(
            $request,
            $apiKey === null ? 'notifications.ai.key_cleared' : 'notifications.ai.key_saved',
            $result,
        );
    }

    public function models(string $provider, AiClient $ai): JsonResponse
    {
        abort_unless(preg_match('/^[a-z0-9_-]{1,64}$/', $provider) === 1, 404);

        try {
            return response()->json($ai->models($provider));
        } catch (AiServiceException $exception) {
            return $this->jsonError($exception);
        }
    }

    public function test(AiClient $ai): JsonResponse
    {
        try {
            return response()->json($ai->test());
        } catch (AiServiceException $exception) {
            return $this->jsonError($exception);
        }
    }

    /** @return array<string, mixed> */
    private function safeSettings(AiClient $ai): array
    {
        try {
            return $ai->settings();
        } catch (AiServiceException) {
            return [];
        }
    }

    /** @param  array<string, mixed>  $data */
    private function success(Request $request, string $messageKey, array $data): SymfonyResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($data);
        }

        Flash::success($messageKey);

        return back();
    }

    private function failure(Request $request, AiServiceException $exception): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return $this->jsonError($exception);
        }

        $message = (string) __($exception->isUnavailable() ? 'notifications.ai.unavailable' : 'notifications.ai.failed');
        Flash::errorMessage($exception->detail !== null ? $message.' '.$exception->detail : $message);

        return back();
    }

    private function jsonError(AiServiceException $exception): JsonResponse
    {
        return response()->json([
            'message' => __($exception->isUnavailable() ? 'notifications.ai.unavailable' : 'notifications.ai.failed'),
            'detail' => $exception->detail,
        ], $exception->isUnavailable() ? 503 : max(400, $exception->status));
    }

    /** @return array{provider: string, model: string, fallback_model: null, temperature: float, embeddings: array{provider: string, model: string}} */
    private static function defaultSettings(): array
    {
        return [
            'provider' => 'mock',
            'model' => 'mock',
            'fallback_model' => null,
            'temperature' => 0.2,
            'embeddings' => ['provider' => 'hash', 'model' => 'hash-384'],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private static function flatten(array $settings): array
    {
        $flat = [];

        foreach (['provider', 'model', 'fallback_model', 'temperature'] as $key) {
            $flat[$key] = $settings[$key] ?? null;
        }

        $embeddings = is_array($settings['embeddings'] ?? null) ? $settings['embeddings'] : [];
        $flat['embeddings.provider'] = $embeddings['provider'] ?? null;
        $flat['embeddings.model'] = $embeddings['model'] ?? null;

        return $flat;
    }
}
