import http from 'http';
import { getDbHealth, sealSuppliesQuery, pool } from './neon-service.mjs';

const port = Number(process.env.PORT || 3001);

const server = http.createServer(async (req, res) => {
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.setHeader('Access-Control-Allow-Origin', '*');

  try {
    if (req.url === '/health') {
      const db = await getDbHealth();
      res.writeHead(200);
      res.end(JSON.stringify({ status: 'ok', service: 'durables-neon-service', db }));
      return;
    }

    if (req.url === '/api/realtime/supplies') {
      const payload = await sealSuppliesQuery();
      res.writeHead(200);
      res.end(JSON.stringify({ status: 'ok', ...payload }));
      return;
    }

    res.writeHead(404);
    res.end(JSON.stringify({ status: 'error', message: 'Not found' }));
  } catch (err) {
    res.writeHead(500);
    res.end(JSON.stringify({ status: 'error', message: err.message }));
  }
});

server.listen(port, '127.0.0.1', () => {
  console.log(`Durables Neon service listening on http://127.0.0.1:${port}`);
});

async function shutdown() {
  server.close();
  await pool.end();
  process.exit(0);
}

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
