<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = fake()->sentence(3);

        return [
            'workspace_id' => Workspace::factory(),
            'title' => rtrim($title, '.'),
            'original_name' => Str::slug($title).'.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(20_000, 900_000),
            'disk' => 'documents',
            'path' => 'factory/'.Str::uuid().'.pdf',
            'sha256' => hash('sha256', Str::random(32)),
            'department' => null,
            'roles' => [],
            'tags' => [],
            'status' => DocumentStatus::Queued,
        ];
    }

    public function indexed(int $pages = 3, int $chunks = 12): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentStatus::Indexed,
            'pages' => $pages,
            'chunks' => $chunks,
            'indexed_at' => now(),
        ]);
    }
}
