<?php
declare(strict_types=1);

final class KnockoutAdvanceService
{
    public static function advance(int $roundId, PDO $pdo): void
    {
        if ($roundId <= 0) return;

        $round = self::getRound($pdo, $roundId);
        if (!$round || strtolower((string)($round['round_type'] ?? '')) !== 'knockout') return;

        $matchMap = self::getMatchMap($pdo, $roundId);
        if (empty($matchMap)) return;

        $advanceMap = self::getAdvanceMap($round, $matchMap);
        if (empty($advanceMap)) return;

        $participantCol = self::participantColumn($pdo);
        if ($participantCol === null) return;

        foreach ($advanceMap as $sourceNo => $targetSpecs) {
            $sourceNo = (int)$sourceNo;
            if ($sourceNo <= 0 || !isset($matchMap[$sourceNo])) continue;
            if (!is_array($targetSpecs) || isset($targetSpecs['match_no'])) {
                $targetSpecs = [$targetSpecs];
            }
            foreach ($targetSpecs as $targetSpec) {
                if (!is_array($targetSpec)) continue;
                $targetNo = (int)($targetSpec['match_no'] ?? 0);
                $slot = strtolower((string)($targetSpec['slot'] ?? ''));
                $outcome = strtolower((string)($targetSpec['outcome'] ?? 'winner'));
                if ($targetNo <= 0 || !isset($matchMap[$targetNo])) continue;

                $sourceMatchId = (int)$matchMap[$sourceNo]['id'];
                $targetMatchId = (int)$matchMap[$targetNo]['id'];
                $pid = self::getOutcomeParticipantId($pdo, $sourceMatchId, $participantCol, $outcome);
                if ($pid <= 0) continue;

                self::upsertParticipantToTarget($pdo, $targetMatchId, $pid, $participantCol, $slot);
            }
        }
    }

    private static function getRound(PDO $pdo, int $roundId): ?array
    {
        $sql = "SELECT id, round_type, qualification_rule FROM table_round WHERE id = :id";
        if (self::tableHasColumn($pdo, 'table_round', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
        $sql .= " LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':id' => $roundId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($r) ? $r : null;
    }

    private static function getMatchMap(PDO $pdo, int $roundId): array
    {
        $sql = "SELECT id, match_no, status FROM table_match WHERE round_id = :round_id";
        if (self::tableHasColumn($pdo, 'table_match', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
        $sql .= " ORDER BY match_no ASC, id ASC";
        $st = $pdo->prepare($sql);
        $st->execute([':round_id' => $roundId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) {
            $no = (int)($r['match_no'] ?? 0);
            if ($no > 0 && !isset($map[$no])) $map[$no] = $r;
        }
        return $map;
    }

    private static function getAdvanceMap(array $round, array $matchMap): array
    {
        $map = [];
        $rule = trim((string)($round['qualification_rule'] ?? ''));
        if ($rule !== '') {
            $decoded = json_decode($rule, true);
            if (is_array($decoded) && isset($decoded['advance_map']) && is_array($decoded['advance_map'])) {
                foreach ($decoded['advance_map'] as $src => $target) {
                    $srcNo = (int)$src;
                    if ($srcNo <= 0) continue;
                    if (is_array($target) && array_is_list($target)) {
                        foreach ($target as $t) {
                            if (!is_array($t)) continue;
                            $tNo = (int)($t['match_no'] ?? 0);
                            $slot = strtolower((string)($t['slot'] ?? ''));
                            $outcome = strtolower((string)($t['outcome'] ?? 'winner'));
                            if ($tNo > 0) $map[$srcNo][] = ['match_no' => $tNo, 'slot' => $slot, 'outcome' => $outcome];
                        }
                    } elseif (is_array($target)) {
                        $tNo = (int)($target['match_no'] ?? 0);
                        $slot = strtolower((string)($target['slot'] ?? ''));
                        $outcome = strtolower((string)($target['outcome'] ?? 'winner'));
                        if ($tNo > 0) $map[$srcNo][] = ['match_no' => $tNo, 'slot' => $slot, 'outcome' => $outcome];
                    } else {
                        $tNo = (int)$target;
                        if ($tNo > 0) $map[$srcNo][] = ['match_no' => $tNo, 'slot' => '', 'outcome' => 'winner'];
                    }
                }
            }
        }

        // Fallback for your current flow:
        // winner 16 -> 18, winner 17 -> 19
        // loser 18 -> 20, loser 19 -> 20
        // winner 18 -> 21, winner 19 -> 21
        if (empty($map) && isset($matchMap[16], $matchMap[17], $matchMap[18], $matchMap[19])) {
            $map[16][] = ['match_no' => 18, 'slot' => 'away', 'outcome' => 'winner'];
            $map[17][] = ['match_no' => 19, 'slot' => 'away', 'outcome' => 'winner'];
            if (isset($matchMap[20], $matchMap[21])) {
                $map[18][] = ['match_no' => 20, 'slot' => 'home', 'outcome' => 'loser'];
                $map[19][] = ['match_no' => 20, 'slot' => 'away', 'outcome' => 'loser'];
                $map[18][] = ['match_no' => 21, 'slot' => 'home', 'outcome' => 'winner'];
                $map[19][] = ['match_no' => 21, 'slot' => 'away', 'outcome' => 'winner'];
            }
        }

        return $map;
    }

    private static function getOutcomeParticipantId(PDO $pdo, int $matchId, string $participantCol, string $outcome): int
    {
        $sql = "SELECT mp.id AS mpid, mp.{$participantCol} AS pid
                FROM table_match_participant mp
                WHERE mp.match_id = :match_id";
        if (self::tableHasColumn($pdo, 'table_match_participant', 'deleted_at')) $sql .= " AND mp.deleted_at IS NULL";
        $sql .= " ORDER BY mp.id ASC";
        $st = $pdo->prepare($sql);
        $st->execute([':match_id' => $matchId]);
        $parts = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($parts) < 2) return 0;

        $scores = [];
        $sSt = $pdo->prepare("SELECT score FROM table_match_result WHERE match_participant_id = :mpid LIMIT 1");
        foreach ($parts as $p) {
            $mpid = (int)($p['mpid'] ?? 0);
            if ($mpid <= 0) continue;
            $sSt->execute([':mpid' => $mpid]);
            $score = $sSt->fetchColumn();
            if ($score === false || $score === null || $score === '') return 0;
            $scores[$mpid] = (float)$score;
        }
        if (count($scores) < 2) return 0;

        $a = $parts[0];
        $b = $parts[1];
        $aMpid = (int)$a['mpid'];
        $bMpid = (int)$b['mpid'];
        $aScore = $scores[$aMpid] ?? null;
        $bScore = $scores[$bMpid] ?? null;
        if ($aScore === null || $bScore === null) return 0;
        if ((float)$aScore === (float)$bScore) return 0; // draw not advanced automatically
        $winner = ((float)$aScore > (float)$bScore) ? (int)$a['pid'] : (int)$b['pid'];
        $loser = ((float)$aScore > (float)$bScore) ? (int)$b['pid'] : (int)$a['pid'];
        return ($outcome === 'loser') ? $loser : $winner;
    }

    private static function upsertParticipantToTarget(PDO $pdo, int $targetMatchId, int $winnerPid, string $participantCol, string $slot): void
    {
        // Already exists in target -> idempotent skip
        $sqlExists = "SELECT COUNT(*) FROM table_match_participant WHERE match_id = :match_id AND {$participantCol} = :pid";
        if (self::tableHasColumn($pdo, 'table_match_participant', 'deleted_at')) $sqlExists .= " AND deleted_at IS NULL";
        $stExists = $pdo->prepare($sqlExists);
        $stExists->execute([':match_id' => $targetMatchId, ':pid' => $winnerPid]);
        if ((int)$stExists->fetchColumn() > 0) return;

        $hasLane = self::tableHasColumn($pdo, 'table_match_participant', 'lane_no');
        $hasDeletedAt = self::tableHasColumn($pdo, 'table_match_participant', 'deleted_at');
        $hasCreatedAt = self::tableHasColumn($pdo, 'table_match_participant', 'created_at');

        $countSql = "SELECT COUNT(*) FROM table_match_participant WHERE match_id = :match_id";
        if ($hasDeletedAt) $countSql .= " AND deleted_at IS NULL";
        $stCount = $pdo->prepare($countSql);
        $stCount->execute([':match_id' => $targetMatchId]);
        $existingCount = (int)$stCount->fetchColumn();
        if ($existingCount >= 2) return;

        $cols = ['match_id', $participantCol];
        $vals = [':match_id', ':pid'];
        $params = [':match_id' => $targetMatchId, ':pid' => $winnerPid];

        if ($hasLane) {
            $lane = null;
            if ($slot === 'home') $lane = 1;
            if ($slot === 'away') $lane = 2;
            if ($lane !== null) {
                $cols[] = 'lane_no';
                $vals[] = ':lane_no';
                $params[':lane_no'] = $lane;
            }
        }
        if ($hasCreatedAt) {
            $cols[] = 'created_at';
            $vals[] = ':created_at';
            $params[':created_at'] = date('Y-m-d H:i:s');
        }

        $ins = $pdo->prepare('INSERT INTO table_match_participant (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')');
        $ins->execute($params);
    }

    private static function participantColumn(PDO $pdo): ?string
    {
        if (self::tableHasColumn($pdo, 'table_match_participant', 'participant_id')) return 'participant_id';
        if (self::tableHasColumn($pdo, 'table_match_participant', 'team_id')) return 'team_id';
        if (self::tableHasColumn($pdo, 'table_match_participant', 'pasukan_id')) return 'pasukan_id';
        return null;
    }

    private static function tableHasColumn(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = strtolower($table . '.' . $column);
        if (array_key_exists($key, $cache)) return (bool)$cache[$key];

        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name');
        $st->execute([':table_name' => $table, ':column_name' => $column]);
        $ok = ((int)$st->fetchColumn() > 0);
        $cache[$key] = $ok;
        return $ok;
    }
}
