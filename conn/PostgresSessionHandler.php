<?php

class PostgresSessionHandler implements SessionHandlerInterface
{
    private $conn;
    private $lockedSessionId;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        if ($this->lockedSessionId !== null) {
            $stmt = $this->conn->prepare('SELECT pg_advisory_unlock(hashtext(:session_id)::bigint)');
            $stmt->execute(['session_id' => $this->lockedSessionId]);
            $this->lockedSessionId = null;
        }
        return true;
    }

    public function read(string $sessionId): string|false
    {
        $lock = $this->conn->prepare('SELECT pg_advisory_lock(hashtext(:session_id)::bigint)');
        $lock->execute(['session_id' => $sessionId]);
        $this->lockedSessionId = $sessionId;

        $stmt = $this->conn->prepare(
            'SELECT session_data FROM app_sessions WHERE session_id = :session_id AND expires_at > now()'
        );
        $stmt->execute(['session_id' => $sessionId]);
        $data = $stmt->fetchColumn();
        return $data === false ? '' : $data;
    }

    public function write(string $sessionId, string $data): bool
    {
        $expiresAt = time() + (int) ini_get('session.gc_maxlifetime');
        $stmt = $this->conn->prepare(
            'INSERT INTO app_sessions (session_id, session_data, expires_at) '
            . 'VALUES (:session_id, :session_data, to_timestamp(:expires_at)) '
            . 'ON CONFLICT (session_id) DO UPDATE SET '
            . 'session_data = EXCLUDED.session_data, expires_at = EXCLUDED.expires_at'
        );
        return $stmt->execute([
            'session_id' => $sessionId,
            'session_data' => $data,
            'expires_at' => $expiresAt,
        ]);
    }

    public function destroy(string $sessionId): bool
    {
        $stmt = $this->conn->prepare('DELETE FROM app_sessions WHERE session_id = :session_id');
        return $stmt->execute(['session_id' => $sessionId]);
    }

    public function gc(int $maxLifetime): int|false
    {
        $stmt = $this->conn->prepare('DELETE FROM app_sessions WHERE expires_at <= now()');
        return $stmt->execute() ? $stmt->rowCount() : false;
    }
}
