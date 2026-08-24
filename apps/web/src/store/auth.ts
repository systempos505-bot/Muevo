import { create } from 'zustand';
import axios from 'axios';

interface User {
  id: string;
  name: string;
  email: string;
  role: string;
}

interface Tenant {
  id: string;
  name: string;
  type: string;
  plan: string;
}

interface AuthState {
  token: string | null;
  user: User | null;
  tenant: Tenant | null;
  isLoading: boolean;
  error: string | null;

  register: (name: string, email: string, password: string, type: string) => Promise<void>;
  login: (email: string, password: string) => Promise<void>;
  pinLogin: (pin: string, terminalId: string) => Promise<void>;
  logout: () => void;
  loadFromStorage: () => void;
}

export const useAuthStore = create<AuthState>((set) => ({
  token: null,
  user: null,
  tenant: null,
  isLoading: false,
  error: null,

  register: async (name, email, password, type) => {
    set({ isLoading: true, error: null });
    try {
      const response = await axios.post('/api/auth/register', {
        name,
        email,
        password,
        type,
      });

      const { token, user, tenant } = response.data;
      localStorage.setItem('token', token);
      localStorage.setItem('tenant', JSON.stringify(tenant));

      set({ token, user, tenant, isLoading: false });
    } catch (error: any) {
      set({ error: error.response?.data?.error || 'Registration failed', isLoading: false });
    }
  },

  login: async (email, password) => {
    set({ isLoading: true, error: null });
    try {
      const response = await axios.post('/api/auth/login', { email, password });

      const { token, user, tenant } = response.data;
      localStorage.setItem('token', token);
      localStorage.setItem('tenant', JSON.stringify(tenant));

      set({ token, user, tenant, isLoading: false });
    } catch (error: any) {
      set({ error: error.response?.data?.error || 'Login failed', isLoading: false });
    }
  },

  pinLogin: async (pin, terminalId) => {
    set({ isLoading: true, error: null });
    try {
      const response = await axios.post('/api/auth/pin-login', { pin, terminalId });

      const { token, user } = response.data;
      localStorage.setItem('token', token);

      set({ token, user, isLoading: false });
    } catch (error: any) {
      set({ error: error.response?.data?.error || 'PIN login failed', isLoading: false });
    }
  },

  logout: () => {
    localStorage.removeItem('token');
    localStorage.removeItem('tenant');
    set({ token: null, user: null, tenant: null });
  },

  loadFromStorage: () => {
    const token = localStorage.getItem('token');
    const tenant = localStorage.getItem('tenant');

    if (token && tenant) {
      set({ token, tenant: JSON.parse(tenant) });
    }
  },
}));
