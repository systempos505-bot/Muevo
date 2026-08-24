<?php

namespace App\Livewire;

use Livewire\Component;

/**
 * Base de las pantallas del sistema.
 *
 * Concentra los avisos en pantalla para que cada componente no repita lo
 * mismo. El menu vive en App\Support\Navigation, porque el layout tambien
 * lo necesita y ahi no hay una instancia del componente.
 */
abstract class Page extends Component
{
    /** Aviso breve en pantalla. */
    protected function notify(string $message, string $kind = 'success'): void
    {
        $this->dispatch('notify', message: $message, kind: $kind);
    }
}
