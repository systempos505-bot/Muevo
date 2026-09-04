<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotizaciones.
 *
 * Una cotizacion no es una venta a medias: es una promesa de precio. No
 * mueve inventario ni dinero, y por eso no tiene folio de venta, ni
 * turno de caja, ni pagos. Lo unico que compromete al negocio es el
 * precio y hasta cuando lo sostiene.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained();

            /*
             * El cliente registrado es opcional a proposito: la mayoria de
             * las cotizaciones se piden por telefono antes de que alguien
             * sea cliente. Obligar a darlo de alta para cotizar haria que
             * el vendedor invente clientes basura con tal de avanzar.
             */
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_phone')->nullable();

            $table->foreignUuid('price_list_id')->nullable()
                ->constrained('price_lists')->nullOnDelete();

            $table->string('folio');

            /*
             * pending    entregada al cliente, esperando respuesta
             * approved   el cliente la acepto; todavia no se factura
             * rejected   el cliente no la tomo, o se dio de baja
             * converted  ya se convirtio en venta; no se vuelve a tocar
             *
             * "Vencida" no es un estado guardado sino algo que se deduce de
             * valid_until. Guardarlo obligaria a una tarea programada que
             * lo voltee todas las noches, y en hosting compartido no hay
             * quien la corra: las cotizaciones se quedarian "vigentes"
             * para siempre.
             */
            $table->enum('status', ['pending', 'approved', 'rejected', 'converted'])
                ->default('pending');

            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            // Hasta cuando se sostiene el precio.
            $table->date('valid_until');

            $table->text('notes')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->foreignUuid('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reject_reason')->nullable();

            // La venta en la que termino, si termino en una.
            $table->foreignUuid('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('converted_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'valid_until']);
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained();
            $table->foreignUuid('variant_id')->nullable()
                ->constrained('product_variants')->nullOnDelete();
            $table->foreignUuid('product_unit_id')->nullable()
                ->constrained('product_units')->nullOnDelete();

            /*
             * Copia de como se llamaba y cuanto costaba al cotizar. Si
             * despues cambia el catalogo, la cotizacion que el cliente
             * tiene en la mano se sigue leyendo igual que cuando se la
             * dieron.
             */
            $table->string('description');
            $table->string('sku')->nullable();
            $table->string('unit_label')->nullable();

            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_factor', 14, 4)->default(1);
            $table->decimal('base_quantity', 14, 3);

            $table->decimal('unit_price', 14, 4);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('net', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            $table->unsignedInteger('position')->default(0);

            $table->index('quote_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
    }
};
