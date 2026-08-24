<?php

use App\Livewire\Finance\Accounts as AccountsScreen;
use App\Livewire\Finance\AccountShow;
use App\Livewire\Finance\Expenses as ExpensesScreen;
use App\Models\Account;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\ExpenseRegistrar;
use App\Services\Treasury;
use Livewire\Livewire;

beforeEach(function () {
    $this->context = actingAsTenant();
    $this->treasury = app(Treasury::class);

    $this->caja = Account::where('name', 'Caja')->first();
    $this->banco = Account::where('name', 'Banco')->first();

    $this->treasury->move($this->caja, 'in', 5000, 'Fondo inicial');
});

// =============================================================
// Cuentas
// =============================================================

describe('cuentas', function () {
    it('muestra las cuentas con su saldo', function () {
        Livewire::test(AccountsScreen::class)
            ->assertSee('Caja')
            ->assertSee('Banco');

        expect(Livewire::test(AccountsScreen::class)->get('totalBalance'))->toBe(5000.0);
    });

    it('crea una cuenta nueva', function () {
        Livewire::test(AccountsScreen::class)
            ->call('create')
            ->set('name', 'Billetera digital')
            ->set('type', 'digital')
            ->call('save')
            ->assertHasNoErrors();

        $created = Account::where('name', 'Billetera digital')->first();

        expect($created)->not->toBeNull()
            ->and($created->type)->toBe('digital')
            ->and($created->balance)->toBe(0.0);
    });

    it('no acepta dos cuentas con el mismo nombre', function () {
        Livewire::test(AccountsScreen::class)
            ->call('create')
            ->set('name', 'Caja')
            ->call('save')
            ->assertHasErrors('name');
    });

    it('registra una entrada manual', function () {
        Livewire::test(AccountsScreen::class)
            ->call('openMovement', $this->caja->id, 'in')
            ->set('movementAmount', 1500)
            ->set('movementDescription', 'Deposito del dueno')
            ->call('saveMovement')
            ->assertHasNoErrors();

        expect($this->caja->fresh()->balance)->toBe(6500.0);
    });

    it('exige concepto en el movimiento manual', function () {
        Livewire::test(AccountsScreen::class)
            ->call('openMovement', $this->caja->id, 'out')
            ->set('movementAmount', 100)
            ->set('movementDescription', '')
            ->call('saveMovement')
            ->assertHasErrors('movementDescription');
    });

    it('no deja sacar mas de lo que hay', function () {
        Livewire::test(AccountsScreen::class)
            ->call('openMovement', $this->caja->id, 'out')
            ->set('movementAmount', 9000)
            ->set('movementDescription', 'Retiro imposible')
            ->call('saveMovement')
            ->assertHasErrors('movementAmount');

        expect($this->caja->fresh()->balance)->toBe(5000.0);
    });

    it('traslada dinero entre cuentas', function () {
        Livewire::test(AccountsScreen::class)
            ->call('openTransfer')
            ->set('fromAccountId', $this->caja->id)
            ->set('toAccountId', $this->banco->id)
            ->set('transferAmount', 2000)
            ->call('saveTransfer')
            ->assertHasNoErrors();

        expect($this->caja->fresh()->balance)->toBe(3000.0)
            ->and($this->banco->fresh()->balance)->toBe(2000.0);
    });

    it('no deja trasladar a la misma cuenta', function () {
        Livewire::test(AccountsScreen::class)
            ->call('openTransfer')
            ->set('fromAccountId', $this->caja->id)
            ->set('toAccountId', $this->caja->id)
            ->set('transferAmount', 100)
            ->call('saveTransfer')
            ->assertHasErrors('toAccountId');
    });

    it('adelanta cuanto llega al trasladar entre monedas', function () {
        $eur = Currency::create([
            'code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'rate' => 25,
        ]);
        $euros = Account::create([
            'name' => 'Euros', 'type' => 'bank', 'currency_id' => $eur->id,
        ]);

        $component = Livewire::test(AccountsScreen::class)
            ->call('openTransfer')
            ->set('fromAccountId', $this->caja->id)
            ->set('toAccountId', $euros->id)
            ->set('transferAmount', 500);

        // Se muestra antes de confirmar, para no sacar la calculadora.
        expect($component->get('transferPreview')['amount'])->toBe(20.0)
            ->and($component->get('transferPreview')['cross'])->toBeTrue();
    });

    it('no deja desactivar una cuenta con saldo', function () {
        Livewire::test(AccountsScreen::class)->call('toggleStatus', $this->caja->id);

        // Esconderla haria que el dinero deje de sumarse a lo disponible.
        expect($this->caja->fresh()->status)->toBe('active');
    });

    it('desactiva una cuenta vacia', function () {
        Livewire::test(AccountsScreen::class)->call('toggleStatus', $this->banco->id);

        expect($this->banco->fresh()->status)->toBe('inactive');
    });

    it('lista los movimientos de una cuenta', function () {
        $this->treasury->move($this->caja, 'out', 300, 'Pago de mensajeria');

        Livewire::test(AccountShow::class, ['accountId' => $this->caja->id])
            ->assertSee('Fondo inicial')
            ->assertSee('Pago de mensajeria')
            ->assertViewHas('totalIn', 5000.0)
            ->assertViewHas('totalOut', 300.0);
    });

    it('filtra los movimientos por origen', function () {
        $this->treasury->move($this->caja, 'in', 800, 'Venta del dia', 'sale');

        Livewire::test(AccountShow::class, ['accountId' => $this->caja->id])
            ->set('source', 'sale')
            ->assertSee('Venta del dia')
            ->assertDontSee('Fondo inicial');
    });
});

// =============================================================
// Gastos
// =============================================================

describe('gastos', function () {
    beforeEach(function () {
        $this->categoria = ExpenseCategory::where('name', 'Renta')->first();
    });

    it('registra un gasto y baja el saldo', function () {
        Livewire::test(ExpensesScreen::class)
            ->call('create')
            ->set('description', 'Renta del local de agosto')
            ->set('total', 1200)
            ->set('formCategoryId', $this->categoria->id)
            ->set('accountId', $this->caja->id)
            ->call('save')
            ->assertHasNoErrors();

        expect(Expense::count())->toBe(1)
            ->and($this->caja->fresh()->balance)->toBe(3800.0);
    });

    it('exige concepto y monto', function () {
        Livewire::test(ExpensesScreen::class)
            ->call('create')
            ->set('description', '')
            ->set('total', 0)
            ->call('save')
            ->assertHasErrors(['description', 'total']);
    });

    it('muestra el error cuando no alcanza el saldo', function () {
        Livewire::test(ExpensesScreen::class)
            ->call('create')
            ->set('description', 'Gasto imposible')
            ->set('total', 9000)
            ->set('accountId', $this->caja->id)
            ->call('save')
            ->assertHasErrors('total');

        expect(Expense::count())->toBe(0);
    });

    it('permite un gasto sin cuenta', function () {
        Livewire::test(ExpensesScreen::class)
            ->call('create')
            ->set('description', 'Gasto solo anotado')
            ->set('total', 300)
            ->set('accountId', '')
            ->call('save')
            ->assertHasNoErrors();

        expect(Expense::sole()->account_id)->toBeNull()
            ->and($this->caja->fresh()->balance)->toBe(5000.0);
    });

    it('resume el gasto por categoria', function () {
        $registrar = app(ExpenseRegistrar::class);
        $servicios = ExpenseCategory::where('name', 'Servicios')->first();

        $registrar->register(1200, 'Renta', $this->categoria->id, $this->caja->id);
        $registrar->register(400, 'Luz', $servicios->id, $this->caja->id);
        $registrar->register(200, 'Agua', $servicios->id, $this->caja->id);

        $component = Livewire::test(ExpensesScreen::class);

        expect($component->get('summary')['total'])->toBe(1800.0);

        $byCategory = $component->get('byCategory');

        // Ordenado de mayor a menor: donde mas se va el dinero primero.
        expect($byCategory[0]['name'])->toBe('Renta')
            ->and($byCategory[0]['total'])->toBe(1200.0)
            ->and($byCategory[1]['name'])->toBe('Servicios')
            ->and($byCategory[1]['total'])->toBe(600.0);
    });

    it('anula un gasto y devuelve el dinero', function () {
        $expense = app(ExpenseRegistrar::class)
            ->register(1200, 'Renta', $this->categoria->id, $this->caja->id);

        Livewire::test(ExpensesScreen::class)
            ->call('openCancel', $expense->id)
            ->set('cancelReason', 'Se registro dos veces')
            ->call('cancel')
            ->assertHasNoErrors();

        expect($expense->fresh()->status)->toBe('cancelled')
            ->and($this->caja->fresh()->balance)->toBe(5000.0);
    });

    it('exige motivo para anular', function () {
        $expense = app(ExpenseRegistrar::class)
            ->register(100, 'Gasto', $this->categoria->id, $this->caja->id);

        Livewire::test(ExpensesScreen::class)
            ->call('openCancel', $expense->id)
            ->set('cancelReason', '')
            ->call('cancel')
            ->assertHasErrors('cancelReason');
    });

    it('repite un gasto recurrente', function () {
        $expense = app(ExpenseRegistrar::class)->register(
            total: 1200,
            description: 'Renta del local',
            categoryId: $this->categoria->id,
            accountId: $this->caja->id,
            expenseDate: '2026-01-05',
            isRecurring: true,
        );

        Livewire::test(ExpensesScreen::class)->call('repeat', $expense->id);

        expect(Expense::count())->toBe(2)
            ->and($this->caja->fresh()->balance)->toBe(2600.0);
    });

    it('agrega una categoria nueva', function () {
        Livewire::test(ExpensesScreen::class)
            ->set('newCategory', 'Publicidad')
            ->call('addCategory')
            ->assertHasNoErrors();

        expect(ExpenseCategory::where('name', 'Publicidad')->exists())->toBeTrue();
    });

    it('no acepta dos categorias con el mismo nombre', function () {
        Livewire::test(ExpensesScreen::class)
            ->set('newCategory', 'Renta')
            ->call('addCategory')
            ->assertHasErrors('newCategory');
    });

    it('no deja borrar una categoria con gastos', function () {
        app(ExpenseRegistrar::class)->register(100, 'Renta', $this->categoria->id, $this->caja->id);

        Livewire::test(ExpensesScreen::class)->call('deleteCategory', $this->categoria->id);

        expect(ExpenseCategory::find($this->categoria->id))->not->toBeNull();
    });

    it('no deja anular sin el permiso', function () {
        $expense = app(ExpenseRegistrar::class)
            ->register(100, 'Gasto', $this->categoria->id, $this->caja->id);

        $this->context['user']->update(['permissions_override' => ['expenses.void' => false]]);

        Livewire::test(ExpensesScreen::class)
            ->call('openCancel', $expense->id)
            ->assertForbidden();
    });
});
