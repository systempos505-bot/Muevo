# Desplegar Muevo en Hostinger (hosting compartido)

El paquete `muevo-hostinger.zip` trae dos carpetas ya armadas y probadas:

```
muevo/         ->  va FUERA de la raiz web  (~/muevo)
public_html/   ->  va EN la raiz web        (~/public_html)
```

Estan separadas a proposito. Si el proyecto entero se sube a `public_html`,
cualquiera puede abrir `tudominio.com/.env` en el navegador y leer la
contrasena de tu base de datos.

El paquete ya incluye las dependencias (`vendor/`) y los estilos compilados
(`public_html/build/`), asi que **no necesitas Composer ni Node en el
servidor**.

---

## 1. Preparar el servidor (hPanel)

**PHP 8.3 o superior.** En hPanel: *Avanzado > Configuracion de PHP >
version*. El sistema no arranca con PHP 8.2 o menor.

**Crear la base de datos.** En hPanel: *Bases de datos > MySQL*. Anota los
cuatro datos; Hostinger le antepone tu usuario al nombre, algo como
`u123456789_muevo`.

---

## 2. Subir los archivos

Por *Administrador de archivos* de hPanel o por FTP:

1. Sube y descomprime el zip en tu carpeta personal (`~`, donde vive
   `public_html`).
2. Deja la carpeta `muevo/` al mismo nivel que `public_html`.
3. Mueve el **contenido** de la carpeta `public_html/` del paquete adentro
   de tu `public_html` real. Debe quedar `public_html/index.php`,
   `public_html/.htaccess` y `public_html/build/`.

Al final tienes:

```
~/muevo/          app, config, vendor, storage...
~/public_html/    index.php, .htaccess, build/, favicon.ico
```

> Si tu carpeta del proyecto no se llama `muevo` o no queda al lado de
> `public_html`, cambia la linea `$app_path` en `public_html/index.php`.

---

## 3. Configurar el `.env`

Renombra `~/muevo/.env.example` a `~/muevo/.env` y llena los valores
marcados `CAMBIAR`: los cuatro de la base de datos, tu dominio en
`APP_URL`, y los de correo.

Verifica que queden asi:

```
APP_ENV=production
APP_DEBUG=false
```

Con `APP_DEBUG=true` cualquier error le muestra al usuario tu codigo
fuente y tu configuracion.

---

## 4. Generar la llave y crear las tablas

Faltan dos comandos. Como se ejecutan depende de tu plan.

### Si tienes SSH (planes Premium y Business)

```bash
cd ~/muevo
php artisan key:generate --force
php artisan migrate --force
```

### Si no tienes SSH (plan Single)

Usa *Avanzado > Trabajos cron* de hPanel. Crea un cron, ponlo "una vez por
minuto", espera a que corra, y borralo. Uno a la vez:

```
php /home/TU_USUARIO/muevo/artisan key:generate --force
```

```
php /home/TU_USUARIO/muevo/artisan migrate --force
```

Reemplaza `TU_USUARIO` por el que aparece en tus rutas de hPanel.

**Comprueba que la llave quedo escrita** antes de seguir: abre
`~/muevo/.env` y mira que `APP_KEY=` ya no este vacio. Sin llave, nadie
puede iniciar sesion.

---

## 5. Permisos

Laravel escribe logs, cache y sesiones. Estas dos carpetas necesitan
permiso de escritura (755 suele bastar en Hostinger):

```
~/muevo/storage
~/muevo/bootstrap/cache
```

---

## 6. Crear tu negocio

Entra a `https://tudominio.com/registro`. Esa pantalla crea la empresa, su
sucursal, su caja, las series de folios y tu usuario administrador.

A partir de ahi ya puedes entrar por `/entrar`.

---

## 7. Opcional: acelerar

Una vez que todo funcione, estos comandos (por SSH o cron) hacen la app
mas rapida:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Si despues cambias el `.env`, vuelve a correr `php artisan config:cache` o
los cambios no se aplican.

---

## Antes de que lo use gente de verdad

**No hay recuperacion de contrasena todavia.** Si el dueno olvida su clave
queda afuera, y como tampoco hay panel de superusuario, solo se arregla
metiendo mano a la base de datos. Esto hay que construirlo antes de que el
sistema tenga usuarios reales.

**El sistema nunca se ha ejecutado contra MySQL.** Las 595 pruebas corren
sobre SQLite. El codigo se reviso y es compatible, pero la primera vez que
corra sobre MySQL de verdad va a ser en este servidor. Prueba el flujo
completo (crear producto, vender, cobrar, ver el reporte) antes de confiar
en el.

**Los planes y suscripciones no se cobran.** Las tablas existen pero no se
aplican en ningun lado: cualquiera que entre a `/registro` usa el sistema
completo, gratis y sin limite. Para venderlo como servicio falta esa parte
y el panel de superusuario.
