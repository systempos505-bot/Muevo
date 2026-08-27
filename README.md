# Muevo POS

Sistema de facturación y punto de venta SaaS, multiempresa y multisucursal.
Pensado para farmacias, tiendas de ropa, zapaterías, ferreterías y supermercados.

**Stack:** Laravel 13 · Livewire · Tailwind 4 · MySQL

---

## Estado actual

| Módulo | Estado |
|---|---|
| Cimientos (empresas, sucursales, cajas, roles, usuarios) | ✅ Listo |
| Sucursales: alta, caja y series de folios | ✅ Listo |
| Usuarios, roles y permisos | ✅ Listo |
| Configuración (monedas, impuestos, numeración) | ✅ Esquema listo |
| Catálogo de productos | ✅ Listo |
| Categorías, marcas y unidades | ✅ Listo |
| Listas de precios | ✅ Listo |
| Inventario: existencias, kardex y ajustes | ✅ Listo |
| Traspasos entre sucursales | ✅ Listo |
| Inventario físico (conteos) | ✅ Listo |
| Inventario: lotes, vencimientos y series | ✅ Esquema listo |
| Clientes y cuentas por cobrar | ✅ Listo |
| Punto de venta | ✅ Listo |
| Caja y turnos | ✅ Listo |
| Ventas: historial, ticket y anulación | ✅ Listo |
| Devoluciones y notas de crédito | ✅ Listo |
| Compras y cuentas por pagar | ✅ Listo |
| Proveedores | ✅ Listo |
| Cuentas de pago y tesorería | ✅ Listo |
| Gastos | ✅ Listo |
| Promociones (2x1, %, monto, paquete) | ✅ Listo |
| Reportes y exportación a CSV | ✅ Listo |
| Panel de superusuario | ⬜ Pendiente |

---

## Instalación

Necesitas PHP 8.3+, Composer, Node 20+ y MySQL 8 (o MariaDB 10.6+).

```bash
# 1. Dependencias
composer install
npm install

# 2. Configuración
cp .env.example .env
php artisan key:generate

# 3. Base de datos
#    Crea la base y ajusta DB_DATABASE, DB_USERNAME y DB_PASSWORD en .env
mysql -u root -e "CREATE DATABASE muevo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan migrate

# 4. Arrancar
php artisan serve      # en una terminal
npm run dev            # en otra
```

Abre `http://localhost:8000` y registra tu negocio.

---

## Pruebas

```bash
vendor/bin/pest
```

Corren contra SQLite en memoria, así que no tocan tu base de trabajo.

---

## Cómo está organizado

```
app/
  Livewire/           Pantallas (cada una es un componente)
    Page.php          Base con los avisos en pantalla
    Auth/             Registro e inicio de sesión
    Products/         Listado y formulario de productos
    Catalog/          Categorías, marcas, unidades y listas de precios
    Inventory/        Existencias, ajustes y kardex
    Pos/              Pantalla de venta y caja
    Sales/            Historial y ticket
    Purchases/        Compras y cuentas por pagar
    Partners/         Clientes y proveedores
    Finance/          Cuentas de pago y gastos
  Models/             Modelos Eloquent
    Concerns/
      BelongsToTenant.php   Aísla los datos por empresa
  Services/
    Pricing.php             Impuesto, margen y precio sugerido
    InventoryManager.php    Único punto por el que se mueve el stock
    SaleRegistrar.php       Registra la venta completa
    CashRegister.php        Apertura, movimientos y corte de caja
    PurchaseRegistrar.php   Recibe mercancía y actualiza costos
    CustomerAccount.php     Crédito, abonos y estado de cuenta
    Treasury.php            Único punto por el que se mueve el dinero
    ExpenseRegistrar.php    Gastos y su efecto en las cuentas
    PromotionEngine.php     Descuento que hace cada promocion
    ReturnRegistrar.php     Devoluciones y notas de credito
    TransferManager.php     Traspasos entre sucursales
    StockCountManager.php   Inventario fisico: contar y ajustar la diferencia
    Reports.php             Consultas agregadas de los reportes
    TenantProvisioner.php   Deja lista una empresa nueva
  Support/
    Permissions.php         Catalogo de permisos del sistema
    Tenancy.php             Empresa activa durante la petición
    Navigation.php          Menú, filtrado por permisos
  Auth/
    TenantAwareUserProvider.php   Busca al usuario que inicia sesión
database/migrations/  Esquema completo
resources/views/
  layouts/            app (con sesión) y guest (sin sesión)
  components/         Botones, campos y tarjetas reutilizables
  livewire/           Vistas de cada pantalla
tests/
  Unit/               Motor de precios
  Feature/            Registro, sesión, productos, catálogo, inventario,
                      ventas, caja, compras, clientes, tesorería y
                      aislamiento entre empresas
```

---

## Decisiones que conviene conocer

**El aislamiento entre empresas vive en el código.** MySQL no tiene
Row Level Security, así que lo resuelve el trait `BelongsToTenant`: filtra
toda consulta por `tenant_id` y lo rellena solo al crear. Si no hay empresa
activa, las consultas devuelven vacío en lugar de devolver todo — ante un
error de configuración es preferible una pantalla en blanco a mostrarle a un
negocio los datos de otro. Hay diez pruebas cuidando exactamente esto.

**El inventario se guarda siempre en la unidad base.** Un producto se puede
vender por unidad, por docena o por caja, y cada presentación tiene su factor
de equivalencia. Vender una caja de 24 descuenta 24, no 1. Así nunca hay dos
números de existencia que se puedan desincronizar.

**Neto + impuesto siempre da el bruto, al centavo.** El desglose se calcula a
partir de lo que el cliente realmente paga, no al revés. El impuesto absorbe
la diferencia de redondeo, para que un comprobante nunca quede descuadrado.

**Los precios son listas del negocio, no campos sueltos.** Público, Mayoreo,
Distribuidor y las que hagan falta, hasta diez. Una lista puede trabajar por
margen y recalcularse sola cuando cambia el costo, salvo que alguien haya
capturado un precio a mano. Además cada precio se puede activar a partir de
cierta cantidad, que es como se arman los precios por volumen.

**El kardex es solo de escritura.** Ningún movimiento se edita ni se borra:
corregir un error se hace con un movimiento nuevo. Es lo que permite explicar
después cómo se llegó a la existencia actual.

**El giro define los valores por defecto.** Una farmacia crea productos con
lotes y vencimiento ya activados; una tienda de ropa, con variantes. Un solo
motor por debajo, pero cada producto activa nada más lo que usa, para que el
formulario no tenga cuarenta casillas.

**Todo movimiento de inventario pasa por `InventoryManager`.** Actualizar la
existencia y escribir el renglón del kardex tienen que ocurrir juntos o no
ocurrir; si cada pantalla lo hiciera por su cuenta, tarde o temprano quedaría
una cantidad sin explicación. La fila se bloquea mientras se actualiza, para
que dos ajustes simultáneos no escriban saldos inconsistentes.

**El costo promedio solo se mueve al entrar mercancía.** En una entrada el
costo es lo que se está pagando ahora; en una salida, el promedio acumulado.
Una salida no cambia lo que costó lo que ya estaba guardado.

**Una venta se registra entera o no se registra.** Folio, líneas, pagos,
descuento de inventario y saldo del cliente ocurren en una sola transacción.
Una venta a medias sería peor que una venta rechazada, porque descuadraría el
inventario o el dinero sin que nadie se entere.

**La línea de venta guarda copia de todo.** Nombre, precio y costo tal como
estaban al vender. Un ticket de hace seis meses se reimprime igual aunque el
producto haya cambiado de precio o ya no exista, y la utilidad de esa venta no
se mueve cuando sube el costo.

**Con lotes, la venta consume por FEFO.** Sale primero lo que vence antes, y un
lote vencido o dentro de su ventana de bloqueo se salta solo. El cajero no
elige nada. Si los lotes no alcanzan, la venta se acepta igual y el faltante
queda visible: en el mostrador es peor no poder cobrar que quedar con un
número negativo marcado para revisar.

**El cambio solo se calcula sobre lo que se puede devolver.** Pagar de más con
tarjeta no genera efectivo en el cajón, así que no genera cambio.

**Nadie vende sin turno de caja abierto.** Es lo que permite cuadrar el
efectivo al final del día. Una caja no puede tener dos turnos abiertos: si no,
dos cajeros cuadrarían contra el mismo dinero y ninguno sabría de quién es la
diferencia. La diferencia del corte se guarda tal cual — si falta dinero, el
sistema lo dice, no lo tapa.

**Anular no borra.** La venta queda marcada, la mercancía vuelve al inventario
con su propio movimiento y el crédito se le devuelve al cliente.

**La compra es la puerta por la que entra la mercancía.** Al recibirla ocurren
tres cosas en la misma transacción: el stock entra, el costo del producto se
actualiza y la deuda se le carga al proveedor si fue a crédito. Una compra a
medias dejaría existencia sin costo o deuda sin mercancía.

**Comprar por caja calcula el costo por pieza.** Una caja de 24 a 240 significa
que la pieza cuesta 10, y ese es el número que alimenta el margen de venta —
no el 240.

**Subir el costo sube el precio, pero solo donde corresponde.** Las listas que
trabajan por margen se recalculan solas al recibir una compra. Un precio que
alguien capturó a mano no se toca.

**No se puede anular una compra cuya mercancía ya se vendió.** Devolver algo
que ya salió dejaría el inventario en negativo sin explicación; el sistema pide
ajustar en lugar de anular.

**El saldo del cliente lo mueve una sola pieza.** Una venta a crédito lo sube,
un abono lo baja, y el estado de cuenta se arma de esas dos fuentes con el saldo
corrido — sin una tabla de movimientos que duplicaría datos que ya viven en la
venta. De una venta mixta solo se carga la parte que quedó a deber.

**Un abono en efectivo entra al cajón.** Si hay turno abierto, cuenta en el
arqueo igual que una venta de contado, porque el dinero está físicamente ahí.
Uno por transferencia no.

**No se baja el límite de crédito por debajo del saldo**, ni se desactiva a un
cliente o proveedor que debe: dejarlo fuera de vista es como perder la cuenta.

**Todo el dinero pasa por `Treasury`.** Actualizar el saldo de una cuenta y
escribir su movimiento ocurren juntos, con la fila bloqueada. Las formas de pago
apuntan a una cuenta — el efectivo a la caja, la tarjeta al banco — y por eso una
venta, una compra o un abono aterrizan solos donde corresponde. El crédito no
aterriza en ninguna: ese dinero todavía no existe.

**El saldo se comprueba con la fila recién leída, no con el modelo que llegó.**
Entre que se abre una pantalla y se confirma un traslado puede colarse otro
movimiento; validar contra un saldo viejo dejaría la cuenta en negativo.

**Los traslados no son ingreso ni gasto.** El dinero solo cambia de bolsillo, así
que quedan fuera del desglose de "en qué entró y en qué salió". Entre monedas
distintas, el monto que llega se calcula y se guarda con su tipo de cambio.

**Un gasto puede no tocar ninguna cuenta.** Un negocio que solo quiere anotar en
qué se le va el dinero no está obligado a llevar tesorería.

**Un precio en cero significa "sin precio", no "gratis".** No se guarda, para
que la caja no resuelva un cero como precio válido y deje regalar el producto.
La pantalla del producto avisa cuando falta el precio de mostrador.

**Las cabeceras de pantalla van dentro del componente Livewire.** Si vivieran
en el layout quedarían fuera de su elemento raíz y los botones con `wire:click`
no harían nada. Es un error que no se ve en las pruebas de componente, porque
esas llaman los métodos directamente.
