<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A que producto, categoria o marca alcanza una promocion. */
class PromotionTarget extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /** El nombre de lo apuntado, para poder listarlo sin adivinar. */
    public function targetName(): ?string
    {
        return match ($this->target_type) {
            'product' => Product::whereKey($this->target_id)->value('name'),
            'category' => Category::whereKey($this->target_id)->value('name'),
            'brand' => Brand::whereKey($this->target_id)->value('name'),
            default => null,
        };
    }
}
