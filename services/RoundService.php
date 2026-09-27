<?php
declare(strict_types=1);

final class RoundService
{
    public static function isRoundComplete(int $round_id, PDO $pdo): bool
    {
        if ($round_id <= 0) {
            return false;
        }

        $hasDeletedAt = self::tableHasColumn($pdo, 'table_match', 'deleted_at');
        $sql = "SELECT COUNT(*) FROM table_match WHERE round_id = :round_id AND status != 'completed'";
        if ($hasDeletedAt) {
            $sql .= " AND deleted_at IS NULL";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':round_id' => $round_id]);
        return ((int)$stmt->fetchColumn() === 0);
    }

    public static function lockRound(int $round_id, PDO $pdo): void
    {
        self::setLockState($round_id, 1, $pdo);
    }

    public static function unlockRound(int $round_id, PDO $pdo): void
    {
        self::setLockState($round_id, 0, $pdo);
    }

    private static function setLockState(int $round_id, int $state, PDO $pdo): void
    {
        if ($round_id <= 0) {
            throw new InvalidArgumentException('round_id must be a positive integer.');
        }
        if (!self::tableHasColumn($pdo, 'table_round', 'is_locked')) {
            throw new RuntimeException('table_round.is_locked column is required.');
        }

        $stmt = $pdo->prepare('UPDATE table_round SET is_locked = :state WHERE id = :round_id');
        $stmt->execute([
            ':state' => $state,
            ':round_id' => $round_id,
        ]);
    }

    private static function tableHasColumn(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = strtolower($table . '.' . $column);
        if (array_key_exists($key, $cache)) {
            return (bool)$cache[$key];
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
        );
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);

        $exists = ((int)$stmt->fetchColumn() > 0);
        $cache[$key] = $exists;
        return $exists;
    }
}

