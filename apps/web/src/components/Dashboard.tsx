import { useAuthStore } from '../store/auth';

export function Dashboard() {
  const { user, tenant, logout } = useAuthStore();

  return (
    <div className="min-h-screen bg-gray-100">
      {/* Header */}
      <header className="bg-white shadow">
        <div className="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
          <div>
            <h1 className="text-2xl font-bold text-blue-600">Muevo POS</h1>
            <p className="text-sm text-gray-600">{tenant?.name}</p>
          </div>
          <div className="flex items-center gap-4">
            <div className="text-right">
              <p className="font-medium text-gray-900">{user?.name}</p>
              <p className="text-xs text-gray-500">{user?.role}</p>
            </div>
            <button
              onClick={logout}
              className="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg transition-colors"
            >
              Salir
            </button>
          </div>
        </div>
      </header>

      {/* Main content */}
      <main className="max-w-7xl mx-auto px-4 py-8">
        {/* Grid layout - responsive */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
          {/* Card: Venta */}
          <div className="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer">
            <div className="text-4xl mb-2">🛒</div>
            <h3 className="font-semibold text-gray-900 text-lg">Nueva Venta</h3>
            <p className="text-sm text-gray-600 mt-1">Registrar venta</p>
          </div>

          {/* Card: Productos */}
          <div className="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer">
            <div className="text-4xl mb-2">📦</div>
            <h3 className="font-semibold text-gray-900 text-lg">Productos</h3>
            <p className="text-sm text-gray-600 mt-1">Gestionar catálogo</p>
          </div>

          {/* Card: Inventario */}
          <div className="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer">
            <div className="text-4xl mb-2">📊</div>
            <h3 className="font-semibold text-gray-900 text-lg">Inventario</h3>
            <p className="text-sm text-gray-600 mt-1">Stock y movimientos</p>
          </div>

          {/* Card: Reportes */}
          <div className="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer">
            <div className="text-4xl mb-2">📈</div>
            <h3 className="font-semibold text-gray-900 text-lg">Reportes</h3>
            <p className="text-sm text-gray-600 mt-1">Análisis y datos</p>
          </div>
        </div>

        {/* Stats section */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          <div className="bg-white rounded-lg shadow p-6">
            <p className="text-sm text-gray-600 mb-1">Ventas hoy</p>
            <p className="text-3xl font-bold text-gray-900">$0.00</p>
          </div>

          <div className="bg-white rounded-lg shadow p-6">
            <p className="text-sm text-gray-600 mb-1">Transacciones</p>
            <p className="text-3xl font-bold text-gray-900">0</p>
          </div>

          <div className="bg-white rounded-lg shadow p-6">
            <p className="text-sm text-gray-600 mb-1">Ticket promedio</p>
            <p className="text-3xl font-bold text-gray-900">$0.00</p>
          </div>
        </div>
      </main>
    </div>
  );
}
