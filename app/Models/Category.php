<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoria o subcategoria. Solo se permiten dos niveles: mas
 * profundidad complica los reportes sin aportar nada en un POS.
 */
class Category extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('position')->orderBy('name');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function isSubcategory(): bool
    {
        return $this->parent_id !== null;
    }

    /** "Abarrotes / Enlatados", para mostrar en listados. */
    public function fullName(): string
    {
        return $this->parent ? "{$this->parent->name} / {$this->name}" : $this->name;
    }

    public function scopeActive($query)
    {
        return $query->where($query->qualifyColumn('status'), 'active');
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }
}
