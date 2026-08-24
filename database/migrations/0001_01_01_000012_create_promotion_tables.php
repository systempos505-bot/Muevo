<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promociones de venta.
 *
 * Una promocion dice a que aplica (productos, categorias, marcas o todo),
 * que descuento hace (2x1, un porcentaje, un monto o un precio de paquete)
 * y cuando esta vigente. El precio del producto no se toca: la promocion
 * es un descuento sobre la linea, para que el ticket muestre el precio de
 * lista y el ahorro por separado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('description')->nullable();

            /*
             * nxm          lleva N y paga M   (2x1, 3x2)
             * percent      un porcentaje menos
             * amount       un monto fijo menos por unidad
             * bundle_price precio cerrado por un paquete de N
             */
            $table->enum('type', ['nxm', 'percent', 'amount', 'bundle_price']);

            // Aplica a lo que diga promotion_targets, o a todo el catalogo.
            $table->boolean('applies_to_all')->default(false);

            // 2x1 es buy=2, get=1: de cada 2 unidades, 1 no se cobra.
            $table->unsignedInteger('buy_quantity')->default(0);
            $table->unsignedInteger('get_quantity')->default(0);

            $table->decimal('discount_percent', 7, 4)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('bundle_price', 14, 2)->default(0);

            // Minimo de unidades en la linea para que aplique.
            $table->decimal('min_quantity', 14, 3)->default(1);
            // Cuantas veces puede repetirse en una misma linea. Sin limite
            // si va en nulo: llevar 20 en un 2x1 son 10 gratis.
            $table->unsignedInteger('max_uses_per_line')->nullable();

            // Vigencia. En nulo significa sin limite por ese lado.
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            // Dias de la semana en que corre: [1..7], 1 es lunes.
            // En nulo, todos los dias.
            $table->json('weekdays')->nullable();
            // Franja horaria del dia, para la hora feliz.
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();

            // Acotaciones opcionales: solo una sucursal, solo una lista de
            // precios, solo un tipo de cliente.
            $table->foreignUuid('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('price_list_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('customer_type_id')->nullable()->constrained()->nullOnDelete();

            // Cuando dos promociones alcanzan la misma linea gana la de
            // mayor prioridad; a igual prioridad, la que mas ahorra.
            $table->unsignedInteger('priority')->default(0);
            // Si puede sumarse a otra promocion en la misma linea.
            $table->boolean('combinable')->default(false);

            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedBigInteger('times_used')->default(0);

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        // A que alcanza la promocion. Una fila por producto, categoria o
        // marca incluida.
        Schema::create('promotion_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('promotion_id')->constrained()->cascadeOnDelete();

            $table->enum('target_type', ['product', 'category', 'brand']);
            $table->uuid('target_id');

            $table->index(['promotion_id', 'target_type']);
            $table->unique(['promotion_id', 'target_type', 'target_id']);
        });

        // Que promocion se aplico a que linea y cuanto ahorro. Se guarda
        // aparte porque una linea puede llevar mas de una promocion
        // combinable, y el ticket tiene que poder explicar el descuento.
        Schema::create('sale_item_promotions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('promotion_id')->nullable()->constrained()->nullOnDelete();

            // Copia del nombre al momento de vender: renombrar la
            // promocion despues no debe cambiar un ticket viejo.
            $table->string('label');
            $table->decimal('discount', 14, 2);
            // Unidades regaladas, cuando la promocion es de tipo nxm.
            $table->decimal('free_quantity', 14, 3)->default(0);

            $table->index('sale_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_promotions');
        Schema::dropIfExists('promotion_targets');
        Schema::dropIfExists('promotions');
    }
};
