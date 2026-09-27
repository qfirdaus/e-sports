<?php
declare(strict_types=1);

require_once __DIR__ . '/LeagueStandingService.php';

final class TournamentService
{
    public static function handleAfterMatchSave(int $match_id, PDO $pdo): void
    {
        if ($match_id <= 0) {
            throw new InvalidArgumentException('match_id must be a positive integer.');
        }

        $hasMatchDeletedAt = self::tableHasColumn($pdo, 'table_match', 'deleted_at');
        $sql = "
            SELECT m.id, m.round_id, r.round_type
            FROM table_match m
            INNER JOIN table_round r ON r.id = m.round_id
            WHERE m.id = :match_id
        ";
        if ($hasMatchDeletedAt) {
            $sql .= " AND m.deleted_at IS NULL";
        }
        $sql .= " LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':match_id' => $match_id]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$match) {
            return;
        }

        $roundId = (int)$match['round_id'];
        $roundType = strtolower((string)($match['round_type'] ?? ''));

        if ($roundType === 'league') {
            LeagueStandingService::generate($roundId, $pdo);
            return;
        }

        if ($roundType === 'knockout') {
            if (!class_exists('KnockoutAdvanceService') || !method_exists('KnockoutAdvanceService', 'advance')) {
                // Keep result saving successful even when auto-advance service is not yet implemented.
                error_log('[TournamentService] KnockoutAdvanceService not available; skip auto-advance for round_id=' . $roundId);
                return;
            }
            KnockoutAdvanceService::advance($roundId, $pdo);
        }
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
