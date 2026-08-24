<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogo: categorias, marcas, unidades de medida, listas de precios,
 * productos, variantes, presentaciones, precios, codigos de barra,
 * combos e historiales de precio y costo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Categorias y subcategorias. Solo dos niveles: mas profundidad
        // complica los reportes sin aportar nada en un punto de venta.
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('parent_id')->nullable()
                ->constrained('categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 20)->nullable();      // para los botones del POS
            $table->unsignedInteger('position')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'parent_id']);
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        // Catalogo de unidades del negocio: Unidad, Caja, Docena, Kg...
        Schema::create('units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10);                   // UND, CJA, DOC, KG
            $table->string('name');
            $table->string('plural_name')->nullable();
            // Si permite decimales se puede vender 1.5; si no, solo enteros.
            $table->boolean('allows_decimals')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // Precio 1..N a nivel empresa. Un cliente puede tener una asignada.
        Schema::create('price_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');                       // Publico, Mayoreo...
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_default')->default(false);
            // manual: el precio se captura producto por producto.
            // margin: se calcula como costo + margen y se recalcula solo
            //         cuando cambia el costo, salvo que se capture a mano.
            $table->enum('pricing_mode', ['manual', 'margin'])->default('manual');
            $table->decimal('margin_percent', 9, 4)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        // Ahora que price_lists existe, se cierran las relaciones de clientes.
        Schema::table('customer_types', function (Blueprint $table) {
            $table->foreign('price_list_id')->references('id')->on('price_lists')->nullOnDelete();
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->foreign('price_list_id')->references('id')->on('price_lists')->nullOnDelete();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // Identificacion
            $table->string('sku');
            $table->string('internal_code')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();

            // Clasificacion
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('supplier_id')->nullable()->constrained()->nullOnDelete();

            // simple: un solo articulo.
            // variable: se vende por variantes, cada una con su stock.
            // combo: paquete armado con otros productos.
            $table->enum('type', ['simple', 'variable', 'combo'])->default('simple');

            // Unidad en la que se guarda el stock. Todo se convierte a esta.
            $table->foreignUuid('base_unit_id')->constrained('units');
            // Impuesto aplicable. Nulo = exento.
            $table->foreignUuid('tax_id')->nullable()->constrained('taxes')->nullOnDelete();

            // Costo de compra SIN impuesto, en moneda principal.
            $table->decimal('cost', 14, 4)->default(0);

            // Inventario
            $table->boolean('track_stock')->default(true);   // un servicio va en false
            $table->decimal('min_stock', 14, 3)->default(0);
            $table->decimal('max_stock', 14, 3)->nullable();

            // Control avanzado
            $table->boolean('track_lots')->default(false);
            $table->boolean('track_expiry')->default(false);
            $table->boolean('track_serials')->default(false);
            // Dias antes del vencimiento en que se avisa y en que se
            // bloquea la venta. Bloqueo en 0 = no bloquear.
            $table->unsignedSmallInteger('expiry_alert_days')->default(30);
            $table->unsignedSmallInteger('expiry_block_days')->default(0);

            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'status']);
        });

        // --- Variantes (productos tipo 'variable') ---

        // Atributos que generan variantes: Talla, Color, Presentacion.
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('attribute_id')->constrained('product_attributes')->cascadeOnDelete();
            $table->string('value');                      // S, M, L / Rojo, Azul
            $table->unsignedInteger('position')->default(0);

            $table->unique(['attribute_id', 'value']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku');                        // SKU propio
            $table->string('name');                       // "Rojo / M"
            $table->string('image_path')->nullable();
            // Costo propio. Si es nulo, hereda el del producto.
            $table->decimal('cost', 14, 4)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'sku']);
        });

        // Que combinacion de atributos representa cada variante.
        Schema::create('product_variant_values', function (Blueprint $table) {
            $table->foreignUuid('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignUuid('attribute_id')->constrained('product_attributes')->cascadeOnDelete();
            $table->foreignUuid('value_id')->constrained('product_attribute_values')->cascadeOnDelete();

            $table->primary(['variant_id', 'attribute_id']);
        });

        // --- Combos ---

        Schema::create('combo_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('combo_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUuid('variant_id')->nullable()
                ->constrained('product_variants')->restrictOnDelete();
            // Cantidad en unidades base del componente.
            $table->decimal('quantity', 14, 3);

            $table->index('combo_id');
        });

        // --- Presentaciones: vender por unidad, docena o caja ---

        Schema::create('product_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('unit_id')->constrained('units');
            // Cuantas unidades base contiene: Unidad=1, Docena=12, Caja=24.
            $table->decimal('factor', 14, 4);
            // La que aparece seleccionada por defecto en el POS.
            $table->boolean('is_default')->default(false);
            // Se puede usar al comprar.
            $table->boolean('is_purchase')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['product_id', 'unit_id']);
        });

        // --- Precios ---

        Schema::create('product_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('variant_id')->nullable()
                ->constrained('product_variants')->cascadeOnDelete();
            $table->foreignUuid('price_list_id')->constrained()->cascadeOnDelete();
            // Nulo = aplica a la presentacion por defecto.
            $table->foreignUuid('product_unit_id')->nullable()
                ->constrained('product_units')->cascadeOnDelete();
            // Activacion por cantidad: este precio rige desde esta cantidad.
            $table->decimal('min_quantity', 14, 3)->default(1);
            // Precio de venta. Lleva impuesto incluido segun la configuracion
            // de la empresa (tenants.prices_include_tax).
            $table->decimal('price', 14, 4);
            // Margen con el que se calculo, cuando la lista es 'margin'.
            $table->decimal('margin_percent', 9, 4)->nullable();
            // true = capturado a mano; no se recalcula al cambiar el costo.
            $table->boolean('is_manual')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'price_list_id']);
        });

        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('variant_id')->nullable()
                ->constrained('product_variants')->cascadeOnDelete();
            $table->foreignUuid('product_unit_id')->nullable()
                ->constrained('product_units')->cascadeOnDelete();
            $table->string('code');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            // El codigo es unico dentro de la empresa: escanear siempre
            // debe llevar a un solo articulo.
            $table->unique(['tenant_id', 'code']);
            $table->index('product_id');
        });

        // --- Historiales ---

        Schema::create('price_histories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('variant_id')->nullable()
                ->constrained('product_variants')->cascadeOnDelete();
            $table->foreignUuid('price_list_id')->constrained()->cascadeOnDelete();
            $table->decimal('old_price', 14, 4)->nullable();
            $table->decimal('new_price', 14, 4);
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['product_id', 'created_at']);
        });

        Schema::create('cost_histories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('variant_id')->nullable()
                ->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('old_cost', 14, 4)->nullable();
            $table->decimal('new_cost', 14, 4);
            // De donde vino el cambio: una compra o la mano del usuario.
            $table->enum('source', ['manual', 'purchase', 'import'])->default('manual');
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->dropForeign(['price_list_id']));
        Schema::table('customer_types', fn (Blueprint $t) => $t->dropForeign(['price_list_id']));

        Schema::dropIfExists('cost_histories');
        Schema::dropIfExists('price_histories');
        Schema::dropIfExists('product_barcodes');
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('product_units');
        Schema::dropIfExists('combo_items');
        Schema::dropIfExists('product_variant_values');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('product_attributes');
        Schema::dropIfExists('products');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('units');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
    }
};
