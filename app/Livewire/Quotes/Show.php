<?php

namespace App\Livewire\Quotes;

use App\Livewire\Page;
use App\Models\PaymentMethod;
use App\Models\Quote;
use App\Models\Shift;
use App\Models\Terminal;
use App\Services\QuoteRegistrar;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use RuntimeException;
use Throwable;

/**
 * Detalle de una cotizacion y lo que se puede hacer con ella.
 *
 * Aqui se aprueba, se rechaza, se extiende la vigencia y se convierte en
 * venta. Convertir es lo unico que toca inventario y dinero, y por eso
 * es lo unico que exige turno de caja abierto.
 */
#[Layout('layouts.app')]
class Show extends Page
{
    public Quote $quote;

    // --- Rechazo ---
    public bool $showReject = false;

    public string $rejectReason = '';

    // --- Extender vigencia ---
    public bool $showExtend = false;

    public string $newValidUntil = '';

    // --- Conversion en venta ---
    public bool $showConvert = false;

    public ?string $paymentMethodId = null;

    public function mount(string $quoteId): void
    {
        abort_unless(auth()->user()->can('quotes.view'), 403);

        $this->quote = Quote::with(['items', 'customer', 'branch', 'creator', 'responder', 'sale'])
            ->findOrFail($quoteId);

        $this->newValidUntil = now()->addDays(15)->toDateString();
        $this->paymentMethodId = PaymentMethod::active()->where('type', 'cash')->value('id');
    }

    protected function refreshQuote(): void
    {
        $this->quote = $this->quote->fresh(['items', 'customer', 'branch', 'creator', 'responder', 'sale']);
    }

    // =========================================================
    // Caja
    // =========================================================

    #[Computed]
    public function terminalId(): ?string
    {
        return Terminal::where('branch_id', auth()->user()->branch_id)
            ->where('status', 'active')
            ->value('id')
            ?? Terminal::where('status', 'active')->value('id');
    }

    #[Computed]
    public function shift(): ?Shift
    {
        return $this->terminalId ? Shift::openFor($this->terminalId) : null;
    }

    #[Computed]
    public function paymentMethods()
    {
        return PaymentMethod::active()->orderBy('position')->get();
    }

    // =========================================================
    // Respuesta del cliente
    // =========================================================

    public function approve(QuoteRegistrar $registrar): void
    {
        abort_unless(auth()->user()->can('quotes.manage'), 403);

        try {
            $registrar->approve($this->quote);
        } catch (RuntimeException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }

        $this->refreshQuote();
        $this->notify('Cotizacion aprobada');
    }

    public function reject(QuoteRegistrar $registrar): void
    {
        abort_unless(auth()->user()->can('quotes.manage'), 403);

        $this->validate(
            ['rejectReason' => ['required', 'string', 'max:200']],
            ['rejectReason.required' => 'Escribe por que no se tomo.'],
        );

        try {
            $registrar->reject($this->quote, $this->rejectReason);
        } catch (RuntimeException $e) {
            $this->addError('rejectReason', $e->getMessage());

            return;
        }

        $this->showReject = false;
        $this->rejectReason = '';
        $this->refreshQuote();
        $this->notify('Cotizacion rechazada');
    }

    public function reopen(QuoteRegistrar $registrar): void
    {
        abort_unless(auth()->user()->can('quotes.manage'), 403);

        try {
            $registrar->reopen($this->quote);
        } catch (RuntimeException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }

        $this->refreshQuote();
        $this->notify('Cotizacion reabierta');
    }

    public function extend(QuoteRegistrar $registrar): void
    {
        abort_unless(auth()->user()->can('quotes.manage'), 403);

        $this->validate(
            ['newValidUntil' => ['required', 'date', 'after_or_equal:today']],
            ['newValidUntil.after_or_equal' => 'La fecha nueva tiene que ser de hoy en adelante.'],
        );

        try {
            $registrar->extend($this->quote, $this->newValidUntil);
        } catch (RuntimeException $e) {
            $this->addError('newValidUntil', $e->getMessage());

            return;
        }

        $this->showExtend = false;
        $this->refreshQuote();
        $this->notify('Vigencia actualizada');
    }

    // =========================================================
    // Conversion en venta
    // =========================================================

    public function convert(QuoteRegistrar $registrar): void
    {
        abort_unless(auth()->user()->can('quotes.manage'), 403);
        abort_unless(auth()->user()->can('sales.create'), 403);

        if ($this->shift === null) {
            $this->addError('paymentMethodId', 'Abre la caja antes de convertir la cotizacion en venta.');

            return;
        }

        $this->validate(
            ['paymentMethodId' => ['required', 'exists:payment_methods,id']],
            ['paymentMethodId.required' => 'Elige con que se paga.'],
        );

        try {
            $sale = $registrar->convert($this->quote, $this->shift, [[
                'payment_method_id' => $this->paymentMethodId,
                // Se cobra el total exacto de la cotizacion: es la cifra
                // que el cliente vio y acepto.
                'amount' => $this->quote->total,
            ]]);
        } catch (RuntimeException $e) {
            $this->addError('paymentMethodId', $e->getMessage());

            return;
        } catch (Throwable $e) {
            report($e);
            $this->addError('paymentMethodId', 'No se pudo generar la venta. Intenta de nuevo.');

            return;
        }

        $this->showConvert = false;
        $this->notify("Venta {$sale->folio} generada");
        $this->redirectRoute('sales.show', ['saleId' => $sale->id], navigate: true);
    }

    public function render()
    {
        return view('livewire.quotes.show', [
            'currency' => auth()->user()->tenant->primaryCurrency,
        ]);
    }
}
