<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Models;

use App\Domain\Receivables\Enums\InvoiceItemKind;
use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $invoice_id
 * @property int $position
 * @property InvoiceItemKind $kind
 * @property string $description
 * @property string $quantity
 * @property int $unit_price_minor
 * @property int $tax_rate_bp
 * @property int $amount_minor
 * @property int $tax_minor
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class InvoiceItem extends Model
{
    use BelongsToWorkspace;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'kind' => InvoiceItemKind::class,
            'quantity' => 'decimal:3',
            'unit_price_minor' => 'integer',
            'tax_rate_bp' => 'integer',
            'amount_minor' => 'integer',
            'tax_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
