<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas de pago y gastos.
 *
 * Una cuenta es donde vive el dinero: la caja chica, el banco, la cuenta
 * digital. Cada una lleva su moneda y su saldo, y todo lo que entra o
 * sale deja un movimiento que lo explica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('currency_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->enum('type', ['cash', 'bank', 'card', 'digital'])->default('cash');
            // Numero de cuenta o referencia del banco.
            $table->string('reference')->nullable();

            // Saldo en la moneda de la cuenta. Lo mueven los movimientos.
            $table->decimal('balance', 16, 2)->default(0);
            $table->boolean('is_default')->default(false);

            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        // Toda entrada o salida de una cuenta. Es solo de escritura: un
        // error se corrige con otro movimiento, nunca alterando el viejo.
        Schema::create('account_movements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();

            $table->enum('direction', ['in', 'out']);
            // Monto en la moneda de la cuenta.
            $table->decimal('amount', 16, 2);
            // Tipo de cambio aplicado y equivalente en moneda principal.
            // Se guarda para que un reporte viejo no cambie si sube el dolar.
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('amount_primary', 16, 2);
            // Saldo de la cuenta despues del movimiento.
            $table->decimal('balance', 16, 2);

            // De donde vino: una venta, una compra, un gasto, un traslado.
            $table->string('source', 20)->default('manual');
            $table->uuid('source_id')->nullable();

            $table->string('description');
            $table->string('reference')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['account_id', 'created_at']);
            $table->index(['tenant_id', 'source', 'source_id'], 'account_movements_source_index');
        });

        // Traslado entre cuentas. Genera dos movimientos y los liga.
        Schema::create('account_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('from_account_id')->constrained('accounts');
            $table->foreignUuid('to_account_id')->constrained('accounts');

            // Los montos pueden diferir cuando las cuentas tienen monedas
            // distintas; el tipo de cambio usado queda guardado.
            $table->decimal('amount_from', 16, 2);
            $table->decimal('amount_to', 16, 2);
            $table->decimal('exchange_rate', 18, 6)->default(1);

            $table->string('description')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 20)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('category_id')->nullable()
                ->constrained('expense_categories')->nullOnDelete();
            $table->foreignUuid('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('user_id')->constrained('users');

            $table->string('folio');
            $table->date('expense_date');

            // Monto en la moneda de la cuenta con la que se pago.
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('total_primary', 14, 2);

            $table->string('description');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            // Un gasto que se repite: renta, luz, internet. Se marca para
            // poder repetirlo con un clic el mes siguiente.
            $table->boolean('is_recurring')->default(false);

            $table->enum('status', ['registered', 'cancelled'])->default('registered');
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'folio']);
            $table->index(['tenant_id', 'expense_date']);
            $table->index(['category_id', 'status']);
        });

        // Las formas de pago apuntan a la cuenta donde cae el dinero:
        // el efectivo a la caja, la tarjeta al banco. Sin esto una venta
        // no sabria a que cuenta abonar.
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->foreignUuid('account_id')->nullable()->after('type')
                ->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropColumn('account_id');
        });

        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('account_transfers');
        Schema::dropIfExists('account_movements');
        Schema::dropIfExists('accounts');
    }
};
