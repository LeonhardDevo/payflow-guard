<?php

declare(strict_types=1);

namespace App\Assistant\Actions;

interface AssistantAction
{
    public function handle(AssistantActionContext $context): AssistantActionResult;
}
