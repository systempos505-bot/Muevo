<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estructura interna de cada empresa: sucursales, cajas, roles y usuarios.
 * Incluye las tablas de sesion y recuperacion de contrasena de Laravel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_default')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('terminals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            // Prefijo de folios de esta caja, para que dos cajas nunca
            // generen el mismo numero de venta.
            $table->string('folio_prefix', 8);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // tenant_id nulo = rol de sistema, disponible para todas las empresas.
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            // {"products.create":true,"sales.discount":true,...}
            $table->json('permissions');
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('role_id')->constrained();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            // PIN de caja, hasheado igual que la contrasena. Nunca en claro.
            $table->string('pin')->nullable();
            // Excepciones sobre los permisos del rol.
            $table->json('permissions_override')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->rememberToken();
            $table->timestamps();

            // El correo es unico dentro de la empresa, no en todo el sistema:
            // la misma persona puede trabajar en dos negocios distintos.
            $table->unique(['tenant_id', 'email']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('terminals');
        Schema::dropIfExists('branches');
    }
};
