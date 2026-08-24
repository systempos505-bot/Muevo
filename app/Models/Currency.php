<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use BelongsToTenant, HasUuids;

    protected $plural = 'currencies';

    protected $table = 'currencies';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'rate' => 'float', 'decimals' => 'integer'];
    }
}
