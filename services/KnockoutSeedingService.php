<?php
declare(strict_types=1);

final class KnockoutSeedingService
{
    /**
     * Seed knockout participants from league standings based on seed_rule JSON.
     */
    public static function seed(int $round_id, PDO $pdo): void
    {
        if ($round_id <= 0) {
            throw new InvalidArgumentException('round_id must be a positive integer.');
        }

        $participantCol = self::resolveParticipantColumn($pdo);
        $round = self::fetchRound($pdo, $round_id);
        if ($round === null) {
            throw new RuntimeException('Round not found.');
        }

        $roundType = strtolower((string)($round['round_type'] ?? ''));
        if ($roundType !== 'knockout') {
            return;
        }

        $matches = self::fetchKnockoutMatches($pdo, $round_id);
        if (empty($matches)) {
            return;
        }

        $hasDeletedAt = self::tableHasColumn($pdo, 'table_match_participant', 'deleted_at');
        $existsSql = "SELECT COUNT(*) FROM table_match_participant WHERE match_id = :match_id";
        if ($hasDeletedAt) {
            $existsSql .= " AND deleted_at IS NULL";
        }
        $existsStmt = $pdo->prepare($existsSql);

        $existingPairSql = "SELECT COUNT(*) FROM table_match_participant WHERE match_id = :match_id AND {$participantCol} = :participant_id";
        if ($hasDeletedAt) {
            $existingPairSql .= " AND deleted_at IS NULL";
        }
        $existingPairStmt = $pdo->prepare($existingPairSql);

        $insertCols = ['match_id', $participantCol];
        $insertVals = [':match_id', ':participant_id'];

        $withCreatedAt = self::tableHasColumn($pdo, 'table_match_participant', 'created_at');
        if ($withCreatedAt) {
            $insertCols[] = 'created_at';
            $insertVals[] = ':created_at';
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO table_match_participant (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $insertVals) . ')'
        );

        $lookupParticipantByStandingStmt = null;

        foreach ($matches as $match) {
            $matchId = (int)$match['id'];
            if ($matchId <= 0) {
                continue;
            }

            // Only seed if match has no existing participant rows.
            $existsStmt->execute([':match_id' => $matchId]);
            if ((int)$existsStmt->fetchColumn() > 0) {
                continue;
            }

            $seedRule = self::decodeSeedRule((string)($match['seed_rule'] ?? ''));
            if ($seedRule === null) {
                continue;
            }

            $slots = self::extractSlots($seedRule);
            if (count($slots) < 2) {
                continue;
            }

            $participantsToInsert = [];
            foreach ($slots as $slot) {
                $groupCode = strtoupper(trim((string)($slot['group_code'] ?? '')));
                $position = isset($slot['position']) ? (int)$slot['position'] : 0;
                if ($groupCode === '' || $position <= 0) {
                    continue;
                }

                $leagueRoundId = self::findLeagueRoundIdByGroupCode($pdo, $groupCode, $round);
                if ($leagueRoundId <= 0) {
                    continue;
                }

                if ($lookupParticipantByStandingStmt === null) {
                    $lookupParticipantByStandingStmt = $pdo->prepare(
                        'SELECT participant_id FROM table_standing WHERE round_id = :round_id AND ranking = :ranking LIMIT 1'
                    );
                }

                $lookupParticipantByStandingStmt->execute([
                    ':round_id' => $leagueRoundId,
                    ':ranking' => $position,
                ]);

                $participantId = (int)$lookupParticipantByStandingStmt->fetchColumn();
                if ($participantId <= 0) {
                    continue;
                }

                $participantsToInsert[] = $participantId;
            }

            if (empty($participantsToInsert)) {
                continue;
            }

            // Deduplicate participants in the same match seeding payload.
            $participantsToInsert = array_values(array_unique($participantsToInsert));

            foreach ($participantsToInsert as $participantId) {
                $existingPairStmt->execute([
                    ':match_id' => $matchId,
                    ':participant_id' => $participantId,
                ]);
                if ((int)$existingPairStmt->fetchColumn() > 0) {
                    continue;
                }

                $params = [
                    ':match_id' => $matchId,
                    ':participant_id' => $participantId,
                ];
                if ($withCreatedAt) {
                    $params[':created_at'] = date('Y-m-d H:i:s');
                }

                $insertStmt->execute($params);
            }
        }
    }

    private static function fetchRound(PDO $pdo, int $round_id): ?array
    {
        $columns = ['id', 'round_type', 'group_code'];
        if (self::tableHasColumn($pdo, 'table_round', 'event_id')) {
            $columns[] = 'event_id';
        }

        $stmt = $pdo->prepare('SELECT ' . implode(', ', $columns) . ' FROM table_round WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $round_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    private static function fetchKnockoutMatches(PDO $pdo, int $round_id): array
    {
        $hasDeletedAt = self::tableHasColumn($pdo, 'table_match', 'deleted_at');
        $sql = 'SELECT id, seed_rule FROM table_match WHERE round_id = :round_id';
        if ($hasDeletedAt) {
            $sql .= ' AND deleted_at IS NULL';
        }
        $sql .= ' ORDER BY match_no ASC, id ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':round_id' => $round_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    private static function decodeSeedRule(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        return $decoded;
    }

    /**
     * Normalized slot format:
     * [ ['group_code' => 'A', 'position' => 1], ['group_code' => 'B', 'position' => 2] ]
     *
     * Supports either:
     * - {"slot1":{"group_code":"A","position":1},"slot2":{"group_code":"B","position":2}}
     * - {"slot1":{"group":"A","rank":1},"slot2":{"group":"B","rank":2}}
     * - {"slots":[{"group_code":"A","position":1},{"group_code":"B","position":2}]}
     */
    private static function extractSlots(array $seedRule): array
    {
        $candidateSlots = [];

        if (isset($seedRule['slots']) && is_array($seedRule['slots'])) {
            $candidateSlots = $seedRule['slots'];
        } else {
            foreach (['slot1', 'slot2'] as $key) {
                if (isset($seedRule[$key]) && is_array($seedRule[$key])) {
                    $candidateSlots[] = $seedRule[$key];
                }
            }
        }

        $out = [];
        foreach ($candidateSlots as $slot) {
            if (!is_array($slot)) {
                continue;
            }

            $groupCode = '';
            foreach (['group_code', 'group', 'groupCode'] as $gk) {
                if (isset($slot[$gk])) {
                    $groupCode = (string)$slot[$gk];
                    break;
                }
            }

            $position = 0;
            foreach (['position', 'ranking', 'rank', 'place'] as $pk) {
                if (isset($slot[$pk])) {
                    $position = (int)$slot[$pk];
                    break;
                }
            }

            $out[] = [
                'group_code' => strtoupper(trim($groupCode)),
                'position' => $position,
            ];
        }

        return $out;
    }

    private static function findLeagueRoundIdByGroupCode(PDO $pdo, string $groupCode, array $knockoutRound): int
    {
        $hasDeletedAt = self::tableHasColumn($pdo, 'table_round', 'deleted_at');
        $hasEventId = self::tableHasColumn($pdo, 'table_round', 'event_id') && array_key_exists('event_id', $knockoutRound);

        $sql = "SELECT id FROM table_round WHERE round_type = 'league' AND group_code = :group_code";
        if ($hasDeletedAt) {
            $sql .= ' AND deleted_at IS NULL';
        }

        $params = [':group_code' => $groupCode];

        if ($hasEventId) {
            $sql .= ' AND event_id = :event_id';
            $params[':event_id'] = (int)$knockoutRound['event_id'];
        }

        $sql .= ' ORDER BY id ASC LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $roundId = (int)$stmt->fetchColumn();
        return $roundId > 0 ? $roundId : 0;
    }

    private static function resolveParticipantColumn(PDO $pdo): string
    {
        $columns = self::getTableColumns($pdo, 'table_match_participant');
        if (isset($columns['participant_id'])) {
            return 'participant_id';
        }
        if (isset($columns['team_id'])) {
            return 'team_id';
        }
        if (isset($columns['pasukan_id'])) {
            return 'pasukan_id';
        }
        throw new RuntimeException('table_match_participant has no participant reference column.');
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

    /** @return array<string, true> */
    private static function getTableColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name'
        );
        $stmt->execute([':table_name' => $table]);

        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $col) {
                $out[strtolower((string)$col)] = true;
            }
        }
        return $out;
    }
}
