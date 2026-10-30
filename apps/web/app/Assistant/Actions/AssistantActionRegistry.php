<?php

declare(strict_types=1);

namespace App\Assistant\Actions;

use Illuminate\Contracts\Container\Container;

final readonly class AssistantActionRegistry
{
    public function __construct(private Container $container) {}

    public function resolve(string $type): ?AssistantAction
    {
        /** @var array<string, class-string<AssistantAction>> $actions */
        $actions = config('product.assistant.actions', []);
        $class = $actions[$type] ?? null;

        if ($class === null) {
            return null;
        }

        $handler = $this->container->make($class);

        return $handler instanceof AssistantAction ? $handler : null;
    }

    /** @return list<string> */
    public function types(): array
    {
        /** @var array<string, class-string<AssistantAction>> $actions */
        $actions = config('product.assistant.actions', []);

        return array_keys($actions);
    }
}
