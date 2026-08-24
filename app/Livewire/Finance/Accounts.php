<?php

namespace App\Livewire\Finance;

use App\Livewire\Page;
use App\Models\Account;
use App\Models\Currency;
use App\Services\Treasury;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use RuntimeException;

/** Cuentas de pago, sus saldos y los traslados entre ellas. */
#[Layout('layouts.app')]
class Accounts extends Page
{
    // --- Alta y edicion ---
    public bool $showForm = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $type = 'cash';

    public string $currencyId = '';

    public string $reference = '';

    // --- Movimiento manual ---
    public bool $showMovement = false;

    public ?string $movementAccountId = null;

    public string $movementDirection = 'in';

    public ?float $movementAmount = null;

    public string $movementDescription = '';

    // --- Traslado ---
    public bool $showTransfer = false;

    public string $fromAccountId = '';

    public string $toAccountId = '';

    public ?float $transferAmount = null;

    public string $transferDescription = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('finance.view'), 403);
    }

    #[Computed]
    public function treasury(): Treasury
    {
        return app(Treasury::class);
    }

    #[Computed]
    public function accounts()
    {
        return Account::with('currency')->orderByDesc('is_default')->orderBy('name')->get();
    }

    #[Computed]
    public function totalBalance(): float
    {
        return $this->treasury->totalBalance();
    }

    /** Lo que se recibiria al trasladar, ya convertido. */
    #[Computed]
    public function transferPreview(): ?array
    {
        if (! $this->fromAccountId || ! $this->toAccountId || ! $this->transferAmount) {
            return null;
        }

        $from = Account::with('currency')->find($this->fromAccountId);
        $to = Account::with('currency')->find($this->toAccountId);

        if ($from === null || $to === null || $from->id === $to->id) {
            return null;
        }

        $rate = $this->treasury->conversionRate($from, $to);

        return [
            'rate' => $rate,
            'amount' => round((float) $this->transferAmount * $rate, 2),
            'symbol' => $to->currency?->symbol ?? '',
            'cross' => $rate != 1.0,
        ];
    }

    // =========================================================
    // Alta y edicion
    // =========================================================

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'reference']);
        $this->type = 'cash';
        $this->currencyId = (string) Currency::where('is_primary', true)->value('id');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        $account = Account::findOrFail($id);

        $this->editingId = $account->id;
        $this->name = $account->name;
        $this->type = $account->type;
        $this->currencyId = (string) $account->currency_id;
        $this->reference = (string) $account->reference;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('finance.manage'), 403);

        $data = $this->validate([
            'name' => [
                'required', 'string', 'min:2', 'max:80',
                Rule::unique('accounts', 'name')
                    ->where('tenant_id', auth()->user()->tenant_id)
                    ->ignore($this->editingId),
            ],
            'type' => ['required', Rule::in(array_keys(Account::TYPES))],
            'currencyId' => ['required', Rule::exists('currencies', 'id')],
            'reference' => ['nullable', 'string', 'max:80'],
        ], ['name.unique' => 'Ya tienes una cuenta con ese nombre.']);

        Account::updateOrCreate(['id' => $this->editingId], [
            'name' => $data['name'],
            'type' => $data['type'],
            'currency_id' => $data['currencyId'],
            'reference' => $data['reference'] ?: null,
        ]);

        unset($this->accounts, $this->totalBalance);
        $this->showForm = false;
        $this->notify('Cuenta guardada');
    }

    public function toggleStatus(string $id): void
    {
        abort_unless(auth()->user()->can('finance.manage'), 403);

        $account = Account::findOrFail($id);

        // Una cuenta con saldo no se esconde: el dinero seguiria ahi pero
        // dejaria de sumarse a lo que el negocio cree que tiene.
        if ($account->balance != 0.0 && $account->status === 'active') {
            $this->notify('Esta cuenta tiene saldo. Vacíala antes de desactivarla.', 'error');

            return;
        }

        $account->update(['status' => $account->status === 'active' ? 'inactive' : 'active']);
        unset($this->accounts, $this->totalBalance);
        $this->notify($account->status === 'active' ? 'Cuenta activada' : 'Cuenta desactivada');
    }

    // =========================================================
    // Movimiento manual
    // =========================================================

    public function openMovement(string $accountId, string $direction = 'in'): void
    {
        abort_unless(auth()->user()->can('finance.manage'), 403);

        $this->movementAccountId = $accountId;
        $this->movementDirection = $direction;
        $this->reset(['movementAmount', 'movementDescription']);
        $this->resetValidation();
        $this->showMovement = true;
    }

    public function saveMovement(): void
    {
        abort_unless(auth()->user()->can('finance.manage'), 403);

        $this->validate([
            'movementAmount' => ['required', 'numeric', 'gt:0'],
            'movementDescription' => ['required', 'string', 'min:3', 'max:200'],
        ], [
            'movementAmount.gt' => 'El monto debe ser mayor que cero.',
            'movementDescription.required' => 'Escribe de que se trata el movimiento.',
        ]);

        $account = Account::findOrFail($this->movementAccountId);

        if ($this->movementDirection === 'out' && $this->movementAmount > $account->balance) {
            $this->addError('movementAmount', "No hay tanto saldo en {$account->name}.");

            return;
        }

        try {
            $this->treasury->move(
                account: $account,
                direction: $this->movementDirection,
                amount: (float) $this->movementAmount,
                description: $this->movementDescription,
            );
        } catch (RuntimeException $e) {
            $this->addError('movementAmount', $e->getMessage());

            return;
        }

        unset($this->accounts, $this->totalBalance);
        $this->showMovement = false;
        $this->notify('Movimiento registrado');
    }

    // =========================================================
    // Traslado
    // =========================================================

    public function openTransfer(): void
    {
        abort_unless(auth()->user()->can('finance.manage'), 403);

        $this->reset(['fromAccountId', 'toAccountId', 'transferAmount', 'transferDescription']);
        $this->resetValidation();
        $this->showTransfer = true;
    }

    public function saveTransfer(): void
    {
        abort_unless(auth()->user()->can('finance.manage'), 403);

        $this->validate([
            'fromAccountId' => ['required', Rule::exists('accounts', 'id')],
            'toAccountId' => ['required', Rule::exists('accounts', 'id'), 'different:fromAccountId'],
            'transferAmount' => ['required', 'numeric', 'gt:0'],
        ], [
            'toAccountId.different' => 'Elige dos cuentas distintas.',
            'transferAmount.gt' => 'El monto debe ser mayor que cero.',
        ]);

        try {
            $this->treasury->transfer(
                from: Account::with('currency')->findOrFail($this->fromAccountId),
                to: Account::with('currency')->findOrFail($this->toAccountId),
                amount: (float) $this->transferAmount,
                description: $this->transferDescription ?: null,
            );
        } catch (RuntimeException $e) {
            $this->addError('transferAmount', $e->getMessage());

            return;
        }

        unset($this->accounts, $this->totalBalance);
        $this->showTransfer = false;
        $this->notify('Traslado registrado');
    }

    public function render()
    {
        return view('livewire.finance.accounts', [
            'currencies' => Currency::where('status', 'active')->orderByDesc('is_primary')->get(),
            'primary' => auth()->user()->tenant->primaryCurrency,
            'flow' => $this->treasury->cashFlow(now()->startOfMonth()->toDateString()),
        ]);
    }
}
