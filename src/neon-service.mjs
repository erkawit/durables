import dotenv from 'dotenv';
import fs from 'fs';
import path from 'path';
import { Pool } from 'pg';
import { createRealtime, rawSql } from '@neon/realtime/server';

const rootDir = path.resolve(import.meta.dirname, '..');
const envLocalPath = path.join(rootDir, '.env.local');
const envPath = path.join(rootDir, '.env');
if (fs.existsSync(envLocalPath)) {
  dotenv.config({ path: envLocalPath, quiet: true });
} else if (fs.existsSync(envPath)) {
  dotenv.config({ path: envPath, quiet: true });
}

const databaseUrl = process.env.DATABASE_URL;
const realtimeUrl = process.env.NEON_REALTIME_URL;
const realtimeSecret = process.env.NEON_REALTIME_SECRET;

if (!databaseUrl) {
  throw new Error('Missing DATABASE_URL environment variable.');
}

const dbName = new URL(databaseUrl).pathname.replace(/^\//, '') || 'neondb';

export const pool = new Pool({
  connectionString: databaseUrl,
  max: 10,
});

export function createRealtimeService() {
  if (!realtimeUrl || !realtimeSecret) {
    throw new Error('Missing NEON_REALTIME_URL or NEON_REALTIME_SECRET environment variable.');
  }
  return createRealtime({
    url: realtimeUrl,
    secret: realtimeSecret,
    db: dbName,
  });
}

export function activeSuppliesQuery() {
  return rawSql(
    'SELECT id, code, name, qty, unit, status FROM supplies WHERE status = $1 ORDER BY id',
    ['active']
  );
}

export async function getDbHealth() {
  const result = await pool.query(
    'SELECT current_database() AS database, current_user AS user, (SELECT COUNT(*)::int FROM supplies) AS supplies_count, (SELECT COUNT(*)::int FROM users) AS users_count'
  );
  return result.rows[0];
}

export async function sealSuppliesQuery() {
  const realtime = createRealtimeService();
  try {
    const sealed = await realtime.seal({ query: activeSuppliesQuery() });
    return {
      url: realtimeUrl,
      db: dbName,
      sealed,
    };
  } finally {
    realtime.close();
  }
}
