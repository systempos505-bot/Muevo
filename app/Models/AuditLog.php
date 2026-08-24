<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitacora de auditoria. Registra quien creo, modifico o anulo que cosa.
 */
class AuditLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Deja constancia de una accion sobre una entidad. */
    public static function record(string $entity, ?string $entityId, string $action, array $changes = []): void
    {
        static::create([
            'user_id' => auth()->id(),
            'entity' => $entity,
            'entity_id' => $entityId,
            'action' => $action,
            'changes' => $changes,
            'ip' => request()->ip(),
        ]);
    }
}
