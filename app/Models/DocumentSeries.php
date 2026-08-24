<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Numeracion de documentos por sucursal y tipo.
 *
 * El folio se toma bloqueando la fila, para que dos cajas vendiendo al
 * mismo tiempo no se lleven el mismo numero.
 */
class DocumentSeries extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'document_series';

    protected $guarded = ['id'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Reserva el siguiente folio y lo devuelve ya formateado.
     * Debe llamarse dentro de una transaccion.
     */
    public function nextFolio(): string
    {
        return DB::transaction(function () {
            $series = static::whereKey($this->id)->lockForUpdate()->firstOrFail();
            $number = $series->next_number;

            $series->increment('next_number');

            return $series->prefix.str_pad((string) $number, $series->padding, '0', STR_PAD_LEFT);
        });
    }
}
