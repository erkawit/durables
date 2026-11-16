import { execFileSync } from 'child_process';
import fs from 'fs';
import path from 'path';

const rootDir = path.resolve(import.meta.dirname, '..');

function run(cmd, args) {
  execFileSync(cmd, args, { cwd: rootDir, stdio: 'inherit' });
}

console.log('1. Checking Node.js modules syntax...');
for (const file of ['src/neon-service.mjs', 'src/server.mjs', 'scripts/test-neon.mjs']) {
  const fullPath = path.join(rootDir, file);
  if (fs.existsSync(fullPath)) {
    run(process.execPath, ['--check', fullPath]);
  }
}

console.log('2. Verifying Neon packages & neon.ts...');
const neonTs = fs.readFileSync(path.join(rootDir, 'neon.ts'), 'utf8');
if (!neonTs.includes('defineConfig') || !neonTs.includes('realtime')) {
  throw new Error('neon.ts is missing expected defineConfig / realtime configuration');
}
await import('@neon/config/v1');
await import('@neon/realtime/server');
await import('pg');

console.log('3. Checking PHP syntax (if php CLI is available)...');
try {
  run('php', ['-l', 'config.php']);
  run('php', ['-l', 'session_handler.php']);
} catch (err) {
  if (err.code === 'ENOENT') {
    console.log('PHP CLI not found in PATH, skipping php -l.');
  } else {
    throw err;
  }
}

console.log('Build check passed!');
