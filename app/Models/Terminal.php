<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Terminal extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
