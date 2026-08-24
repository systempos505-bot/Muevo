<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nivel 1 de la plataforma: superusuarios, planes, empresas y suscripciones.
 *
 * Todo lo demas del sistema cuelga de `tenants`, por eso esta migracion
 * corre primero.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Superusuarios de la plataforma. No pertenecen a ninguna empresa.
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->rememberToken();
            $table->timestamps();
        });

        // Planes de suscripcion con sus limites y modulos.
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->decimal('price_monthly', 12, 2)->default(0);
            $table->decimal('price_yearly', 12, 2)->default(0);
            // {"products":500,"users":3,"branches":1,"terminals":1,"storage_mb":500}
            // Una clave en null significa ilimitado.
            $table->json('limits');
            // {"purchases":true,"multicurrency":false,...}
            $table->json('features');
            $table->unsignedInteger('position')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        // Empresas (tenants). Cada una es un negocio independiente.
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Identidad
            $table->string('name');                       // nombre del negocio
            $table->string('trade_name')->nullable();     // nombre comercial
            $table->string('legal_name')->nullable();     // razon social
            $table->string('tax_id')->nullable();
            $table->string('logo_path')->nullable();

            // Giro: fija los valores por defecto al crear productos.
            $table->enum('business_type', [
                'pharmacy', 'clothing', 'footwear', 'hardware', 'supermarket', 'general',
            ])->default('general');

            // Contacto
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('whatsapp')->nullable();
            $table->string('email')->unique();
            $table->string('timezone')->default('UTC');

            // Comportamiento de precios en todo el sistema.
            $table->boolean('prices_include_tax')->default(true);
            $table->unsignedTinyInteger('price_decimals')->default(2);
            $table->unsignedTinyInteger('qty_decimals')->default(3);

            $table->enum('status', ['trial', 'active', 'suspended', 'cancelled'])->default('trial');
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained();
            $table->enum('status', ['trialing', 'active', 'past_due', 'suspended', 'cancelled'])
                ->default('trialing');
            $table->enum('billing_cycle', ['monthly', 'yearly'])->default('monthly');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('platform_admins');
    }
};
