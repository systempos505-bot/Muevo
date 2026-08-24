<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compras a proveedores y sus pagos.
 *
 * Una compra es la via por la que la mercancia entra al inventario con
 * documento: quien la vendio, a que costo y en que lote. Sin ella todo
 * ingreso seria un ajuste manual sin respaldo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained();
            $table->foreignUuid('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('user_id')->constrained();

            $table->string('folio');
            // Numero de la factura del proveedor, que no es el folio interno.
            $table->string('invoice_number')->nullable();

            $table->enum('payment_type', ['cash', 'credit'])->default('cash');
            $table->date('due_date')->nullable();

            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            // Lo abonado al proveedor por esta compra.
            $table->decimal('paid', 14, 2)->default(0);

            // Si al recibirla se actualiza el costo de los productos.
            $table->boolean('updates_cost')->default(true);

            $table->enum('status', ['received', 'cancelled'])->default('received');
            $table->text('notes')->nullable();

            $table->timestamp('received_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'folio']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('variant_id')->nullable()
                ->constrained('product_variants')->nullOnDelete();
            // Unidad en la que se compro: puede no ser la de venta.
            $table->foreignUuid('product_unit_id')->nullable()
                ->constrained('product_units')->nullOnDelete();
            $table->foreignUuid('lot_id')->nullable()->constrained('product_lots')->nullOnDelete();

            $table->string('description');
            $table->string('sku')->nullable();
            $table->string('unit_label')->nullable();

            // Cantidad en la presentacion comprada y en unidad base.
            $table->decimal('quantity', 14, 3);
            $table->decimal('base_quantity', 14, 3);
            $table->decimal('unit_factor', 14, 4)->default(1);

            // Costo de la presentacion comprada (una caja) y por unidad
            // base (una pieza). El segundo es el que alimenta el margen.
            $table->decimal('unit_cost', 14, 4);
            $table->decimal('base_unit_cost', 14, 4);

            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('net', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            // Lote y vencimiento capturados al recibir.
            $table->string('lot_number')->nullable();
            $table->date('expiry_date')->nullable();

            $table->unsignedInteger('position')->default(0);

            $table->index('purchase_id');
            $table->index('product_id');
        });

        // Abonos al proveedor. Una compra a credito se salda con uno o
        // varios de estos.
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('payment_method_id')->nullable()
                ->constrained('payment_methods')->nullOnDelete();

            $table->decimal('amount', 14, 2);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['supplier_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
