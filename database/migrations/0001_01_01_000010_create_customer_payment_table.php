<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abonos de clientes a su cuenta.
 *
 * Una venta a credito sube el saldo del cliente; un abono lo baja. Juntos
 * arman el estado de cuenta, que es lo que se le muestra cuando pregunta
 * cuanto debe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            // Abono a una venta concreta, o a la cuenta en general.
            $table->foreignUuid('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('payment_method_id')->nullable()
                ->constrained('payment_methods')->nullOnDelete();
            // Turno en el que se recibio, para que cuente en el arqueo.
            $table->foreignUuid('shift_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('amount', 14, 2);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payments');
    }
};
