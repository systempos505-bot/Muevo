/* Core multi-tenant types */

export interface Tenant {
  id: string;
  name: string;
  email: string;
  type: 'retail' | 'restaurant';
  plan: 'basic' | 'pro' | 'enterprise';
  status: 'trial' | 'active' | 'suspended';
  created_at: Date;
  updated_at: Date;
}

export interface Branch {
  id: string;
  tenant_id: string;
  name: string;
  address: string;
  city: string;
  country: string;
  phone: string;
  status: 'active' | 'inactive';
  created_at: Date;
  updated_at: Date;
}

export interface Terminal {
  id: string;
  tenant_id: string;
  branch_id: string;
  name: string;
  serial_number: string;
  status: 'active' | 'inactive';
  created_at: Date;
  updated_at: Date;
}

export interface User {
  id: string;
  tenant_id: string;
  branch_id?: string;
  email: string;
  name: string;
  role: 'owner' | 'manager' | 'cashier' | 'warehouse';
  pin?: string;
  permissions: Record<string, boolean>;
  status: 'active' | 'inactive';
  created_at: Date;
  updated_at: Date;
}

export interface Product {
  id: string;
  tenant_id: string;
  sku: string;
  name: string;
  description?: string;
  category: string;
  brand?: string;
  unit: 'piece' | 'kg' | 'liter' | 'meter';
  cost: number;
  price: number;
  tax_rate: number;
  barcode?: string;
  image_url?: string;
  status: 'active' | 'inactive';
  created_at: Date;
  updated_at: Date;
}

export interface Inventory {
  id: string;
  tenant_id: string;
  branch_id: string;
  product_id: string;
  quantity: number;
  min_quantity: number;
  updated_at: Date;
}

export interface Customer {
  id: string;
  tenant_id: string;
  name: string;
  email?: string;
  phone?: string;
  address?: string;
  tax_id?: string;
  credit_limit?: number;
  credit_balance: number;
  created_at: Date;
  updated_at: Date;
}

export interface Sale {
  id: string;
  tenant_id: string;
  branch_id: string;
  terminal_id: string;
  cashier_id: string;
  customer_id?: string;
  shift_id: string;
  status: 'completed' | 'cancelled' | 'refunded';
  subtotal: number;
  tax: number;
  discount: number;
  total: number;
  payment_method: string;
  reference_number: string;
  notes?: string;
  synced: boolean;
  created_at: Date;
  updated_at: Date;
}

export interface SaleItem {
  id: string;
  sale_id: string;
  product_id: string;
  quantity: number;
  unit_price: number;
  discount: number;
  tax: number;
  total: number;
}

export interface Shift {
  id: string;
  tenant_id: string;
  branch_id: string;
  terminal_id: string;
  cashier_id: string;
  start_time: Date;
  end_time?: Date;
  opening_balance: number;
  closing_balance?: number;
  calculated_balance?: number;
  difference?: number;
  status: 'open' | 'closed';
  created_at: Date;
  updated_at: Date;
}

export interface OfflineQueue {
  id: string;
  tenant_id: string;
  terminal_id: string;
  operation: 'sale' | 'inventory' | 'customer' | 'return';
  entity_id: string;
  payload: Record<string, any>;
  status: 'pending' | 'synced' | 'failed';
  attempts: number;
  error?: string;
  created_at: Date;
  synced_at?: Date;
}
