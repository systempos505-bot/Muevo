<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Supplier;
use Illuminate\Support\Carbon;

/**
 * Consultas de los reportes.
 *
 * Vive fuera de los componentes para que la misma cifra se calcule en un
 * solo lugar, la pida el panel, la pantalla de reportes o una exportacion.
 * Todas las consultas se agrupan en la base en lugar de traer filas y
 * sumarlas en PHP: un negocio con un ano de ventas tiene demasiadas.
 */
class Reports
{
    /**
     * Acota una consulta a un rango de dias por una columna de tipo fecha.
     *
     * Se compara con date() y no con el valor crudo porque una columna
     * date guardada por Eloquent puede traer la hora pegada; comparar el
     * texto dejaria fuera todo lo del propio dia.
     */
    protected function betweenDates($query, string $column, string $from, string $to): void
    {
        $query->whereDate($column, '>=', $from)->whereDate($column, '<=', $to);
    }

    /**
     * Resumen de ventas de un periodo.
     *
     * @return array{sales: int, total: float, tax: float, cost: float, profit: float, average: float, items: float}
     */
    public function salesSummary(string $from, string $to, ?string $branchId = null, ?string $userId = null): array
    {
        $row = Sale::completed()
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->selectRaw('count(*) as sales,
                         coalesce(sum(total), 0) as total,
                         coalesce(sum(tax), 0) as tax,
                         coalesce(sum(cost_total), 0) as cost')
            ->first();

        $total = (float) $row->total;
        $tax = (float) $row->tax;
        $cost = (float) $row->cost;
        $count = (int) $row->sales;

        return [
            'sales' => $count,
            'total' => Pricing::round($total, 2),
            'tax' => Pricing::round($tax, 2),
            'cost' => Pricing::round($cost, 2),
            // La utilidad se calcula sobre lo cobrado sin impuesto: el
            // impuesto no es del negocio, solo pasa por sus manos.
            'profit' => Pricing::round($total - $tax - $cost, 2),
            'average' => $count > 0 ? Pricing::round($total / $count, 2) : 0.0,
        ];
    }

    /**
     * Ventas dia por dia, para ver la tendencia del periodo.
     *
     * @return array<int, array{date: string, total: float, sales: int}>
     */
    public function salesByDay(string $from, string $to, ?string $branchId = null): array
    {
        $rows = Sale::completed()
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('date(created_at) as day, count(*) as sales, coalesce(sum(total), 0) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        // Los dias sin ventas se rellenan con cero: un hueco en la grafica
        // se lee como "no hay dato", no como "no se vendio".
        $days = [];
        $cursor = Carbon::parse($from);
        $end = Carbon::parse($to);

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $row = $rows->get($key);

            $days[] = [
                'date' => $key,
                'total' => $row ? Pricing::round((float) $row->total, 2) : 0.0,
                'sales' => $row ? (int) $row->sales : 0,
            ];

            $cursor->addDay();
        }

        return $days;
    }

    /**
     * Productos mas vendidos del periodo.
     *
     * @return array<int, array{name: string, sku: ?string, quantity: float, total: float, profit: float}>
     */
    public function topProducts(string $from, string $to, int $limit = 10, ?string $branchId = null): array
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($branchId, fn ($q) => $q->where('sales.branch_id', $branchId))
            ->selectRaw('sale_items.description, sale_items.sku,
                         coalesce(sum(sale_items.base_quantity), 0) as quantity,
                         coalesce(sum(sale_items.total), 0) as total,
                         coalesce(sum(sale_items.net - (sale_items.unit_cost * sale_items.base_quantity)), 0) as profit')
            ->groupBy('sale_items.description', 'sale_items.sku')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->description,
                'sku' => $row->sku,
                'quantity' => Pricing::round((float) $row->quantity, 3),
                'total' => Pricing::round((float) $row->total, 2),
                'profit' => Pricing::round((float) $row->profit, 2),
            ])
            ->all();
    }

    /**
     * Ventas por forma de pago: con que paga la gente.
     *
     * @return array<int, array{method: string, total: float}>
     */
    public function salesByPaymentMethod(string $from, string $to, ?string $branchId = null): array
    {
        return SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($branchId, fn ($q) => $q->where('sales.branch_id', $branchId))
            ->selectRaw('sale_payments.method_label, coalesce(sum(sale_payments.amount_primary), 0) as total')
            ->groupBy('sale_payments.method_label')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->method_label,
                'total' => Pricing::round((float) $row->total, 2),
            ])
            ->all();
    }

    /**
     * Ventas por cajero, para saber quien vendio cuanto.
     *
     * @return array<int, array{name: string, sales: int, total: float}>
     */
    public function salesByUser(string $from, string $to, ?string $branchId = null): array
    {
        return Sale::completed()
            ->join('users', 'users.id', '=', 'sales.user_id')
            ->whereBetween('sales.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($branchId, fn ($q) => $q->where('sales.branch_id', $branchId))
            ->selectRaw('users.name, count(*) as sales, coalesce(sum(sales.total), 0) as total')
            ->groupBy('users.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'sales' => (int) $row->sales,
                'total' => Pricing::round((float) $row->total, 2),
            ])
            ->all();
    }

    /**
     * Ventas por categoria de producto.
     *
     * @return array<int, array{name: string, total: float}>
     */
    public function salesByCategory(string $from, string $to, ?string $branchId = null): array
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($branchId, fn ($q) => $q->where('sales.branch_id', $branchId))
            ->selectRaw('categories.name, coalesce(sum(sale_items.total), 0) as total')
            ->groupBy('categories.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name ?? 'Sin categoria',
                'total' => Pricing::round((float) $row->total, 2),
            ])
            ->all();
    }

    /** Compras del periodo. */
    public function purchasesSummary(string $from, string $to): array
    {
        $row = Purchase::received()
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw('count(*) as purchases,
                         coalesce(sum(total), 0) as total,
                         coalesce(sum(total - paid), 0) as pending')
            ->first();

        return [
            'purchases' => (int) $row->purchases,
            'total' => Pricing::round((float) $row->total, 2),
            'pending' => Pricing::round((float) $row->pending, 2),
        ];
    }

    /** Gastos del periodo, con su desglose por categoria. */
    public function expensesSummary(string $from, string $to): array
    {
        $total = (float) Expense::registered()
            ->tap(fn ($q) => $this->betweenDates($q, 'expenses.expense_date', $from, $to))
            ->sum('total_primary');

        $byCategory = Expense::registered()
            ->tap(fn ($q) => $this->betweenDates($q, 'expenses.expense_date', $from, $to))
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.category_id')
            ->selectRaw('expense_categories.name, coalesce(sum(expenses.total_primary), 0) as total')
            ->groupBy('expense_categories.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name ?? 'Sin categoria',
                'total' => Pricing::round((float) $row->total, 2),
            ])
            ->all();

        return [
            'total' => Pricing::round($total, 2),
            'by_category' => $byCategory,
        ];
    }

    /**
     * Utilidad real del periodo: lo que dejaron las ventas menos los gastos.
     *
     * Es la cifra que de verdad importa, y la unica que junta los dos
     * lados del negocio.
     */
    public function profitAndLoss(string $from, string $to, ?string $branchId = null): array
    {
        $sales = $this->salesSummary($from, $to, $branchId);
        $expenses = $this->expensesSummary($from, $to);

        $gross = $sales['profit'];
        $net = Pricing::round($gross - $expenses['total'], 2);

        return [
            'revenue' => Pricing::round($sales['total'] - $sales['tax'], 2),
            'cost' => $sales['cost'],
            'gross_profit' => $gross,
            'expenses' => $expenses['total'],
            'net_profit' => $net,
            // Margen neto sobre lo vendido sin impuesto.
            'margin' => $sales['total'] - $sales['tax'] > 0
                ? Pricing::round($net / ($sales['total'] - $sales['tax']) * 100, 2)
                : 0.0,
        ];
    }

    /** Inventario valorizado a costo. */
    public function inventoryValue(?string $branchId = null): array
    {
        $row = Inventory::query()
            ->join('products', 'products.id', '=', 'inventories.product_id')
            ->where('products.status', 'active')
            ->where('products.track_stock', true)
            ->when($branchId, fn ($q) => $q->where('inventories.branch_id', $branchId))
            ->selectRaw('count(distinct inventories.product_id) as products,
                         coalesce(sum(inventories.quantity), 0) as units,
                         coalesce(sum(inventories.quantity * inventories.avg_cost), 0) as value')
            ->first();

        return [
            'products' => (int) $row->products,
            'units' => Pricing::round((float) $row->units, 3),
            'value' => Pricing::round((float) $row->value, 2),
        ];
    }

    /** Lo que deben los clientes y lo que se debe a proveedores. */
    public function balances(): array
    {
        return [
            'receivable' => Pricing::round((float) Customer::sum('balance'), 2),
            'payable' => Pricing::round((float) Supplier::sum('balance'), 2),
        ];
    }

    /** Productos que no se han vendido en el periodo, para detectar lo estancado. */
    public function deadStock(string $from, string $to, int $limit = 20, ?string $branchId = null): array
    {
        $sold = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->select('sale_items.product_id');

        // Se agrupa contra un join real en vez de usar una subconsulta con
        // HAVING: filtrar por el alias de una subconsulta no es portable
        // entre motores, y aqui hace falta descartar lo que no tiene
        // existencia antes de recortar al limite.
        return Product::query()
            ->active()
            ->join('inventories', 'inventories.product_id', '=', 'products.id')
            ->where('products.track_stock', true)
            ->whereNotIn('products.id', $sold)
            ->when($branchId, fn ($q) => $q->where('inventories.branch_id', $branchId))
            ->selectRaw('products.name, products.sku, products.cost,
                         coalesce(sum(inventories.quantity), 0) as stock')
            ->groupBy('products.id', 'products.name', 'products.sku', 'products.cost')
            ->havingRaw('coalesce(sum(inventories.quantity), 0) > 0')
            ->orderByDesc('stock')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'sku' => $row->sku,
                'stock' => Pricing::round((float) $row->stock, 3),
                'value' => Pricing::round((float) $row->stock * (float) $row->cost, 2),
            ])
            ->all();
    }
}
