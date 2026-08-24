<?php

namespace App\Support;

use Closure;

/**
 * Guarda cual es la empresa activa durante la peticion.
 *
 * En MySQL no existe el aislamiento por fila que da PostgreSQL, asi que
 * la separacion entre empresas se sostiene aqui y en el scope global de
 * los modelos. Es la pieza mas delicada del sistema: si esto falla, un
 * negocio ve los datos de otro.
 *
 * Por eso la regla es que nada la fije "a mano" en medio de un flujo
 * normal: la establece el middleware a partir del usuario autenticado.
 */
class Tenancy
{
    protected static ?string $tenantId = null;

    /** Permite saltarse el filtro en seeders, migraciones y el panel de plataforma. */
    protected static bool $disabled = false;

    public static function set(?string $tenantId): void
    {
        static::$tenantId = $tenantId;
    }

    public static function id(): ?string
    {
        return static::$tenantId;
    }

    public static function check(): bool
    {
        return static::$tenantId !== null;
    }

    public static function forget(): void
    {
        static::$tenantId = null;
    }

    public static function disabled(): bool
    {
        return static::$disabled;
    }

    /**
     * Corre una funcion sin el filtro de empresa.
     *
     * Solo para el panel de superusuario, seeders y trabajos en segundo
     * plano que operan sobre varias empresas. El estado se restaura aunque
     * la funcion lance una excepcion, para que un error no deje el filtro
     * apagado el resto de la peticion.
     */
    public static function withoutScope(Closure $callback): mixed
    {
        $previous = static::$disabled;
        static::$disabled = true;

        try {
            return $callback();
        } finally {
            static::$disabled = $previous;
        }
    }

    /**
     * Corre una funcion como si fuera otra empresa.
     * Restaura la anterior al terminar, pase lo que pase.
     */
    public static function forTenant(string $tenantId, Closure $callback): mixed
    {
        $previous = static::$tenantId;
        static::$tenantId = $tenantId;

        try {
            return $callback();
        } finally {
            static::$tenantId = $previous;
        }
    }
}
