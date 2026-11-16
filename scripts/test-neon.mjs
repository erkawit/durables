import { spawn } from 'child_process';
import path from 'path';
import {
  activeSuppliesQuery,
  createRealtimeService,
  pool,
} from '../src/neon-service.mjs';

const rootDir = path.resolve(import.meta.dirname, '..');
const port = 3011;

async function waitForServer(url, timeoutMs = 10000) {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    try {
      const res = await fetch(url);
      if (res.ok) return res;
    } catch {
      // retry
    }
    await new Promise((r) => setTimeout(r, 200));
  }
  throw new Error(`Timed out waiting for ${url}`);
}

async function main() {
  const serverProc = spawn(process.execPath, ['src/server.mjs'], {
    cwd: rootDir,
    env: { ...process.env, PORT: String(port) },
    stdio: ['ignore', 'pipe', 'pipe'],
  });

  serverProc.stdout.on('data', (d) => process.stdout.write(`[server] ${d}`));
  serverProc.stderr.on('data', (d) => process.stderr.write(`[server:err] ${d}`));

  const realtime = createRealtimeService();

  try {
    // 1. Test HTTP /health (Neon Postgres via pg.Pool + DATABASE_URL)
    const healthRes = await waitForServer(`http://127.0.0.1:${port}/health`);
    const healthJson = await healthRes.json();
    console.log('1. /health response:', JSON.stringify(healthJson));
    if (healthJson.status !== 'ok' || !healthJson.db || healthJson.db.supplies_count <= 0) {
      throw new Error('Postgres health check failed');
    }

    // 2. Test HTTP /api/realtime/supplies (Sealing live query with NEON_REALTIME_SECRET)
    const sealRes = await fetch(`http://127.0.0.1:${port}/api/realtime/supplies`);
    const sealJson = await sealRes.json();
    console.log(
      '2. /api/realtime/supplies response:',
      JSON.stringify({
        status: sealJson.status,
        db: sealJson.db,
        url: sealJson.url,
        hasCapability: Boolean(sealJson.sealed && sealJson.sealed.capability),
        queryFingerprint: sealJson.sealed?.queryFingerprint,
      })
    );
    if (sealJson.status !== 'ok' || !sealJson.sealed?.capability) {
      throw new Error('Realtime seal endpoint failed');
    }

    // 3. Test Neon Realtime WebSocket live subscription + Postgres write txid
    console.log('3. Subscribing to live supplies query via Neon Realtime...');
    const sub = await realtime.subscribe(activeSuppliesQuery());
    sub.onStateChange((state) => {
      console.log('   Realtime state:', JSON.stringify(state));
    });

    const initialRows = await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('Timed out waiting for initial Realtime snapshot')), 15000);
      const check = () => {
        const snap = sub.getSnapshot();
        if (snap && Array.isArray(snap.data) && snap.data.length > 0) {
          clearTimeout(timer);
          resolve(snap.data);
        }
      };
      sub.onChange(() => check());
      sub.onStateChange(() => check());
      check();
    });
    console.log(`   Received initial Realtime snapshot: ${initialRows.length} active supplies.`);

    // Trigger a real update & restore in Neon Postgres and await its txid via Realtime
    const target = initialRows[0];
    const origQty = target.qty;
    const tempQty = origQty + 1;

    const upd = await pool.query(
      'UPDATE supplies SET qty = $1 WHERE id = $2 RETURNING id, qty, pg_current_xact_id()::text AS txid',
      [tempQty, target.id]
    );
    const txid1 = upd.rows[0].txid;
    await sub.awaitTxId(txid1, 10000);
    const afterUpdate = sub.getSnapshot().data.find((r) => r.id === target.id);
    console.log(`   Realtime synced UPDATE txid=${txid1}: supply id=${target.id} qty ${origQty} -> ${afterUpdate?.qty}`);
    if (afterUpdate?.qty !== tempQty) {
      throw new Error(`Expected qty ${tempQty}, got ${afterUpdate?.qty}`);
    }

    // Restore original qty
    const restore = await pool.query(
      'UPDATE supplies SET qty = $1 WHERE id = $2 RETURNING id, qty, pg_current_xact_id()::text AS txid',
      [origQty, target.id]
    );
    const txid2 = restore.rows[0].txid;
    await sub.awaitTxId(txid2, 10000);
    const afterRestore = sub.getSnapshot().data.find((r) => r.id === target.id);
    console.log(`   Realtime synced RESTORE txid=${txid2}: supply id=${target.id} qty -> ${afterRestore?.qty}`);

    sub.unsubscribe?.();
    console.log('ALL END-TO-END TESTS PASSED!');
  } finally {
    realtime.close();
    await pool.end();
    serverProc.kill();
  }
}

main().catch((err) => {
  console.error('TEST FAILED:', err);
  process.exit(1);
});
