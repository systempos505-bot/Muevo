import Fastify from 'fastify';
import jwt from 'jwt-simple';
import pg from 'pg';
import { v4 as uuid } from 'uuid';

const { Pool } = pg;

const server = Fastify({ logger: true });
const JWT_SECRET = process.env.JWT_SECRET || 'dev-secret-change-in-prod';

// Database connection
const pool = new Pool({
  connectionString: process.env.DATABASE_URL || 'postgres://postgres:password@localhost:5432/muevo',
});

// ==================== TYPES ====================
interface AuthPayload {
  userId: string;
  tenantId: string;
  email: string;
  role: string;
}

interface LoginRequest {
  email: string;
  password: string;
}

interface PinLoginRequest {
  pin: string;
  terminalId: string;
}

// ==================== MIDDLEWARE ====================
server.decorate('authenticate', async (request: any, reply: any) => {
  try {
    const token = request.headers.authorization?.replace('Bearer ', '');
    if (!token) throw new Error('No token');
    const decoded = jwt.decode(token, JWT_SECRET) as AuthPayload;
    request.user = decoded;
  } catch (err) {
    reply.status(401).send({ error: 'Unauthorized' });
  }
});

// ==================== ROUTES ====================

// Health check
server.get('/health', async () => {
  return { status: 'ok' };
});

// Tenant registration (signup)
server.post('/api/auth/register', async (request: any, reply: any) => {
  const { name, email, password, type } = request.body as any;

  try {
    const tenantId = uuid();
    const hashedPassword = Buffer.from(password).toString('base64'); // Replace with bcrypt in production

    const result = await pool.query(
      `INSERT INTO tenants (id, name, email, type, plan, status)
       VALUES ($1, $2, $3, $4, $5, $6)
       RETURNING id, name, email, plan, status`,
      [tenantId, name, email, type || 'retail', 'basic', 'trial']
    );

    // Create owner user
    const userId = uuid();
    await pool.query(
      `INSERT INTO users (id, tenant_id, email, name, role, password_hash, status)
       VALUES ($1, $2, $3, $4, $5, $6, $7)`,
      [userId, tenantId, email, name, 'owner', hashedPassword, 'active']
    );

    // Create default branch
    const branchId = uuid();
    await pool.query(
      `INSERT INTO branches (id, tenant_id, name, status)
       VALUES ($1, $2, $3, $4)`,
      [branchId, tenantId, 'Sucursal Principal', 'active']
    );

    // Create default terminal
    const terminalId = uuid();
    await pool.query(
      `INSERT INTO terminals (id, tenant_id, branch_id, name, serial_number, status)
       VALUES ($1, $2, $3, $4, $5, $6)`,
      [terminalId, tenantId, branchId, 'Caja 1', 'TERM-001', 'active']
    );

    const token = jwt.encode({ userId, tenantId, email, role: 'owner' }, JWT_SECRET);

    reply.status(201).send({
      token,
      tenant: result.rows[0],
      user: { id: userId, name, email, role: 'owner' },
      branch: { id: branchId, name: 'Sucursal Principal' },
      terminal: { id: terminalId, name: 'Caja 1' },
    });
  } catch (error: any) {
    reply.status(400).send({ error: error.message });
  }
});

// Email + password login
server.post('/api/auth/login', async (request: any, reply: any) => {
  const { email, password } = request.body as LoginRequest;

  try {
    const result = await pool.query(
      `SELECT u.id, u.tenant_id, u.name, u.email, u.role, u.password_hash, t.name as tenant_name
       FROM users u
       JOIN tenants t ON u.tenant_id = t.id
       WHERE u.email = $1 AND u.status = 'active'`,
      [email]
    );

    if (result.rows.length === 0) {
      reply.status(401).send({ error: 'Invalid credentials' });
      return;
    }

    const user = result.rows[0];
    const hashedPassword = Buffer.from(password).toString('base64');

    if (user.password_hash !== hashedPassword) {
      reply.status(401).send({ error: 'Invalid credentials' });
      return;
    }

    const token = jwt.encode(
      { userId: user.id, tenantId: user.tenant_id, email: user.email, role: user.role },
      JWT_SECRET
    );

    // Get default branch and terminal
    const branchResult = await pool.query(
      `SELECT id, name FROM branches WHERE tenant_id = $1 AND status = 'active' LIMIT 1`,
      [user.tenant_id]
    );

    const terminalResult = await pool.query(
      `SELECT id, name FROM terminals WHERE tenant_id = $1 AND status = 'active' LIMIT 1`,
      [user.tenant_id]
    );

    reply.send({
      token,
      user: {
        id: user.id,
        name: user.name,
        email: user.email,
        role: user.role,
      },
      tenant: {
        id: user.tenant_id,
        name: user.tenant_name,
      },
      branch: branchResult.rows[0] || null,
      terminal: terminalResult.rows[0] || null,
    });
  } catch (error: any) {
    reply.status(400).send({ error: error.message });
  }
});

// PIN login for cashiers (on terminal)
server.post('/api/auth/pin-login', async (request: any, reply: any) => {
  const { pin, terminalId } = request.body as PinLoginRequest;

  try {
    const result = await pool.query(
      `SELECT u.id, u.tenant_id, u.name, u.email, u.role, u.pin, t.branch_id
       FROM users u
       JOIN terminals t ON t.tenant_id = u.tenant_id
       WHERE u.pin = $1 AND t.id = $2 AND u.status = 'active'`,
      [pin, terminalId]
    );

    if (result.rows.length === 0) {
      reply.status(401).send({ error: 'Invalid PIN' });
      return;
    }

    const user = result.rows[0];
    const token = jwt.encode(
      { userId: user.id, tenantId: user.tenant_id, email: user.email, role: user.role },
      JWT_SECRET
    );

    reply.send({
      token,
      user: {
        id: user.id,
        name: user.name,
        role: user.role,
      },
      terminal: { id: terminalId },
      branch: { id: user.branch_id },
    });
  } catch (error: any) {
    reply.status(400).send({ error: error.message });
  }
});

// Get current user + session data
server.get('/api/auth/me', { onRequest: [server.authenticate] }, async (request: any) => {
  const user = request.user as AuthPayload;

  try {
    const userResult = await pool.query(
      `SELECT id, name, email, role FROM users WHERE id = $1`,
      [user.userId]
    );

    const tenantResult = await pool.query(
      `SELECT id, name, type, plan FROM tenants WHERE id = $1`,
      [user.tenantId]
    );

    reply.send({
      user: userResult.rows[0],
      tenant: tenantResult.rows[0],
    });
  } catch (error: any) {
    reply.status(400).send({ error: error.message });
  }
});

// Start server
const start = async () => {
  try {
    // Test DB connection
    const result = await pool.query('SELECT NOW()');
    server.log.info('Database connected', result.rows[0]);

    await server.listen({ port: 3000, host: '0.0.0.0' });
    server.log.info('Server running on http://localhost:3000');
  } catch (err) {
    server.log.error(err);
    process.exit(1);
  }
};

start();

export default server;
