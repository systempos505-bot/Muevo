# Muevo POS

Sistema de facturación y punto de venta SaaS, multiempresa y multisucursal.
Pensado para farmacias, tiendas de ropa, zapaterías, ferreterías y supermercados.

**Stack:** Laravel 13 · Livewire · Tailwind 4 · MySQL

---

## Estado actual

| Módulo | Estado |
|---|---|
| Cimientos (empresas, sucursales, cajas, roles, usuarios) | ✅ Listo |
| Configuración (monedas, impuestos, numeración) | ✅ Esquema listo |
| Catálogo de productos | ✅ Listo |
| Categorías, marcas y unidades | ✅ Listo |
| Listas de precios | ✅ Listo |
| Inventario: existencias, kardex y ajustes | ✅ Listo |
| Inventario: lotes, vencimientos y series | ✅ Esquema listo |
| Clientes y proveedores | ✅ Esquema listo |
| Punto de venta | ⬜ Pendiente |
| Compras | ⬜ Pendiente |
| Caja y turnos | ⬜ Pendiente |
| Cuentas de pago y gastos | ⬜ Pendiente |
| Promociones | ⬜ Pendiente |
| Reportes | ⬜ Pendiente |
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
  Models/             Modelos Eloquent
    Concerns/
      BelongsToTenant.php   Aísla los datos por empresa
  Services/
    Pricing.php             Impuesto, margen y precio sugerido
    InventoryManager.php    Único punto por el que se mueve el stock
    TenantProvisioner.php   Deja lista una empresa nueva
  Support/
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
  Feature/            Registro, sesión, productos, catálogo, inventario
                      y aislamiento entre empresas
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

**Las cabeceras de pantalla van dentro del componente Livewire.** Si vivieran
en el layout quedarían fuera de su elemento raíz y los botones con `wire:click`
no harían nada. Es un error que no se ve en las pruebas de componente, porque
esas llaman los métodos directamente.
