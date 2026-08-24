<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traspasos de mercancia entre sucursales.
 *
 * El traspaso tiene dos momentos, salida y llegada, porque en el medio
 * hay mercancia que no esta en ninguna de las dos tiendas. Descontarla y
 * sumarla de golpe haria que el destino muestre existencia que todavia
 * va en camino, y quien vende ahi la ofreceria sin tenerla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('from_branch_id')->constrained('branches');
            $table->foreignUuid('to_branch_id')->constrained('branches');

            $table->string('folio');

            /*
             * draft     se esta armando, todavia no sale nada
             * sent      salio del origen, va en camino
             * received  llego al destino
             * cancelled se cancelo; si ya habia salido, la mercancia
             *           regresa al origen
             */
            $table->enum('status', ['draft', 'sent', 'received', 'cancelled'])->default('draft');

            // Valor de lo enviado, al costo del origen.
            $table->decimal('total_cost', 14, 4)->default(0);

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignUuid('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained();
            $table->foreignUuid('variant_id')->nullable()
                ->constrained('product_variants')->nullOnDelete();

            $table->string('description');
            $table->string('sku')->nullable();
            $table->string('unit_label')->nullable();

            // Siempre en unidad base: un traspaso mueve mercancia, no
            // presentaciones de venta, y mezclarlas invita a errores.
            $table->decimal('quantity_sent', 14, 3);
            // Lo que de verdad llego. Puede ser menos: la diferencia es lo
            // que se perdio en el camino, y queda a la vista.
            $table->decimal('quantity_received', 14, 3)->nullable();

            // Costo promedio del origen al momento de enviar, para que el
            // destino no herede un costo que no le corresponde.
            $table->decimal('unit_cost', 14, 4)->default(0);

            $table->unsignedInteger('position')->default(0);

            $table->index('transfer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
