<?php

namespace App\Livewire\Reports;

use App\Livewire\Page;
use App\Models\Branch;
use App\Services\Reports;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pantalla de reportes.
 *
 * Un solo lugar con pestanas en vez de una pantalla por reporte: quien
 * revisa el negocio compara cifras entre si, y cambiar de pestana sin
 * perder el periodo elegido es lo que hace util la comparacion.
 *
 * Los calculos no viven aqui sino en el servicio Reports, para que la
 * misma cifra salga igual en pantalla, en el panel y en la exportacion.
 */
#[Layout('layouts.app')]
class Index extends Page
{
    /** @var array<string, string> */
    public const TABS = [
        'resumen' => 'Resumen',
        'ventas' => 'Ventas',
        'productos' => 'Productos',
        'inventario' => 'Inventario',
    ];

    #[Url(except: 'resumen')]
    public string $tab = 'resumen';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $branchId = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('reports.view'), 403);

        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'resumen';
        }

        // Por defecto el mes en curso: es el periodo con el que la mayoria
        // de los negocios mide, y evita traer un historico entero de golpe.
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: now()->toDateString();
    }

    /**
     * Periodos de un clic.
     *
     * Escribir dos fechas para ver "lo de ayer" es friccion suficiente
     * para que nadie revise sus numeros a diario.
     */
    public function preset(string $name): void
    {
        [$from, $to] = match ($name) {
            'hoy' => [now(), now()],
            'ayer' => [now()->subDay(), now()->subDay()],
            'semana' => [now()->subDays(6), now()],
            'mes' => [now()->startOfMonth(), now()],
            'mes_pasado' => [
                now()->subMonthNoOverflow()->startOfMonth(),
                now()->subMonthNoOverflow()->endOfMonth(),
            ],
            'ano' => [now()->startOfYear(), now()],
            default => [now()->startOfMonth(), now()],
        };

        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    public function selectTab(string $tab): void
    {
        if (array_key_exists($tab, self::TABS)) {
            $this->tab = $tab;
        }
    }

    /**
     * El rango tal como lo van a recibir las consultas.
     *
     * Se ordena por si alguien escribe la fecha final antes que la
     * inicial: un rango invertido devolveria cero y se leeria como que el
     * negocio no vendio nada.
     *
     * @return array{0: string, 1: string}
     */
    protected function range(): array
    {
        $from = $this->from ?: now()->startOfMonth()->toDateString();
        $to = $this->to ?: now()->toDateString();

        return Carbon::parse($from)->gt(Carbon::parse($to))
            ? [$to, $from]
            : [$from, $to];
    }

    protected function reports(): Reports
    {
        return app(Reports::class);
    }

    protected function branch(): ?string
    {
        return $this->branchId ?: null;
    }

    // ---------------------------------------------------------
    // Cifras
    // ---------------------------------------------------------

    #[Computed]
    public function sales(): array
    {
        [$from, $to] = $this->range();

        return $this->reports()->salesSummary($from, $to, $this->branch());
    }

    #[Computed]
    public function profit(): array
    {
        [$from, $to] = $this->range();

        return $this->reports()->profitAndLoss($from, $to, $this->branch());
    }

    #[Computed]
    public function byDay(): array
    {
        [$from, $to] = $this->range();

        return $this->reports()->salesByDay($from, $to, $this->branch());
    }

    #[Computed]
    public function topProducts(): array
    {
        [$from, $to] = $this->range();

        return $this->reports()->topProducts($from, $to, 10, $this->branch());
    }

    #[Computed]
    public function byPaymentMethod(): array
    {
        [$from, $to] = $this->range();

        return $this->reports()->salesByPaymentMethod($from, $to, $this->branch());
    }

    #[Computed]
    public function byUser(): array
    {
        [$from, $to] = $this->range();

        return $this->reports()->salesByUser($from, $to, $this->branch());
    }

    #[Computed]
    public function byCategory(): array
    {
        [$from, $to] = $this->range();

        return $this->reports()->salesByCategory($from, $to, $this->branch());
    }

    #[Computed]
    public function purchases(): array
    {
        [$from, $to] = $this->range();

        return $this->reports()->purchasesSummary($from, $to);
    }

    #[Computed]
    public function expenses(): array
    {
        [$from, $to] = $this->range();

        return $this->reports()->expensesSummary($from, $to);
    }

    #[Computed]
    public function inventory(): array
    {
        return $this->reports()->inventoryValue($this->branch());
    }

    #[Computed]
    public function balances(): array
    {
        return $this->reports()->balances();
    }

    #[Computed]
    public function deadStock(): array
    {
        [$from, $to] = $this->range();

        return $this->reports()->deadStock($from, $to, 20, $this->branch());
    }

    /**
     * Alto de cada barra de la grafica, en porcentaje del dia mas alto.
     *
     * Se calcula aqui y no en la vista para que la vista no tenga que
     * recorrer los dias dos veces buscando el maximo.
     *
     * @return array<int, array{date: string, label: string, total: float, sales: int, height: float}>
     */
    #[Computed]
    public function chart(): array
    {
        $days = $this->byDay;
        $max = max(array_column($days, 'total') ?: [0]);

        return array_map(fn (array $day) => [
            ...$day,
            'label' => Carbon::parse($day['date'])->format('d/m'),
            // Un dia sin ventas se queda en cero: una barra minima ahi se
            // leeria como que si hubo algo.
            'height' => $max > 0 ? round($day['total'] / $max * 100, 2) : 0.0,
        ], $days);
    }

    // ---------------------------------------------------------
    // Exportacion
    // ---------------------------------------------------------

    /**
     * Baja la pestana actual como CSV.
     *
     * Se transmite fila por fila en vez de armar el archivo en memoria:
     * un ano de ventas por dia no cabe comodo en una sola cadena.
     */
    public function export(): StreamedResponse
    {
        [$from, $to] = $this->range();

        [$headers, $rows] = $this->exportData();

        $name = "reporte-{$this->tab}-{$from}-a-{$to}.csv";

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');

            // Marca de orden de bytes: sin ella Excel abre los acentos rotos.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Encabezados y filas del CSV de la pestana activa.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    protected function exportData(): array
    {
        return match ($this->tab) {
            'ventas' => [
                ['Fecha', 'Ventas', 'Total'],
                array_map(
                    fn (array $d) => [$d['date'], $d['sales'], $d['total']],
                    $this->byDay,
                ),
            ],
            'productos' => [
                ['Producto', 'SKU', 'Cantidad', 'Total', 'Utilidad'],
                array_map(
                    fn (array $p) => [$p['name'], $p['sku'], $p['quantity'], $p['total'], $p['profit']],
                    $this->topProducts,
                ),
            ],
            'inventario' => [
                ['Producto', 'SKU', 'Existencia', 'Valor a costo'],
                array_map(
                    fn (array $p) => [$p['name'], $p['sku'], $p['stock'], $p['value']],
                    $this->deadStock,
                ),
            ],
            default => [
                ['Concepto', 'Importe'],
                [
                    ['Ventas', $this->sales['total']],
                    ['Impuesto', $this->sales['tax']],
                    ['Costo de lo vendido', $this->profit['cost']],
                    ['Utilidad bruta', $this->profit['gross_profit']],
                    ['Gastos', $this->profit['expenses']],
                    ['Utilidad neta', $this->profit['net_profit']],
                    ['Compras', $this->purchases['total']],
                    ['Por cobrar a clientes', $this->balances['receivable']],
                    ['Por pagar a proveedores', $this->balances['payable']],
                    ['Inventario a costo', $this->inventory['value']],
                ],
            ],
        };
    }

    public function render()
    {
        [$from, $to] = $this->range();

        return view('livewire.reports.index', [
            'branches' => Branch::active()->orderBy('name')->get(),
            'currency' => auth()->user()->tenant->primaryCurrency,
            'periodFrom' => $from,
            'periodTo' => $to,
        ]);
    }
}
