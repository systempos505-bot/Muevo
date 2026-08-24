<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Tax extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'taxes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate' => 'float', 'is_default' => 'boolean'];
    }

    public function scopeActive($query)
    {
        return $query->where($query->qualifyColumn('status'), 'active');
    }
}
