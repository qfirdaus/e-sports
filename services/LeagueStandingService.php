<?php
declare(strict_types=1);

final class LeagueStandingService
{
    /**
     * Generate league standings for one round.
     */
    public static function generate(int $round_id, PDO $pdo): void
    {
        if ($round_id <= 0) {
            throw new InvalidArgumentException('round_id must be a positive integer.');
        }

        $participantCol = self::resolveParticipantColumn($pdo);
        $standingColumns = self::getTableColumns($pdo, 'table_standing');

        $requiredStandingCols = [
            'round_id', 'participant_id', 'ranking', 'played', 'win', 'draw', 'lose',
            'goal_for', 'goal_against', 'goal_diff', 'points'
        ];
        foreach ($requiredStandingCols as $col) {
            if (!isset($standingColumns[$col])) {
                throw new RuntimeException("table_standing is missing required column: {$col}");
            }
        }

        $round = self::fetchRoundRow($pdo, $round_id);
        if ($round === null) {
            throw new RuntimeException('Round not found.');
        }

        $roundType = strtolower((string)($round['round_type'] ?? ''));
        if ($roundType !== 'league') {
            return;
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $deleteStmt = $pdo->prepare('DELETE FROM table_standing WHERE round_id = :round_id');
            $deleteStmt->execute([':round_id' => $round_id]);

            $matches = self::fetchCompletedMatches($pdo, $round_id);
            if (empty($matches)) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
                return;
            }

            $participantStmt = $pdo->prepare(
                "SELECT mp.id AS match_participant_id, mp.{$participantCol} AS participant_id
                 FROM table_match_participant mp
                 WHERE mp.match_id = :match_id
                   AND mp.deleted_at IS NULL
                 ORDER BY mp.id ASC"
            );

            $scoreStmt = $pdo->prepare(
                'SELECT score FROM table_match_result WHERE match_participant_id = :match_participant_id LIMIT 1'
            );

            $stats = [];

            foreach ($matches as $match) {
                $matchId = (int)$match['id'];

                $participantStmt->execute([':match_id' => $matchId]);
                $participants = $participantStmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($participants) < 2) {
                    continue;
                }

                $a = $participants[0];
                $b = $participants[1];

                $pA = (int)($a['participant_id'] ?? 0);
                $pB = (int)($b['participant_id'] ?? 0);
                $mpA = (int)($a['match_participant_id'] ?? 0);
                $mpB = (int)($b['match_participant_id'] ?? 0);

                if ($pA <= 0 || $pB <= 0 || $mpA <= 0 || $mpB <= 0 || $pA === $pB) {
                    continue;
                }

                $scoreA = self::fetchNumericScore($scoreStmt, $mpA);
                $scoreB = self::fetchNumericScore($scoreStmt, $mpB);

                if ($scoreA === null || $scoreB === null) {
                    continue;
                }

                if (!isset($stats[$pA])) {
                    $stats[$pA] = self::emptyStats($pA);
                }
                if (!isset($stats[$pB])) {
                    $stats[$pB] = self::emptyStats($pB);
                }

                $stats[$pA]['played']++;
                $stats[$pB]['played']++;

                $stats[$pA]['goal_for'] += $scoreA;
                $stats[$pA]['goal_against'] += $scoreB;
                $stats[$pA]['goal_diff'] = $stats[$pA]['goal_for'] - $stats[$pA]['goal_against'];

                $stats[$pB]['goal_for'] += $scoreB;
                $stats[$pB]['goal_against'] += $scoreA;
                $stats[$pB]['goal_diff'] = $stats[$pB]['goal_for'] - $stats[$pB]['goal_against'];

                if ($scoreA > $scoreB) {
                    $stats[$pA]['win']++;
                    $stats[$pA]['points'] += 3;
                    $stats[$pB]['lose']++;
                } elseif ($scoreA < $scoreB) {
                    $stats[$pB]['win']++;
                    $stats[$pB]['points'] += 3;
                    $stats[$pA]['lose']++;
                } else {
                    $stats[$pA]['draw']++;
                    $stats[$pB]['draw']++;
                    $stats[$pA]['points'] += 1;
                    $stats[$pB]['points'] += 1;
                }
            }

            if (empty($stats)) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
                return;
            }

            $rows = array_values($stats);
            usort($rows, static function (array $x, array $y): int {
                return ($y['points'] <=> $x['points'])
                    ?: ($y['goal_diff'] <=> $x['goal_diff'])
                    ?: ($y['goal_for'] <=> $x['goal_for'])
                    ?: ($x['participant_id'] <=> $y['participant_id']);
            });

            $insertCols = [
                'round_id', 'participant_id', 'ranking', 'played', 'win', 'draw', 'lose',
                'goal_for', 'goal_against', 'goal_diff', 'points'
            ];

            // Optional metadata columns if they exist.
            if (isset($standingColumns['event_id']) && isset($round['event_id'])) {
                $insertCols[] = 'event_id';
            }
            if (isset($standingColumns['created_at'])) {
                $insertCols[] = 'created_at';
            }
            if (isset($standingColumns['updated_at'])) {
                $insertCols[] = 'updated_at';
            }

            $insertSql = 'INSERT INTO table_standing ('
                . implode(', ', $insertCols)
                . ') VALUES ('
                . implode(', ', array_map(static fn(string $c): string => ':' . $c, $insertCols))
                . ')';

            $insertStmt = $pdo->prepare($insertSql);

            $rank = 1;
            foreach ($rows as $r) {
                $params = [
                    ':round_id' => $round_id,
                    ':participant_id' => (int)$r['participant_id'],
                    ':ranking' => $rank,
                    ':played' => (int)$r['played'],
                    ':win' => (int)$r['win'],
                    ':draw' => (int)$r['draw'],
                    ':lose' => (int)$r['lose'],
                    ':goal_for' => (int)$r['goal_for'],
                    ':goal_against' => (int)$r['goal_against'],
                    ':goal_diff' => (int)$r['goal_diff'],
                    ':points' => (int)$r['points'],
                ];

                if (isset($standingColumns['event_id']) && isset($round['event_id'])) {
                    $params[':event_id'] = (int)$round['event_id'];
                }
                if (isset($standingColumns['created_at'])) {
                    $params[':created_at'] = date('Y-m-d H:i:s');
                }
                if (isset($standingColumns['updated_at'])) {
                    $params[':updated_at'] = date('Y-m-d H:i:s');
                }

                $insertStmt->execute($params);
                $rank++;
            }

            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function fetchRoundRow(PDO $pdo, int $round_id): ?array
    {
        $stmt = $pdo->prepare('SELECT id, round_type, event_id FROM table_round WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $round_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    private static function fetchCompletedMatches(PDO $pdo, int $round_id): array
    {
        $stmt = $pdo->prepare(
            "SELECT id, round_id, match_no
             FROM table_match
             WHERE round_id = :round_id
               AND status = 'completed'
               AND deleted_at IS NULL
             ORDER BY match_no ASC, id ASC"
        );
        $stmt->execute([':round_id' => $round_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    private static function fetchNumericScore(PDOStatement $stmt, int $matchParticipantId): ?int
    {
        $stmt->execute([':match_participant_id' => $matchParticipantId]);
        $score = $stmt->fetchColumn();
        if ($score === false || $score === null || $score === '') {
            return null;
        }
        if (!is_numeric((string)$score)) {
            return null;
        }
        return (int)round((float)$score);
    }

    /** @return array<string, int> */
    private static function emptyStats(int $participantId): array
    {
        return [
            'participant_id' => $participantId,
            'played' => 0,
            'win' => 0,
            'draw' => 0,
            'lose' => 0,
            'goal_for' => 0,
            'goal_against' => 0,
            'goal_diff' => 0,
            'points' => 0,
        ];
    }

    /** @return array<string, true> */
    private static function getTableColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name'
        );
        $stmt->execute([':table_name' => $table]);
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $map = [];
        if (is_array($cols)) {
            foreach ($cols as $col) {
                $map[strtolower((string)$col)] = true;
            }
        }
        return $map;
    }

    private static function resolveParticipantColumn(PDO $pdo): string
    {
        $cols = self::getTableColumns($pdo, 'table_match_participant');
        if (isset($cols['participant_id'])) {
            return 'participant_id';
        }
        if (isset($cols['team_id'])) {
            return 'team_id';
        }
        if (isset($cols['pasukan_id'])) {
            return 'pasukan_id';
        }
        throw new RuntimeException('table_match_participant is missing participant reference column.');
    }
}
