<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devoluciones y notas de credito.
 *
 * Una venta no se edita nunca: si el cliente devuelve algo, se emite un
 * documento aparte que dice que volvio, cuanto se le regreso y como. Asi
 * la venta original sigue siendo lo que se cobro ese dia, y el reporte de
 * un mes cerrado no cambia porque alguien devolvio algo en el siguiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained();
            $table->foreignUuid('shift_id')->nullable()->constrained()->nullOnDelete();
            // La venta de origen. Se admite nula por si algun dia se
            // recibe una devolucion sin ticket.
            $table->foreignUuid('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('user_id')->constrained('users');

            $table->string('folio');

            /*
             * refund  se le devuelve el dinero
             * credit  le queda como saldo a favor
             */
            $table->enum('type', ['refund', 'credit'])->default('refund');

            // De donde sale el dinero cuando se devuelve en efectivo.
            $table->foreignUuid('payment_method_id')->nullable()
                ->constrained()->nullOnDelete();

            // Si la mercancia vuelve al inventario. Lo dañado no vuelve:
            // devolverlo al estante seria inventar existencia vendible.
            $table->boolean('restock')->default(true);

            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            // Costo de lo que volvio, para que la utilidad del periodo
            // descuente tambien el costo de la mercancia devuelta.
            $table->decimal('cost_total', 14, 4)->default(0);

            $table->string('reason');
            $table->text('notes')->nullable();

            $table->enum('status', ['registered', 'cancelled'])->default('registered');
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index('sale_id');
        });

        Schema::create('credit_note_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('credit_note_id')->constrained()->cascadeOnDelete();
            // La linea de la venta que se esta devolviendo. Es lo que
            // permite saber cuanto queda por devolver de cada una.
            $table->foreignUuid('sale_item_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('variant_id')->nullable()
                ->constrained('product_variants')->nullOnDelete();
            $table->foreignUuid('product_unit_id')->nullable()
                ->constrained('product_units')->nullOnDelete();

            $table->string('description');
            $table->string('sku')->nullable();
            $table->string('unit_label')->nullable();

            $table->decimal('quantity', 14, 3);
            $table->decimal('base_quantity', 14, 3);
            $table->decimal('unit_factor', 14, 4)->default(1);

            // Precio efectivo pagado por unidad: el de lista menos lo que
            // le tocaba de descuento y promocion. Devolver al precio de
            // lista lo que se compro en oferta seria regalar dinero.
            $table->decimal('unit_price', 14, 4);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('net', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('unit_cost', 14, 4)->default(0);

            $table->unsignedInteger('position')->default(0);

            $table->index('credit_note_id');
            $table->index('sale_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_items');
        Schema::dropIfExists('credit_notes');
    }
};
