<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cambio de costo, venga de una compra o de la mano del usuario.
 */
class CostHistory extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'cost_histories';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['old_cost' => 'float', 'new_cost' => 'float', 'created_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
