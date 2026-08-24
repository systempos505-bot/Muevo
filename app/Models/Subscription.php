<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isUsable(): bool
    {
        return in_array($this->status, ['trialing', 'active', 'past_due'], true);
    }
}
