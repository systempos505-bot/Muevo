import { useEffect } from 'react';
import { useAuthStore } from './store/auth';
import { Login } from './components/Login';
import { Dashboard } from './components/Dashboard';

function App() {
  const { token, loadFromStorage } = useAuthStore();

  useEffect(() => {
    loadFromStorage();
  }, []);

  return token ? <Dashboard /> : <Login />;
}

export default App;
