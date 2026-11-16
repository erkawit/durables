<?php
/**
 * DatabaseSessionHandler for Vercel Serverless / Multi-instance environments.
 * Stores PHP session data in the `sessions` table (PostgreSQL or MySQL).
 */

class DatabaseSessionHandler implements SessionHandlerInterface {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function open($savePath, $sessionName): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read($id): string|false {
        try {
            $stmt = $this->pdo->prepare("SELECT data FROM sessions WHERE id = ?");
            $stmt->execute([$id]);
            $data = $stmt->fetchColumn();
            return ($data !== false && $data !== null) ? $data : '';
        } catch (Exception $e) {
            return '';
        }
    }

    public function write($id, $data): bool {
        global $db_driver;
        try {
            if ($db_driver === 'pgsql') {
                $stmt = $this->pdo->prepare("
                    INSERT INTO sessions (id, data, last_activity) VALUES (?, ?, CURRENT_TIMESTAMP)
                    ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, last_activity = CURRENT_TIMESTAMP
                ");
            } else {
                $stmt = $this->pdo->prepare("
                    INSERT INTO sessions (id, data, last_activity) VALUES (?, ?, CURRENT_TIMESTAMP)
                    ON DUPLICATE KEY UPDATE data = VALUES(data), last_activity = CURRENT_TIMESTAMP
                ");
            }
            return $stmt->execute([$id, $data]);
        } catch (Exception $e) {
            return false;
        }
    }

    public function destroy($id): bool {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE id = ?");
            return $stmt->execute([$id]);
        } catch (Exception $e) {
            return false;
        }
    }

    public function gc($maxlifetime): int|false {
        try {
            $cutoff = date('Y-m-d H:i:s', time() - $maxlifetime);
            $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE last_activity < ?");
            $stmt->execute([$cutoff]);
            return $stmt->rowCount();
        } catch (Exception $e) {
            return false;
        }
    }
}
