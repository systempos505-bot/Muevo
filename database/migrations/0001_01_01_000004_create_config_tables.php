<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuracion de la empresa: monedas, tipos de cambio, impuestos,
 * numeracion de documentos y bitacora de auditoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 3);                    // USD, MXN, HNL
            $table->string('name');
            $table->string('symbol', 8);
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->boolean('is_primary')->default(false);
            // Cuantas unidades de esta moneda equivalen a 1 de la principal.
            // La moneda principal siempre vale 1.
            $table->decimal('rate', 18, 6)->default(1);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // Historial de tipos de cambio. Cada venta guarda el que uso,
        // para que un reporte viejo no cambie si hoy sube el dolar.
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('currency_id')->constrained()->cascadeOnDelete();
            $table->decimal('rate', 18, 6);
            $table->timestamp('effective_at');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'currency_id', 'effective_at']);
        });

        Schema::create('taxes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');                       // IVA 15%, Exento
            $table->decimal('rate', 7, 4)->default(0);    // porcentaje
            $table->boolean('is_default')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('document_series', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('doc_type', [
                'sale', 'quote', 'credit_note', 'purchase', 'expense',
                'transfer', 'adjustment', 'shift',
            ]);
            $table->string('prefix', 16)->default('');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(6);
            $table->timestamps();

            $table->unique(['tenant_id', 'branch_id', 'doc_type'], 'document_series_unique');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entity');                     // product, sale, ...
            $table->uuid('entity_id')->nullable();
            $table->enum('action', ['create', 'update', 'delete', 'void', 'login', 'export', 'import']);
            // {campo: {before, after}}
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'entity', 'entity_id'], 'audit_logs_entity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('document_series');
        Schema::dropIfExists('taxes');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
    }
};
