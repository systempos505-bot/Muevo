<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes y proveedores.
 *
 * Van antes del catalogo porque los productos apuntan a su proveedor.
 * La relacion con listas de precios se agrega en la migracion del
 * catalogo, cuando esa tabla ya existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Agrupan clientes por comportamiento comercial y les fijan
        // una lista de precios: mayorista, minorista, empleado...
        Schema::create('customer_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->uuid('price_list_id')->nullable();     // FK en la migracion del catalogo
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code')->nullable();
            $table->string('name');
            $table->string('tax_id')->nullable();          // identificacion fiscal
            $table->foreignUuid('customer_type_id')->nullable()->constrained()->nullOnDelete();
            // Lista propia del cliente. Si es nula, hereda la de su tipo.
            $table->uuid('price_list_id')->nullable();
            $table->string('phone')->nullable();
            $table->string('whatsapp')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();

            // Credito
            $table->boolean('credit_enabled')->default(false);
            $table->decimal('credit_limit', 14, 2)->default(0);
            $table->unsignedSmallInteger('credit_days')->default(0);
            // Lo que el cliente debe al negocio.
            $table->decimal('balance', 14, 2)->default(0);

            $table->text('notes')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code')->nullable();
            $table->string('name');
            $table->string('tax_id')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('whatsapp')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->unsignedSmallInteger('credit_days')->default(0);
            // Lo que el negocio le debe al proveedor.
            $table->decimal('balance', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('customer_types');
    }
};
