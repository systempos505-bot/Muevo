<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Linea de una venta.
 *
 * Guarda copia del nombre, del precio y del costo al momento de vender,
 * para que un ticket viejo se pueda reimprimir igual aunque el producto
 * haya cambiado de precio o ya no exista.
 */
class SaleItem extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'base_quantity' => 'float',
            'unit_factor' => 'float',
            'unit_price' => 'float',
            'discount' => 'float',
            'tax_rate' => 'float',
            'tax_amount' => 'float',
            'net' => 'float',
            'total' => 'float',
            'unit_cost' => 'float',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** Promociones que se aplicaron a esta linea al venderla. */
    public function promotions(): HasMany
    {
        return $this->hasMany(SaleItemPromotion::class);
    }

    /** Devoluciones que ya se hicieron de esta linea. */
    public function returnItems(): HasMany
    {
        return $this->hasMany(CreditNoteItem::class);
    }

    /** Utilidad de la linea: lo que quedo despues del costo. */
    public function profit(): float
    {
        return round($this->net - ($this->unit_cost * $this->base_quantity), 2);
    }

    /**
     * Precio efectivo que el cliente pago por unidad.
     *
     * Es el de lista menos lo que le tocaba de descuento y promocion.
     * Devolver al precio de lista lo que se compro en oferta seria
     * regresarle mas dinero del que entrego.
     */
    public function effectiveUnitPrice(): float
    {
        if ($this->quantity <= 0) {
            return 0.0;
        }

        return round($this->total / $this->quantity, 4);
    }

    /**
     * Cuanto queda por devolver de esta linea.
     *
     * Se cuenta contra las devoluciones ya emitidas: sin esto, el mismo
     * producto se podria devolver dos veces.
     */
    public function returnableQuantity(): float
    {
        $returned = (float) $this->returnItems()
            ->join('credit_notes', 'credit_notes.id', '=', 'credit_note_items.credit_note_id')
            ->where('credit_notes.status', 'registered')
            ->sum('credit_note_items.quantity');

        return round(max(0, $this->quantity - $returned), 3);
    }
}
