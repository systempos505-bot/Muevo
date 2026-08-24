<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Ata un modelo a la empresa activa.
 *
 * Hace dos cosas, y las dos importan:
 *  1. Filtra toda consulta por tenant_id, sin que haya que recordarlo.
 *  2. Rellena tenant_id al crear, para que nunca nazca un registro huerfano.
 *
 * Si no hay empresa activa, las consultas no devuelven nada en lugar de
 * devolver todo: ante un error de configuracion es preferible una pantalla
 * vacia a mostrarle a un negocio los datos de otro.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            if (Tenancy::disabled()) {
                return;
            }

            $query->where(
                $query->getModel()->qualifyColumn('tenant_id'),
                Tenancy::id(),
            );
        });

        static::creating(function ($model) {
            if ($model->tenant_id !== null) {
                return;
            }

            if (Tenancy::disabled()) {
                // Fuera del scope hay que ser explicito: adivinar la empresa
                // en un seeder o un job es justo como se cruzan los datos.
                throw new RuntimeException(
                    sprintf(
                        'Falta tenant_id al crear %s con el filtro de empresa desactivado.',
                        static::class,
                    ),
                );
            }

            if (! Tenancy::check()) {
                throw new RuntimeException(
                    sprintf('No hay empresa activa para crear %s.', static::class),
                );
            }

            $model->tenant_id = Tenancy::id();
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Consulta sin el filtro de empresa. Usar con cuidado y a proposito. */
    public static function allTenants(): Builder
    {
        return static::query()->withoutGlobalScope('tenant');
    }
}
