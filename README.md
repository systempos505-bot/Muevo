# Muevo POS - Sistema punto de venta SaaS

Sistema punto de venta completo, offline-first, multi-tenant y responsive.

## Features

- ✅ Multi-tenant con aislamiento de datos
- ✅ Offline-first (funciona sin internet)
- ✅ Responsive (celular, tablet, desktop)
- ✅ PWA instalable
- ✅ Autenticación JWT + PIN para caja
- ✅ Múltiples precios por producto

## Tech Stack

- **Frontend**: React + TypeScript + Vite + Tailwind CSS
- **Backend**: Node.js + Fastify + TypeScript
- **Database**: PostgreSQL con Row Level Security
- **State**: Zustand + Dexie (IndexedDB)
- **Deploy**: Docker

## Setup

### 1. Prerequisites

- Node.js 18+
- PostgreSQL 12+

### 2. Install dependencies

```bash
npm install
cd apps/web && npm install && cd ../..
```

### 3. Database setup

```bash
# Create database
createdb muevo

# Load schema
psql muevo < src/db/schema.sql
```

### 4. Environment

```bash
cp .env.example .env
# Edit .env with your database URL
```

### 5. Run

```bash
# Terminal 1: Start server (port 3000)
npm run dev:server

# Terminal 2: Start web (port 5173)
cd apps/web && npm run dev
```

Open http://localhost:5173

## Project Structure

```
muevo/
├── src/
│   ├── server/        # Fastify server
│   ├── db/            # Database schema & migrations
│   └── types/         # Shared TypeScript types
├── apps/web/
│   ├── src/
│   │   ├── components/
│   │   ├── store/
│   │   └── App.tsx
│   └── index.html
└── package.json
```

## Development

### Create a test account

On signup page:
- Name: Test Store
- Email: test@example.com
- Password: password123
- Type: Retail

Login with same credentials.

## Next Steps

- [x] Module 1: Foundation (auth, login, dashboard)
- [ ] Module 2: Products (with 10+ price tiers)
- [ ] Module 3: Inventory & Kardex
- [ ] Module 4: Offline-first engine
- [ ] Module 5: POS screen
- [ ] Module 6: Cash & shifts
- [ ] Module 7: Customers
- [ ] Module 8: Reports
- [ ] Module 9: SaaS panel

## License

MIT
