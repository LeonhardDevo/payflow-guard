<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $driver
 * @property array<string, string> $credentials
 * @property string|null $webhook_endpoint_id
 * @property CarbonInterface|null $connected_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class PaymentAccount extends Model
{
    protected $fillable = ['workspace_id', 'driver', 'credentials', 'webhook_endpoint_id', 'connected_at'];

    protected $hidden = ['credentials'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'connected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
