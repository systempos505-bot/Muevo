<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Punto de venta: formas de pago, turnos de caja, ventas, sus lineas,
 * sus pagos y las ventas en espera.
 *
 * Las lineas guardan una copia del nombre, el precio y el costo del
 * producto al momento de venderlo. Un ticket de hace seis meses tiene que
 * poder reimprimirse igual aunque despues se haya cambiado el precio o
 * hasta borrado el producto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->enum('type', ['cash', 'card', 'transfer', 'credit', 'other'])->default('other');
            // Si entra al cajon de dinero y por tanto cuenta en el arqueo.
            $table->boolean('affects_drawer')->default(false);
            // Si permite dar cambio (solo el efectivo, normalmente).
            $table->boolean('allows_change')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // Turno de caja. Nadie puede vender sin uno abierto: es lo que
        // permite cuadrar el efectivo al final del dia.
        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('terminal_id')->constrained();
            $table->foreignUuid('user_id')->constrained();
            $table->string('folio');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            // Fondo con el que se abrio.
            $table->decimal('opening_amount', 14, 2)->default(0);
            // Lo que el cajero conto al cerrar.
            $table->decimal('counted_amount', 14, 2)->nullable();
            // Lo que el sistema esperaba: fondo + ventas en efectivo
            // + entradas - salidas.
            $table->decimal('expected_amount', 14, 2)->nullable();
            // Positiva sobra, negativa falta.
            $table->decimal('difference', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamps();

            $table->unique(['tenant_id', 'folio']);
            $table->index(['terminal_id', 'status']);
        });

        // Entradas y salidas de efectivo que no son ventas: retiros,
        // pagos a proveedor desde caja, fondo adicional.
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('shift_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['in', 'out']);
            $table->decimal('amount', 14, 2);
            $table->string('reason');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('shift_id');
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained();
            $table->foreignUuid('terminal_id')->constrained();
            $table->foreignUuid('shift_id')->constrained();
            $table->foreignUuid('user_id')->constrained();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('price_list_id')->nullable()->constrained()->nullOnDelete();

            $table->string('folio');

            // Importes, todos en la moneda principal de la empresa.
            // subtotal es la suma de las lineas antes de descuento.
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            // Lo que se recibio y el cambio entregado.
            $table->decimal('paid', 14, 2)->default(0);
            $table->decimal('change', 14, 2)->default(0);
            // Costo de lo vendido, para calcular utilidad sin recorrer
            // el catalogo (que pudo cambiar desde entonces).
            $table->decimal('cost_total', 14, 4)->default(0);

            $table->enum('status', ['completed', 'cancelled', 'refunded'])->default('completed');
            $table->text('notes')->nullable();

            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'folio']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['shift_id', 'status']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_id')->constrained()->cascadeOnDelete();
            // El producto no se borra nunca, pero se usa nullOnDelete por
            // si acaso: el ticket sigue siendo legible con la descripcion.
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('variant_id')->nullable()
                ->constrained('product_variants')->nullOnDelete();
            $table->foreignUuid('product_unit_id')->nullable()
                ->constrained('product_units')->nullOnDelete();
            $table->foreignUuid('lot_id')->nullable()->constrained('product_lots')->nullOnDelete();

            // Copia de como se llamaba y que codigo tenia al venderlo.
            $table->string('description');
            $table->string('sku')->nullable();
            $table->string('unit_label')->nullable();

            // Cantidad en la presentacion vendida (2 cajas) y su
            // equivalente en unidad base (48 piezas).
            $table->decimal('quantity', 14, 3);
            $table->decimal('base_quantity', 14, 3);
            $table->decimal('unit_factor', 14, 4)->default(1);

            $table->decimal('unit_price', 14, 4);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('net', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            // Costo unitario al momento de vender, en unidad base.
            $table->decimal('unit_cost', 14, 4)->default(0);

            $table->unsignedInteger('position')->default(0);

            $table->index('sale_id');
            $table->index('product_id');
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_method_id')->nullable()
                ->constrained('payment_methods')->nullOnDelete();
            $table->foreignUuid('currency_id')->nullable()->constrained()->nullOnDelete();

            $table->string('method_label');
            // Monto tal como lo entrego el cliente, en su moneda.
            $table->decimal('amount', 14, 2);
            // Tipo de cambio aplicado y su equivalente en moneda principal.
            // Se guarda para que un reporte viejo no cambie si hoy sube el dolar.
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('amount_primary', 14, 2);
            $table->string('reference')->nullable();

            $table->timestamps();

            $table->index('sale_id');
        });

        // Ventas en espera: el cajero deja una cuenta apartada para
        // atender a otro cliente. Se guarda el carrito tal cual.
        Schema::create('held_sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('terminal_id')->constrained();
            $table->foreignUuid('user_id')->constrained();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->json('cart');
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['terminal_id', 'created_at']);
        });

        // Ahora que existe sales se cierra la relacion de los numeros de serie.
        Schema::table('product_serials', function (Blueprint $table) {
            $table->foreign('sale_id')->references('id')->on('sales')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_serials', fn (Blueprint $t) => $t->dropForeign(['sale_id']));

        Schema::dropIfExists('held_sales');
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('payment_methods');
    }
};
