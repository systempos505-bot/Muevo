<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventario: existencias, lotes, vencimientos, numeros de serie,
 * kardex e inventario fisico.
 *
 * Todas las cantidades se guardan en la UNIDAD BASE del producto.
 * Vender una caja de 24 descuenta 24, no 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('variant_id')->nullable()
                ->constrained('product_variants')->cascadeOnDelete();
            // Puede quedar negativo: es preferible registrar la venta y
            // que el faltante quede visible, a rechazarla en el mostrador.
            $table->decimal('quantity', 14, 3)->default(0);
            // Costo promedio ponderado en esta sucursal.
            $table->decimal('avg_cost', 14, 4)->default(0);
            // Minimo propio de la sucursal. Si es nulo usa products.min_stock.
            $table->decimal('min_stock', 14, 3)->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'product_id', 'variant_id'], 'inventories_unique');
            $table->index(['tenant_id', 'product_id']);
        });

        Schema::create('product_lots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('variant_id')->nullable()
                ->constrained('product_variants')->cascadeOnDelete();
            $table->string('lot_number');
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('cost', 14, 4)->default(0);
            // 'blocked' saca el lote de la venta sin borrarlo.
            $table->enum('status', ['active', 'blocked', 'depleted'])->default('active');
            $table->timestamps();

            $table->unique(['branch_id', 'product_id', 'variant_id', 'lot_number'], 'product_lots_unique');
            // Para vender primero lo que vence antes y para las alertas.
            $table->index(['tenant_id', 'expiry_date']);
        });

        // Una fila por pieza individual.
        Schema::create('product_serials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('variant_id')->nullable()
                ->constrained('product_variants')->cascadeOnDelete();
            $table->foreignUuid('lot_id')->nullable()->constrained('product_lots')->nullOnDelete();
            $table->string('serial');
            $table->enum('status', ['in_stock', 'sold', 'returned', 'damaged'])->default('in_stock');
            // Venta que consumio la pieza. La relacion se cierra con el
            // modulo de ventas.
            $table->uuid('sale_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'serial']);
            $table->index(['product_id', 'branch_id', 'status']);
        });

        // Kardex. Es solo de escritura: nunca se actualiza ni se borra,
        // porque es el respaldo de como se llego a la existencia actual.
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('variant_id')->nullable()
                ->constrained('product_variants')->cascadeOnDelete();
            $table->foreignUuid('lot_id')->nullable()->constrained('product_lots')->nullOnDelete();
            $table->enum('type', [
                'initial',         // inventario inicial
                'purchase',        // compra
                'sale',            // venta
                'sale_return',     // devolucion de cliente
                'purchase_return', // devolucion a proveedor
                'adjustment',      // ajuste manual
                'transfer_out',    // traspaso: salida
                'transfer_in',     // traspaso: entrada
                'count',           // inventario fisico
                'loss',            // merma
            ]);
            // Positiva entra, negativa sale. Siempre en unidad base.
            $table->decimal('quantity', 14, 3);
            // Existencia despues del movimiento: permite leer el kardex
            // sin recalcular toda la historia en cada consulta.
            $table->decimal('balance', 14, 3);
            $table->decimal('unit_cost', 14, 4)->default(0);
            // Documento que lo origino.
            $table->string('reference_type', 20)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('reason')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['product_id', 'branch_id', 'created_at'], 'movements_kardex_index');
            $table->index(['reference_type', 'reference_id'], 'movements_reference_index');
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->string('folio');
            $table->text('notes')->nullable();
            $table->enum('status', ['open', 'applied', 'cancelled'])->default('open');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'folio']);
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('variant_id')->nullable()
                ->constrained('product_variants')->cascadeOnDelete();
            $table->foreignUuid('lot_id')->nullable()->constrained('product_lots')->nullOnDelete();
            // Lo que decia el sistema al momento de contar.
            $table->decimal('system_qty', 14, 3)->default(0);
            // Lo que se conto fisicamente.
            $table->decimal('counted_qty', 14, 3)->default(0);

            $table->index('count_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('product_serials');
        Schema::dropIfExists('product_lots');
        Schema::dropIfExists('inventories');
    }
};
