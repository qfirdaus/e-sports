<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/rbac.php';

// Support both structures:
// 1) app/pages + services at project root (../../services)
// 2) pages + services at same root level (../services)
$serviceBaseCandidates = [
    __DIR__ . '/../../services',
    __DIR__ . '/../services',
];

$loadService = static function (string $file) use ($serviceBaseCandidates): void {
    foreach ($serviceBaseCandidates as $base) {
        $path = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $file;
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }
    throw new RuntimeException('Service file not found: ' . $file);
};

$loadService('RoundService.php');
$loadService('LeagueStandingService.php');
$loadService('KnockoutSeedingService.php');
$loadService('KnockoutAdvanceService.php');
$loadService('TournamentService.php');

Session::start();
$auth = getAuth();
$auth->requireAuth();

$rbac = getRBAC();
$rbac->requirePageAccess('pages/matches.php');

$page_title = 'Matches';

function matches_has_column(PDO $db, string $table, string $column): bool {
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) {
        return (bool)$cache[$key];
    }

    try {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
              AND column_name = :column_name
        ");
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);
        $exists = ((int)$stmt->fetchColumn() > 0);
        $cache[$key] = $exists;
        return $exists;
    } catch (Exception $e) {
        $cache[$key] = false;
        return false;
    }
}

function matches_table_exists(PDO $db, string $table): bool {
    static $cache = [];
    $key = strtolower($table);
    if (array_key_exists($key, $cache)) {
        return (bool)$cache[$key];
    }

    try {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
        ");
        $stmt->execute([':table_name' => $table]);
        $exists = ((int)$stmt->fetchColumn() > 0);
        $cache[$key] = $exists;
        return $exists;
    } catch (Exception $e) {
        $cache[$key] = false;
        return false;
    }
}

function matches_ensure_set_result_table(PDO $db): void {
    if (matches_table_exists($db, 'table_match_result_set')) {
        return;
    }
    $sql = "
        CREATE TABLE IF NOT EXISTS table_match_result_set (
            id INT AUTO_INCREMENT PRIMARY KEY,
            match_id INT NOT NULL,
            set_no INT NOT NULL,
            score_a INT NOT NULL,
            score_b INT NOT NULL,
            created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            UNIQUE KEY uq_match_set (match_id, set_no),
            INDEX idx_match_id (match_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    $db->exec($sql);
}

function matches_get_set_rows(PDO $db, int $matchId): array {
    if ($matchId <= 0 || !matches_table_exists($db, 'table_match_result_set')) {
        return [];
    }
    $hasDeleted = matches_has_column($db, 'table_match_result_set', 'deleted_at');
    $sql = "
        SELECT set_no, score_a, score_b
        FROM table_match_result_set
        WHERE match_id = :match_id
    ";
    if ($hasDeleted) $sql .= " AND deleted_at IS NULL";
    $sql .= " ORDER BY set_no ASC";
    $st = $db->prepare($sql);
    $st->execute([':match_id' => $matchId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function matches_delete_set_rows(PDO $db, int $matchId): void {
    if ($matchId <= 0 || !matches_table_exists($db, 'table_match_result_set')) return;
    if (matches_has_column($db, 'table_match_result_set', 'deleted_at')) {
        $st = $db->prepare("UPDATE table_match_result_set SET deleted_at = NOW() WHERE match_id = :match_id AND deleted_at IS NULL");
        $st->execute([':match_id' => $matchId]);
    } else {
        $st = $db->prepare("DELETE FROM table_match_result_set WHERE match_id = :match_id");
        $st->execute([':match_id' => $matchId]);
    }
}

function matches_save_set_rows(PDO $db, int $matchId, array $sets): void {
    if ($matchId <= 0) return;
    $activeSetNos = [];
    foreach ($sets as $row) {
        $setNo = (int)($row['set_no'] ?? 0);
        if ($setNo > 0) $activeSetNos[] = $setNo;
    }
    $activeSetNos = array_values(array_unique($activeSetNos));

    // Mark/remove stale set rows not included in latest payload.
    if (!empty($activeSetNos)) {
        $ph = implode(',', array_fill(0, count($activeSetNos), '?'));
        if (matches_has_column($db, 'table_match_result_set', 'deleted_at')) {
            $sqlClear = "UPDATE table_match_result_set SET deleted_at = NOW() WHERE match_id = ? AND set_no NOT IN ({$ph}) AND deleted_at IS NULL";
            $stClear = $db->prepare($sqlClear);
            $stClear->execute(array_merge([$matchId], $activeSetNos));
        } else {
            $sqlClear = "DELETE FROM table_match_result_set WHERE match_id = ? AND set_no NOT IN ({$ph})";
            $stClear = $db->prepare($sqlClear);
            $stClear->execute(array_merge([$matchId], $activeSetNos));
        }
    } else {
        matches_delete_set_rows($db, $matchId);
    }

    $upsert = $db->prepare("
        INSERT INTO table_match_result_set (match_id, set_no, score_a, score_b)
        VALUES (:match_id, :set_no, :score_a, :score_b)
        ON DUPLICATE KEY UPDATE
            score_a = VALUES(score_a),
            score_b = VALUES(score_b),
            deleted_at = NULL
    ");
    foreach ($sets as $row) {
        $setNo = (int)($row['set_no'] ?? 0);
        $sa = (int)($row['score_a'] ?? 0);
        $sb = (int)($row['score_b'] ?? 0);
        if ($setNo <= 0) continue;
        $upsert->execute([
            ':match_id' => $matchId,
            ':set_no' => $setNo,
            ':score_a' => $sa,
            ':score_b' => $sb,
        ]);
    }
}

function matches_participant_column(PDO $db): string {
    if (matches_has_column($db, 'table_match_participant', 'participant_id')) {
        return 'participant_id';
    }
    if (matches_has_column($db, 'table_match_participant', 'team_id')) {
        return 'team_id';
    }
    if (matches_has_column($db, 'table_match_participant', 'pasukan_id')) {
        return 'pasukan_id';
    }
    return 'participant_id';
}

function matches_get_sukan_by_round(PDO $db, int $roundId): ?int {
    if ($roundId <= 0) {
        return null;
    }

    try {
        if (matches_has_column($db, 'table_round', 'sukan_id')) {
            $stmt = $db->prepare("SELECT sukan_id FROM table_round WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $roundId]);
            $sukanId = (int)$stmt->fetchColumn();
            return $sukanId > 0 ? $sukanId : null;
        }

        if (matches_has_column($db, 'table_round', 'event_id') && matches_table_exists($db, 'table_event')) {
            $stmt = $db->prepare("
                SELECT e.sukan_id
                FROM table_round r
                INNER JOIN table_event e ON e.id = r.event_id
                WHERE r.id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $roundId]);
            $sukanId = (int)$stmt->fetchColumn();
            return $sukanId > 0 ? $sukanId : null;
        }
    } catch (Exception $e) {
        error_log('[matches.php] Failed to resolve sukan by round: ' . $e->getMessage());
    }

    return null;
}

function matches_not_deleted_clause(PDO $db, string $table, string $alias): string {
    return matches_has_column($db, $table, 'deleted_at') ? " AND {$alias}.deleted_at IS NULL" : '';
}

function matches_get_round_meta(PDO $db, int $roundId): ?array {
    if ($roundId <= 0) {
        return null;
    }
    $hasEvent = matches_has_column($db, 'table_round', 'event_id');
    $hasLocked = matches_has_column($db, 'table_round', 'is_locked');
    $fields = ['id', 'round_type', 'qualification_rule'];
    if ($hasEvent) $fields[] = 'event_id';
    if ($hasLocked) $fields[] = 'is_locked';
    $stmt = $db->prepare('SELECT ' . implode(', ', $fields) . ' FROM table_round WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $roundId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($r) ? $r : null;
}

function matches_find_knockout_round(PDO $db, int $groupRoundId): int {
    $round = matches_get_round_meta($db, $groupRoundId);
    if (!$round) {
        return 0;
    }
    $sql = "SELECT id FROM table_round WHERE round_type = 'knockout'";
    $params = [];
    if (array_key_exists('event_id', $round)) {
        $sql .= " AND event_id = :event_id";
        $params[':event_id'] = (int)$round['event_id'];
    }
    if (matches_has_column($db, 'table_round', 'deleted_at')) {
        $sql .= " AND deleted_at IS NULL";
    }
    $sql .= " ORDER BY id ASC LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $id = (int)$stmt->fetchColumn();
    return $id > 0 ? $id : 0;
}

function matches_build_slot_placeholders(array $roundRow): array {
    $out = [];
    $rule = trim((string)($roundRow['qualification_rule'] ?? ''));
    if ($rule === '') return $out;
    $decoded = json_decode($rule, true);
    if (!is_array($decoded) || !isset($decoded['advance_map']) || !is_array($decoded['advance_map'])) {
        return $out;
    }

    foreach ($decoded['advance_map'] as $sourceNo => $targets) {
        $src = (int)$sourceNo;
        if ($src <= 0) continue;
        $labelFrom = static function(string $outcome, int $matchNo): string {
            $o = strtolower(trim($outcome));
            if ($o === 'loser' || $o === 'kalah') return 'KALAH ' . $matchNo;
            return 'MENANG ' . $matchNo;
        };

        if (is_array($targets) && array_is_list($targets)) {
            foreach ($targets as $t) {
                if (!is_array($t)) continue;
                $targetNo = (int)($t['match_no'] ?? 0);
                $slot = strtolower((string)($t['slot'] ?? ''));
                $outcome = strtolower((string)($t['outcome'] ?? 'winner'));
                if ($targetNo <= 0 || ($slot !== 'home' && $slot !== 'away')) continue;
                $out[$targetNo][$slot] = $labelFrom($outcome, $src);
            }
        } elseif (is_array($targets)) {
            $targetNo = (int)($targets['match_no'] ?? 0);
            $slot = strtolower((string)($targets['slot'] ?? ''));
            $outcome = strtolower((string)($targets['outcome'] ?? 'winner'));
            if ($targetNo <= 0) continue;
            if ($slot !== 'home' && $slot !== 'away') $slot = 'away';
            $out[$targetNo][$slot] = $labelFrom($outcome, $src);
        } else {
            $targetNo = (int)$targets;
            if ($targetNo <= 0) continue;
            $out[$targetNo]['away'] = $labelFrom('winner', $src);
        }
    }

    return $out;
}

function matches_extract_date_only($value): string {
    $s = trim((string)$value);
    if ($s === '') return '';
    $ts = strtotime($s);
    if ($ts === false) return '';
    return date('Y-m-d', $ts);
}

function matches_pick_round_result_context(PDO $db, int $roundId): array {
    $sukanId = 0;
    $kategoriId = null;

    if ($roundId <= 0) {
        return ['sukan_id' => 0, 'kategori_id' => null];
    }

    $hasRoundEventId = matches_has_column($db, 'table_round', 'event_id');
    $hasRoundSukanId = matches_has_column($db, 'table_round', 'sukan_id');
    $hasEventTable = matches_table_exists($db, 'table_event');
    $hasEventSukanId = $hasEventTable && matches_has_column($db, 'table_event', 'sukan_id');
    $hasEventKategoriId = $hasEventTable && matches_has_column($db, 'table_event', 'kategori_id');

    if ($hasRoundEventId && $hasEventTable) {
        $parts = [];
        if ($hasEventSukanId) $parts[] = 'e.sukan_id';
        if ($hasEventKategoriId) $parts[] = 'e.kategori_id';
        if (!empty($parts)) {
            $sql = 'SELECT ' . implode(', ', $parts) . ' FROM table_round r INNER JOIN table_event e ON e.id = r.event_id WHERE r.id = :rid LIMIT 1';
            $st = $db->prepare($sql);
            $st->execute([':rid' => $roundId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                if ($hasEventSukanId) $sukanId = (int)($row['sukan_id'] ?? 0);
                if ($hasEventKategoriId) {
                    $kategoriVal = isset($row['kategori_id']) ? (int)$row['kategori_id'] : 0;
                    $kategoriId = $kategoriVal > 0 ? $kategoriVal : null;
                }
            }
        }
    }

    if ($sukanId <= 0 && $hasRoundSukanId) {
        $st = $db->prepare('SELECT sukan_id FROM table_round WHERE id = :rid LIMIT 1');
        $st->execute([':rid' => $roundId]);
        $sukanId = (int)$st->fetchColumn();
    }

    return ['sukan_id' => ($sukanId > 0 ? $sukanId : 0), 'kategori_id' => $kategoriId];
}

function matches_pick_match_outcome(PDO $db, int $matchId, string $participantCol): ?array {
    if ($matchId <= 0) return null;

    $st = $db->prepare("
        SELECT mp.id AS match_participant_id, mp.{$participantCol} AS participant_id, mr.score
        FROM table_match_participant mp
        LEFT JOIN table_match_result mr ON mr.match_participant_id = mp.id
        WHERE mp.match_id = :mid
          AND mp.deleted_at IS NULL
        ORDER BY mp.id ASC
    ");
    $st->execute([':mid' => $matchId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows) || count($rows) < 2) return null;

    $a = $rows[0];
    $b = $rows[1];
    $scoreA = $a['score'];
    $scoreB = $b['score'];
    if ($scoreA === null || $scoreA === '' || $scoreB === null || $scoreB === '') return null;
    if (!is_numeric($scoreA) || !is_numeric($scoreB)) return null;

    $pidA = (int)($a['participant_id'] ?? 0);
    $pidB = (int)($b['participant_id'] ?? 0);
    if ($pidA <= 0 || $pidB <= 0) return null;

    $numA = (float)$scoreA;
    $numB = (float)$scoreB;
    if ($numA === $numB) return null;

    if ($numA > $numB) {
        return ['winner_id' => $pidA, 'loser_id' => $pidB];
    }
    return ['winner_id' => $pidB, 'loser_id' => $pidA];
}

function matches_pick_medal_matches(PDO $db, int $roundId): ?array {
    if ($roundId <= 0) return null;

    $hasRoundOrder = matches_has_column($db, 'table_round', 'round_order');
    $hasEventId = matches_has_column($db, 'table_round', 'event_id');
    $hasSukanId = matches_has_column($db, 'table_round', 'sukan_id');
    $hasRoundDeletedAt = matches_has_column($db, 'table_round', 'deleted_at');

    $fields = ['id', 'round_type'];
    if ($hasRoundOrder) $fields[] = 'round_order';
    if ($hasEventId) $fields[] = 'event_id';
    if ($hasSukanId) $fields[] = 'sukan_id';
    $st = $db->prepare('SELECT ' . implode(', ', $fields) . ' FROM table_round WHERE id = :rid LIMIT 1');
    $st->execute([':rid' => $roundId]);
    $currRound = $st->fetch(PDO::FETCH_ASSOC);
    if (!$currRound) return null;
    if (strtolower((string)($currRound['round_type'] ?? '')) !== 'knockout') return null;

    $sql = 'SELECT id, ' . ($hasRoundOrder ? 'COALESCE(round_order, 0)' : '0') . " AS round_order FROM table_round WHERE round_type = 'knockout'";
    $params = [];
    if ($hasEventId && isset($currRound['event_id']) && (int)$currRound['event_id'] > 0) {
        $sql .= ' AND event_id = :event_id';
        $params[':event_id'] = (int)$currRound['event_id'];
    } elseif ($hasSukanId && isset($currRound['sukan_id']) && (int)$currRound['sukan_id'] > 0) {
        $sql .= ' AND sukan_id = :sukan_id';
        $params[':sukan_id'] = (int)$currRound['sukan_id'];
    }
    if ($hasRoundDeletedAt) $sql .= ' AND deleted_at IS NULL';
    $sql .= ' ORDER BY round_order DESC, id DESC';
    $rSt = $db->prepare($sql);
    $rSt->execute($params);
    $knockoutRounds = $rSt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($knockoutRounds) || empty($knockoutRounds)) return null;

    $finalRoundId = (int)($knockoutRounds[0]['id'] ?? 0);
    $bronzeRoundId = 0;
    if (count($knockoutRounds) > 1) {
        $bronzeRoundId = (int)($knockoutRounds[1]['id'] ?? 0);
    }

    $mSql = 'SELECT id, round_id, match_no, tarikh FROM table_match WHERE round_id = :rid';
    if (matches_has_column($db, 'table_match', 'deleted_at')) $mSql .= ' AND deleted_at IS NULL';
    $mSql .= ' ORDER BY match_no ASC, id ASC';
    $mSt = $db->prepare($mSql);

    $finalMatch = null;
    $bronzeMatch = null;
    $finalMatches = [];

    if ($finalRoundId > 0) {
        $mSt->execute([':rid' => $finalRoundId]);
        $finalMatches = $mSt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($finalMatches) && !empty($finalMatches)) {
            // In terminal round, the largest match_no is treated as Grand Final (emas/perak).
            $finalMatch = $finalMatches[count($finalMatches) - 1];
            // Football-style rule: 2 matches terakhir dalam round terminal = bronze + final.
            if (count($finalMatches) >= 2) {
                $bronzeMatch = $finalMatches[count($finalMatches) - 2];
            }
        }
    }

    // Prefer explicit advance_map inference:
    // - target with 2x "loser" sources = bronze match
    // - target with 2x "winner" sources = final match
    if ((!$bronzeMatch || !$finalMatch) && is_array($finalMatches) && !empty($finalMatches)) {
        $matchByNo = [];
        foreach ($finalMatches as $fm) {
            $no = (int)($fm['match_no'] ?? 0);
            if ($no > 0 && !isset($matchByNo[$no])) $matchByNo[$no] = $fm;
        }
        $rule = trim((string)($currRound['qualification_rule'] ?? ''));
        if ($rule !== '') {
            $decoded = json_decode($rule, true);
            if (is_array($decoded) && isset($decoded['advance_map']) && is_array($decoded['advance_map'])) {
                $targetOutcomes = [];
                foreach ($decoded['advance_map'] as $srcNo => $targets) {
                    if (is_array($targets) && array_is_list($targets)) {
                        foreach ($targets as $t) {
                            if (!is_array($t)) continue;
                            $tn = (int)($t['match_no'] ?? 0);
                            $oc = strtolower(trim((string)($t['outcome'] ?? 'winner')));
                            if ($tn > 0) $targetOutcomes[$tn][] = $oc;
                        }
                    } elseif (is_array($targets)) {
                        $tn = (int)($targets['match_no'] ?? 0);
                        $oc = strtolower(trim((string)($targets['outcome'] ?? 'winner')));
                        if ($tn > 0) $targetOutcomes[$tn][] = $oc;
                    }
                }
                $bronzeNo = 0;
                $finalNo = 0;
                foreach ($targetOutcomes as $tn => $outs) {
                    $winnerCount = 0;
                    $loserCount = 0;
                    foreach ($outs as $oc) {
                        if ($oc === 'loser' || $oc === 'kalah') $loserCount++;
                        else $winnerCount++;
                    }
                    if ($loserCount >= 2) $bronzeNo = (int)$tn;
                    if ($winnerCount >= 2) $finalNo = (int)$tn;
                }
                if ($bronzeNo > 0 && isset($matchByNo[$bronzeNo])) $bronzeMatch = $matchByNo[$bronzeNo];
                if ($finalNo > 0 && isset($matchByNo[$finalNo])) $finalMatch = $matchByNo[$finalNo];
            }
        }
    }

    // Legacy fallback: if exactly 2 matches in terminal round and bronze still missing.
    if (!$bronzeMatch && is_array($finalMatches) && count($finalMatches) >= 2) {
        $bronzeMatch = $finalMatches[0];
    }

    if ($bronzeRoundId > 0) {
        $mSt->execute([':rid' => $bronzeRoundId]);
        $bronzeMatches = $mSt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($bronzeMatches) && !empty($bronzeMatches)) {
            $bronzeMatch = $bronzeMatches[0];
        }
    }

    // Legacy layout fallback: both medal matches in one same round (2+ matches).
    if (!$bronzeMatch && $finalRoundId > 0) {
        $mSt->execute([':rid' => $finalRoundId]);
        $sameRoundMatches = $mSt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($sameRoundMatches) && count($sameRoundMatches) >= 2) {
            $bronzeMatch = $sameRoundMatches[0];
            $finalMatch = $sameRoundMatches[count($sameRoundMatches) - 1];
        }
    }

    if (!$bronzeMatch || !$finalMatch) return null;

    return [
        'bronze_match' => $bronzeMatch,
        'final_match' => $finalMatch,
    ];
}

function matches_get_kategori_penilaian(PDO $db, ?int $kategoriId): string {
    if ($kategoriId === null || (int)$kategoriId <= 0) return 'berkumpulan';
    if (!matches_table_exists($db, 'table_kategori')) return 'berkumpulan';

    $hasDeleted = matches_has_column($db, 'table_kategori', 'deleted_at');
    $hasStatus = matches_has_column($db, 'table_kategori', 'status');
    $sql = 'SELECT penilaian FROM table_kategori WHERE id = :id';
    if ($hasDeleted) $sql .= ' AND deleted_at IS NULL';
    if ($hasStatus) $sql .= ' AND status = 1';
    $sql .= ' LIMIT 1';
    $st = $db->prepare($sql);
    $st->execute([':id' => (int)$kategoriId]);
    $p = strtolower(trim((string)$st->fetchColumn()));
    if ($p !== 'individu' && $p !== 'berkumpulan') return 'berkumpulan';
    return $p;
}

function matches_mark_round_completed_if_ready(PDO $db, int $roundId): void {
    if ($roundId <= 0) return;
    if (!matches_has_column($db, 'table_round', 'status')) return;

    $rSt = $db->prepare('SELECT id, round_type FROM table_round WHERE id = :rid LIMIT 1');
    $rSt->execute([':rid' => $roundId]);
    $round = $rSt->fetch(PDO::FETCH_ASSOC);
    if (!$round) return;

    $mSql = 'SELECT id FROM table_match WHERE round_id = :rid';
    if (matches_has_column($db, 'table_match', 'deleted_at')) $mSql .= ' AND deleted_at IS NULL';
    $mSql .= ' ORDER BY id ASC';
    $mSt = $db->prepare($mSql);
    $mSt->execute([':rid' => $roundId]);
    $matches = $mSt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($matches) || empty($matches)) return;

    $pSql = "SELECT mp.id FROM table_match_participant mp WHERE mp.match_id = :mid";
    if (matches_has_column($db, 'table_match_participant', 'deleted_at')) $pSql .= ' AND mp.deleted_at IS NULL';
    $pSql .= " ORDER BY mp.id ASC";
    $pSt = $db->prepare($pSql);
    $sSt = $db->prepare('SELECT score FROM table_match_result WHERE match_participant_id = :mpid LIMIT 1');

    foreach ($matches as $m) {
        $mid = (int)($m['id'] ?? 0);
        if ($mid <= 0) return;

        $pSt->execute([':mid' => $mid]);
        $participants = $pSt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($participants) || count($participants) < 2) return;

        for ($i = 0; $i < 2; $i++) {
            $mpid = (int)($participants[$i]['id'] ?? 0);
            if ($mpid <= 0) return;
            $sSt->execute([':mpid' => $mpid]);
            $score = $sSt->fetchColumn();
            if ($score === false || $score === null || $score === '' || !is_numeric($score)) {
                return;
            }
        }
    }

    $uSt = $db->prepare("UPDATE table_round SET status = 'completed' WHERE id = :rid");
    $uSt->execute([':rid' => $roundId]);
}

function matches_upsert_knockout_medals(PDO $db, int $roundId, ?int $selectedSukanId = null, ?int $selectedKategoriId = null): array {
    $state = ['updated' => false, 'reason' => 'not_knockout_or_incomplete'];
    if ($roundId <= 0) return $state;

    $roundSt = $db->prepare('SELECT id, round_type FROM table_round WHERE id = :rid LIMIT 1');
    $roundSt->execute([':rid' => $roundId]);
    $round = $roundSt->fetch(PDO::FETCH_ASSOC);
    if (!$round) return $state;
    if (strtolower((string)($round['round_type'] ?? '')) !== 'knockout') return $state;

    $medalMatches = matches_pick_medal_matches($db, $roundId);
    if (!$medalMatches) {
        $state['reason'] = 'medal_matches_not_found';
        return $state;
    }
    $bronzeMatch = $medalMatches['bronze_match'];
    $finalMatch = $medalMatches['final_match'];

    $participantCol = matches_participant_column($db);

    $bronzeOutcome = matches_pick_match_outcome($db, (int)$bronzeMatch['id'], $participantCol);
    $finalOutcome = matches_pick_match_outcome($db, (int)$finalMatch['id'], $participantCol);
    if (!$bronzeOutcome || !$finalOutcome) {
        $state['reason'] = 'both_medal_matches_not_completed';
        return $state;
    }

    $positions = [
        1 => (int)$finalOutcome['winner_id'],
        2 => (int)$finalOutcome['loser_id'],
        3 => (int)$bronzeOutcome['winner_id'],
    ];

    $context = matches_pick_round_result_context($db, $roundId);
    $sukanId = (int)($context['sukan_id'] ?? 0);
    $kategoriId = $context['kategori_id'] ?? null;
    if ($selectedSukanId !== null && (int)$selectedSukanId > 0) {
        $sukanId = (int)$selectedSukanId;
    }
    if ($selectedKategoriId !== null && (int)$selectedKategoriId > 0) {
        $kategoriId = (int)$selectedKategoriId;
    }
    if ($sukanId <= 0) {
        $state['reason'] = 'invalid_sukan_id';
        return $state;
    }

    $tarikh = matches_extract_date_only($finalMatch['tarikh'] ?? '');
    if ($tarikh === '') {
        $tarikh = matches_extract_date_only($bronzeMatch['tarikh'] ?? '');
    }
    if ($tarikh === '') {
        $tarikh = date('Y-m-d');
    }

    ksort($positions);
    $standings = [];
    foreach ($positions as $pos => $pid) {
        if ($pid <= 0) continue;
        $standings[] = ['position' => (int)$pos, 'participant_id' => (int)$pid];
    }
    if (empty($standings)) {
        $state['reason'] = 'standings_empty';
        return $state;
    }
    $standingsJson = json_encode($standings, JSON_UNESCAPED_UNICODE);
    if ($standingsJson === false) {
        $state['reason'] = 'standings_json_failed';
        return $state;
    }

    // Align with manual keputusan rules:
    // - individu: allow winners from the same kontingen
    // - berkumpulan: participants for each medal position must be unique
    $penilaian = matches_get_kategori_penilaian($db, ($kategoriId !== null ? (int)$kategoriId : null));
    if ($penilaian !== 'individu') {
        $ids = [];
        foreach ($standings as $s) {
            $ids[] = (int)($s['participant_id'] ?? 0);
        }
        $ids = array_values(array_filter($ids, static fn($v) => $v > 0));
        if (count($ids) !== count(array_unique($ids))) {
            $state['reason'] = 'duplicate_team_participant';
            return $state;
        }
    }

    $hasKategoriCol = matches_has_column($db, 'table_results', 'kategori_id');
    $hasUpdatedBy = matches_has_column($db, 'table_results', 'updated_by');
    $hasCreatedBy = matches_has_column($db, 'table_results', 'created_by');
    $userId = Session::has('user_id') ? (int)Session::get('user_id') : 0;

    if ($hasKategoriCol && $kategoriId !== null && (int)$kategoriId > 0) {
        $existingSt = $db->prepare('SELECT id FROM table_results WHERE kategori_id = :kategori_id AND deleted_at IS NULL ORDER BY id DESC LIMIT 1');
        $existingSt->execute([':kategori_id' => (int)$kategoriId]);
    } else {
        $existingSt = $db->prepare('SELECT id FROM table_results WHERE sukan_id = :sukan_id AND deleted_at IS NULL ORDER BY id DESC LIMIT 1');
        $existingSt->execute([':sukan_id' => $sukanId]);
    }
    $existingId = (int)$existingSt->fetchColumn();

    if ($existingId > 0) {
        $set = [
            'sukan_id = :sukan_id',
            'tarikh = :tarikh',
            "status = 'completed'",
            'standings = :standings',
            'updated_at = NOW()',
        ];
        $params = [
            ':id' => $existingId,
            ':sukan_id' => $sukanId,
            ':tarikh' => $tarikh,
            ':standings' => $standingsJson,
        ];
        if ($hasKategoriCol) {
            $set[] = 'kategori_id = :kategori_id';
            $params[':kategori_id'] = ($kategoriId !== null && (int)$kategoriId > 0) ? (int)$kategoriId : null;
        }
        if ($hasUpdatedBy) {
            $set[] = 'updated_by = :updated_by';
            $params[':updated_by'] = ($userId > 0 ? $userId : null);
        }
        $upd = $db->prepare('UPDATE table_results SET ' . implode(', ', $set) . ' WHERE id = :id');
        $upd->execute($params);
        $roundIds = [
            (int)($bronzeMatch['round_id'] ?? 0),
            (int)($finalMatch['round_id'] ?? 0),
        ];
        $roundIds = array_values(array_unique(array_filter($roundIds, static fn($v) => $v > 0)));
        if (!empty($roundIds) && matches_has_column($db, 'table_round', 'status')) {
            $ph = implode(',', array_fill(0, count($roundIds), '?'));
            $rs = $db->prepare("UPDATE table_round SET status = 'completed' WHERE id IN ({$ph})");
            $rs->execute($roundIds);
        }
        $state['updated'] = true;
        $state['reason'] = 'updated_existing';
        return $state;
    }

    $cols = ['sukan_id', 'tarikh', 'status', 'standings'];
    $vals = [':sukan_id', ':tarikh', "'completed'", ':standings'];
    $params = [
        ':sukan_id' => $sukanId,
        ':tarikh' => $tarikh,
        ':standings' => $standingsJson,
    ];
    if ($hasKategoriCol) {
        $cols[] = 'kategori_id';
        $vals[] = ':kategori_id';
        $params[':kategori_id'] = ($kategoriId !== null && (int)$kategoriId > 0) ? (int)$kategoriId : null;
    }
    if ($hasCreatedBy) {
        $cols[] = 'created_by';
        $vals[] = ':created_by';
        $params[':created_by'] = ($userId > 0 ? $userId : null);
    }
    if ($hasUpdatedBy) {
        $cols[] = 'updated_by';
        $vals[] = ':updated_by';
        $params[':updated_by'] = ($userId > 0 ? $userId : null);
    }

    $ins = $db->prepare('INSERT INTO table_results (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')');
    $ins->execute($params);
    $roundIds = [
        (int)($bronzeMatch['round_id'] ?? 0),
        (int)($finalMatch['round_id'] ?? 0),
    ];
    $roundIds = array_values(array_unique(array_filter($roundIds, static fn($v) => $v > 0)));
    if (!empty($roundIds) && matches_has_column($db, 'table_round', 'status')) {
        $ph = implode(',', array_fill(0, count($roundIds), '?'));
        $rs = $db->prepare("UPDATE table_round SET status = 'completed' WHERE id IN ({$ph})");
        $rs->execute($roundIds);
    }
    $state['updated'] = true;
    $state['reason'] = 'inserted_new';
    return $state;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_knockout') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Generate the knockout stage from Round Standings to use the latest global rules.'
    ]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_match_detail') {
    header('Content-Type: application/json; charset=utf-8');
    $matchId = isset($_GET['match_id']) ? (int)$_GET['match_id'] : 0;
    if ($matchId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid match ID.']);
        exit;
    }

    try {
        $db = getDB();
        $participantCol = matches_participant_column($db);

        $matchStmt = $db->prepare("
            SELECT m.id, m.round_id, m.match_no, m.status, m.tarikh, r.nama_round, COALESCE(r.group_code, '-') AS group_code
            FROM table_match m
            INNER JOIN table_round r ON r.id = m.round_id
            WHERE m.id = :match_id
              AND m.deleted_at IS NULL
            LIMIT 1
        ");
        $matchStmt->execute([':match_id' => $matchId]);
        $match = $matchStmt->fetch(PDO::FETCH_ASSOC);
        if (!$match) {
            echo json_encode(['success' => false, 'message' => 'Matches not found.']);
            exit;
        }

        $participantStmt = $db->prepare("
            SELECT mp.id AS match_participant_id, mp.{$participantCol} AS participant_id, p.nama_pasukan
            FROM table_match_participant mp
            INNER JOIN table_pasukan p ON p.id = mp.{$participantCol}
            WHERE mp.match_id = :match_id
              AND mp.deleted_at IS NULL
            ORDER BY mp.id ASC
        ");
        $participantStmt->execute([':match_id' => $matchId]);
        $participants = $participantStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($participants) < 2) {
            echo json_encode(['success' => false, 'message' => 'Incomplete team data.']);
            exit;
        }

        $scoreStmt = $db->prepare("
            SELECT score
            FROM table_match_result
            WHERE match_participant_id = :match_participant_id
            LIMIT 1
        ");
        foreach ($participants as &$p) {
            $scoreStmt->execute([':match_participant_id' => (int)$p['match_participant_id']]);
            $score = $scoreStmt->fetchColumn();
            $p['score'] = ($score !== false && $score !== null) ? (string)$score : '';
        }
        unset($p);
        $setRows = matches_get_set_rows($db, $matchId);

        echo json_encode([
            'success' => true,
            'match' => $match,
            'participants' => $participants,
            'sets' => $setRows,
        ]);
    } catch (Exception $e) {
        error_log('[matches.php:get_match_detail] ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Unable to load match details.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_result') {
    header('Content-Type: application/json; charset=utf-8');
    $matchId = isset($_POST['match_id']) ? (int)$_POST['match_id'] : 0;
    $mpA = isset($_POST['match_participant_a']) ? (int)$_POST['match_participant_a'] : 0;
    $mpB = isset($_POST['match_participant_b']) ? (int)$_POST['match_participant_b'] : 0;
    $scoreA = isset($_POST['score_a']) ? trim((string)$_POST['score_a']) : '';
    $scoreB = isset($_POST['score_b']) ? trim((string)$_POST['score_b']) : '';
    $scoringMode = isset($_POST['scoring_mode']) ? strtolower(trim((string)$_POST['scoring_mode'])) : 'score';
    if (!in_array($scoringMode, ['score', 'set3', 'set5', 'set7'], true)) $scoringMode = 'score';
    $setAList = isset($_POST['set_a']) && is_array($_POST['set_a']) ? $_POST['set_a'] : [];
    $setBList = isset($_POST['set_b']) && is_array($_POST['set_b']) ? $_POST['set_b'] : [];
    $selectedSukanId = isset($_POST['sukan_id']) ? (int)$_POST['sukan_id'] : 0;
    $selectedKategoriId = isset($_POST['kategori_id']) ? (int)$_POST['kategori_id'] : 0;

    if ($matchId <= 0 || $mpA <= 0 || $mpB <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid result parameter.']);
        exit;
    }
    $setRowsToSave = [];
    if ($scoringMode !== 'score') {
        $modeToSets = ['set3' => 3, 'set5' => 5, 'set7' => 7];
        $maxSets = (int)($modeToSets[$scoringMode] ?? 3);
        $winTarget = (int)(floor($maxSets / 2) + 1);
        $winsA = 0;
        $winsB = 0;
        $winnerFoundAt = 0;
        for ($i = 0; $i < $maxSets; $i++) {
            $aRaw = isset($setAList[$i]) ? trim((string)$setAList[$i]) : '';
            $bRaw = isset($setBList[$i]) ? trim((string)$setBList[$i]) : '';
            if ($aRaw === '' && $bRaw === '') continue;
            if ($aRaw === '' || $bRaw === '') {
                echo json_encode(['success' => false, 'message' => 'Set #' . ($i + 1) . ' requires scores for both teams.']);
                exit;
            }
            if (!preg_match('/^\d+$/', $aRaw) || !preg_match('/^\d+$/', $bRaw)) {
                echo json_encode(['success' => false, 'message' => 'Skor Set #' . ($i + 1) . ' must be a whole number.']);
                exit;
            }
            $sa = (int)$aRaw;
            $sb = (int)$bRaw;
            if ($sa === $sb) {
                echo json_encode(['success' => false, 'message' => 'Skor Set #' . ($i + 1) . ' cannot end in a draw.']);
                exit;
            }
            if ($winnerFoundAt > 0) {
                echo json_encode(['success' => false, 'message' => 'Additional sets are not allowed after a winner has been determined.']);
                exit;
            }
            $setRowsToSave[] = ['set_no' => $i + 1, 'score_a' => $sa, 'score_b' => $sb];
            if ($sa > $sb) $winsA++;
            if ($sa < $sb) $winsB++;
            if ($winsA >= 2 || $winsB >= 2) {
                $winnerFoundAt = $i + 1;
            }
        }
        if (count($setRowsToSave) < 2) {
            echo json_encode(['success' => false, 'message' => 'Set mode requires at least two sets.']);
            exit;
        }
        if ($winsA !== $winTarget && $winsB !== $winTarget) {
            echo json_encode(['success' => false, 'message' => 'Invalid set result. A winner is required ' . $winTarget . ' set.']);
            exit;
        }
        $scoreA = (string)$winsA;
        $scoreB = (string)$winsB;
    } else {
        if ($scoreA === '' || $scoreB === '') {
            echo json_encode(['success' => false, 'message' => 'Please enter the scores for Team A and Team B.']);
            exit;
        }
        if (!preg_match('/^\d+$/', $scoreA) || !preg_match('/^\d+$/', $scoreB)) {
            echo json_encode(['success' => false, 'message' => 'The score must be a whole number (integer).']);
            exit;
        }
    }

    try {
        $db = getDB();
        if ($scoringMode !== 'score') {
            matches_ensure_set_result_table($db);
        }
        $db->beginTransaction();

        $checkMatch = $db->prepare("
            SELECT id, round_id
            FROM table_match
            WHERE id = :match_id
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $checkMatch->execute([':match_id' => $matchId]);
        $match = $checkMatch->fetch(PDO::FETCH_ASSOC);
        if (!$match) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Matches not found.']);
            exit;
        }
        $roundId = (int)$match['round_id'];

        // Enforce round lock before allowing result updates.
        if (matches_has_column($db, 'table_round', 'is_locked')) {
            $lockStmt = $db->prepare('SELECT is_locked FROM table_round WHERE id = :round_id LIMIT 1');
            $lockStmt->execute([':round_id' => $roundId]);
            $isLocked = (int)$lockStmt->fetchColumn();
            if ($isLocked === 1) {
                throw new RuntimeException('Round already locked. Cannot modify result.');
            }
        }

        $checkParticipant = $db->prepare("
            SELECT COUNT(*)
            FROM table_match_participant
            WHERE match_id = :match_id
              AND id IN (:mp_a, :mp_b)
              AND deleted_at IS NULL
        ");
        $checkParticipant->bindValue(':match_id', $matchId, PDO::PARAM_INT);
        $checkParticipant->bindValue(':mp_a', $mpA, PDO::PARAM_INT);
        $checkParticipant->bindValue(':mp_b', $mpB, PDO::PARAM_INT);
        $checkParticipant->execute();
        if ((int)$checkParticipant->fetchColumn() < 2) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Invalid match participant.']);
            exit;
        }

        $existsStmt = $db->prepare("
            SELECT id
            FROM table_match_result
            WHERE match_participant_id = :match_participant_id
            LIMIT 1
        ");
        $updateStmt = $db->prepare("
            UPDATE table_match_result
            SET score = :score
            WHERE match_participant_id = :match_participant_id
        ");
        $insertStmt = $db->prepare("
            INSERT INTO table_match_result (match_participant_id, score)
            VALUES (:match_participant_id, :score)
        ");

        $toSave = [
            ['match_participant_id' => $mpA, 'score' => $scoreA],
            ['match_participant_id' => $mpB, 'score' => $scoreB],
        ];
        foreach ($toSave as $r) {
            $existsStmt->execute([':match_participant_id' => (int)$r['match_participant_id']]);
            $exists = (int)$existsStmt->fetchColumn();
            if ($exists > 0) {
                $updateStmt->execute([
                    ':score' => (string)$r['score'],
                    ':match_participant_id' => (int)$r['match_participant_id'],
                ]);
            } else {
                $insertStmt->execute([
                    ':match_participant_id' => (int)$r['match_participant_id'],
                    ':score' => (string)$r['score'],
                ]);
            }
        }
        if ($scoringMode !== 'score') {
            matches_save_set_rows($db, $matchId, $setRowsToSave);
        } else {
            matches_delete_set_rows($db, $matchId);
        }

        $updateMatch = $db->prepare("
            UPDATE table_match
            SET status = 'completed'
            WHERE id = :match_id
        ");
        $updateMatch->execute([':match_id' => $matchId]);

        TournamentService::handleAfterMatchSave($matchId, $db);
        $medalSync = ['updated' => false, 'reason' => 'not_processed'];
        try {
            matches_mark_round_completed_if_ready($db, $roundId);
            $medalSync = matches_upsert_knockout_medals(
                $db,
                $roundId,
                ($selectedSukanId > 0 ? $selectedSukanId : null),
                ($selectedKategoriId > 0 ? $selectedKategoriId : null)
            );
        } catch (Throwable $autoEx) {
            error_log('[matches.php:save_result:auto] ' . $autoEx->getMessage());
            $medalSync = ['updated' => false, 'reason' => 'automation_error'];
        }

        $db->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Result saved successfully.',
            'medal_sync' => $medalSync,
        ]);
    } catch (Exception $e) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[matches.php:save_result] ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Unable to save the result: ' . $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_categories') {
    header('Content-Type: application/json; charset=utf-8');
    $sukanId = isset($_GET['sukan_id']) ? (int)$_GET['sukan_id'] : 0;

    if ($sukanId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid sport ID.']);
        exit;
    }

    try {
        $db = getDB();
        if (!matches_table_exists($db, 'table_kategori')) {
            echo json_encode(['success' => true, 'categories' => []]);
            exit;
        }
        $hasStatus = matches_has_column($db, 'table_kategori', 'status');
        $hasDeleted = matches_has_column($db, 'table_kategori', 'deleted_at');
        $sql = "SELECT id, nama_kategori FROM table_kategori WHERE sukan_id = :sukan_id";
        if ($hasStatus) $sql .= " AND status = 1";
        if ($hasDeleted) $sql .= " AND deleted_at IS NULL";
        $sql .= " ORDER BY nama_kategori ASC, id ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([':sukan_id' => $sukanId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rows = is_array($rows) ? $rows : [];
        echo json_encode(['success' => true, 'categories' => $rows]);
    } catch (Exception $e) {
        error_log('[matches.php:get_categories] ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Unable to load categories.']);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_rounds') {
    header('Content-Type: application/json; charset=utf-8');
    $sukanId = isset($_GET['sukan_id']) ? (int)$_GET['sukan_id'] : 0;
    $kategoriId = isset($_GET['kategori_id']) ? (int)$_GET['kategori_id'] : 0;

    if ($sukanId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid sport ID.']);
        exit;
    }
    if ($kategoriId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid category ID.']);
        exit;
    }

    try {
        $db = getDB();
        $hasRoundGroup = matches_has_column($db, 'table_round', 'group_code');
        $hasRoundOrder = matches_has_column($db, 'table_round', 'group_order');
        $groupSelect = $hasRoundGroup ? "COALESCE(r.group_code, '')" : "''";
        $orderRound = $hasRoundOrder ? 'r.group_order ASC, ' : '';
        $roundDeleted = matches_not_deleted_clause($db, 'table_round', 'r');
        $eventDeleted = matches_not_deleted_clause($db, 'table_event', 'e');
        $rows = [];

        if (matches_has_column($db, 'table_round', 'event_id')
            && matches_table_exists($db, 'table_event')
            && matches_has_column($db, 'table_event', 'sukan_id')
            && matches_has_column($db, 'table_event', 'kategori_id')
        ) {
            $stmt = $db->prepare("
                SELECT r.id, r.nama_round, {$groupSelect} AS group_code
                FROM table_round r
                INNER JOIN table_event e ON e.id = r.event_id
                WHERE e.sukan_id = :sukan_id
                  AND e.kategori_id = :kategori_id
                  {$roundDeleted}
                  {$eventDeleted}
                ORDER BY r.nama_round ASC, {$orderRound} r.id ASC
            ");
            $stmt->execute([
                ':sukan_id' => $sukanId,
                ':kategori_id' => $kategoriId,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif (matches_has_column($db, 'table_round', 'sukan_id')) {
            $stmt = $db->prepare("
                SELECT r.id, r.nama_round, {$groupSelect} AS group_code
                FROM table_round r
                WHERE r.sukan_id = :sukan_id
                  {$roundDeleted}
                ORDER BY r.nama_round ASC, {$orderRound} r.id ASC
            ");
            $stmt->execute([':sukan_id' => $sukanId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif (matches_has_column($db, 'table_round', 'event_id') && matches_table_exists($db, 'table_event')) {
            $stmt = $db->prepare("
                SELECT r.id, r.nama_round, {$groupSelect} AS group_code
                FROM table_round r
                INNER JOIN table_event e ON e.id = r.event_id
                WHERE e.sukan_id = :sukan_id
                  {$roundDeleted}
                  {$eventDeleted}
                ORDER BY r.nama_round ASC, {$orderRound} r.id ASC
            ");
            $stmt->execute([':sukan_id' => $sukanId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $db->query("
                SELECT r.id, r.nama_round, {$groupSelect} AS group_code
                FROM table_round r
                WHERE 1=1 {$roundDeleted}
                ORDER BY r.nama_round ASC, {$orderRound} r.id ASC
            ");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        }

        $rounds = $rows;
        echo json_encode(['success' => true, 'rounds' => $rounds]);
    } catch (Exception $e) {
        error_log('[matches.php:get_rounds] ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Unable to load rounds.']);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'list_matches') {
    header('Content-Type: application/json; charset=utf-8');
    $roundId = isset($_GET['round_id']) ? (int)$_GET['round_id'] : 0;

    if ($roundId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid round.']);
        exit;
    }

    try {
        $db = getDB();
        $participantCol = matches_participant_column($db);
        $hasMatchGroup = matches_has_column($db, 'table_match', 'group_code');
        $hasRoundGroup = matches_has_column($db, 'table_round', 'group_code');
        $hasVenueId = matches_has_column($db, 'table_match', 'venue_id');
        $hasVenueDetail = matches_has_column($db, 'table_match', 'venue_detail');

        $groupExpr = $hasMatchGroup
            ? "COALESCE(NULLIF(m.group_code, ''), " . ($hasRoundGroup ? "NULLIF(r.group_code, '')" : "NULL") . ", '-')"
            : ($hasRoundGroup ? "COALESCE(NULLIF(r.group_code, ''), '-')" : "'-'");
        $venueIdSelect = $hasVenueId ? 'm.venue_id' : 'NULL';
        $venueDetailSelect = $hasVenueDetail ? 'm.venue_detail' : "''";
        $venueJoin = $hasVenueId ? 'LEFT JOIN table_ref_venues v ON v.id = m.venue_id' : '';
        $venueNameSelect = $hasVenueId ? "COALESCE(v.nama_venue, '')" : "''";

        $stmt = $db->prepare("
            SELECT m.*, r.nama_round, r.round_type, {$groupExpr} AS group_code,
                   {$venueIdSelect} AS venue_id, {$venueDetailSelect} AS venue_detail, {$venueNameSelect} AS venue_name
            FROM table_match m
            JOIN table_round r ON r.id = m.round_id
            {$venueJoin}
            WHERE m.round_id = ?
              AND m.deleted_at IS NULL
            ORDER BY m.match_no ASC
        ");
        $stmt->execute([$roundId]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $slotPlaceholders = [];
        $ruleStmt = $db->prepare("SELECT qualification_rule FROM table_round WHERE id = ? LIMIT 1");
        $ruleStmt->execute([$roundId]);
        $roundRule = $ruleStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($roundRule)) {
            $slotPlaceholders = matches_build_slot_placeholders($roundRule);
        }

        $participantStmt = $db->prepare("
            SELECT mp.id, mp.{$participantCol} AS participant_id, p.nama_pasukan
            FROM table_match_participant mp
            JOIN table_pasukan p ON p.id = mp.{$participantCol}
            WHERE mp.match_id = ?
              AND mp.deleted_at IS NULL
            ORDER BY mp.id ASC
        ");
        $scoreStmt = $db->prepare("
            SELECT score
            FROM table_match_result
            WHERE match_participant_id = ?
            LIMIT 1
        ");
        $setCountByMatch = [];
        if (matches_table_exists($db, 'table_match_result_set')) {
            $matchIds = [];
            foreach ($matches as $_m) {
                $mid = (int)($_m['id'] ?? 0);
                if ($mid > 0) $matchIds[] = $mid;
            }
            $matchIds = array_values(array_unique($matchIds));
            if (!empty($matchIds)) {
                $in = implode(',', array_fill(0, count($matchIds), '?'));
                $sqlSetCount = "SELECT match_id, COUNT(*) AS cnt FROM table_match_result_set WHERE match_id IN ({$in})";
                if (matches_has_column($db, 'table_match_result_set', 'deleted_at')) {
                    $sqlSetCount .= " AND deleted_at IS NULL";
                }
                $sqlSetCount .= " GROUP BY match_id";
                $stSetCount = $db->prepare($sqlSetCount);
                $stSetCount->execute($matchIds);
                $setCountRows = $stSetCount->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($setCountRows as $scr) {
                    $sid = (int)($scr['match_id'] ?? 0);
                    $cnt = (int)($scr['cnt'] ?? 0);
                    if ($sid > 0 && $cnt > 0) $setCountByMatch[$sid] = $cnt;
                }
            }
        }

        $rows = [];
        foreach ($matches as $m) {
            $participantStmt->execute([(int)$m['id']]);
            $participants = $participantStmt->fetchAll(PDO::FETCH_ASSOC);

            $teamA = $participants[0]['nama_pasukan'] ?? '-';
            $teamB = $participants[1]['nama_pasukan'] ?? '-';
            $mno = (int)($m['match_no'] ?? 0);
            $phA = $slotPlaceholders[$mno]['home'] ?? '';
            $phB = $slotPlaceholders[$mno]['away'] ?? '';
            if ((string)$teamA === '-' && $phA !== '') $teamA = $phA;
            if ((string)$teamB === '-' && $phB !== '') $teamB = $phB;
            $scoreA = null;
            $scoreB = null;
            if (!empty($participants[0]['id'])) {
                $scoreStmt->execute([(int)$participants[0]['id']]);
                $rawA = $scoreStmt->fetchColumn();
                if ($rawA !== false && $rawA !== null && $rawA !== '') {
                    $scoreA = (string)$rawA;
                }
            }
            if (!empty($participants[1]['id'])) {
                $scoreStmt->execute([(int)$participants[1]['id']]);
                $rawB = $scoreStmt->fetchColumn();
                if ($rawB !== false && $rawB !== null && $rawB !== '') {
                    $scoreB = (string)$rawB;
                }
            }
            $resultText = ($scoreA !== null && $scoreB !== null) ? ($scoreA . '-' . $scoreB) : '';
            $winnerSide = '';
            if ($scoreA !== null && $scoreB !== null && is_numeric((string)$scoreA) && is_numeric((string)$scoreB)) {
                $nA = (float)$scoreA;
                $nB = (float)$scoreB;
                if ($nA > $nB) $winnerSide = 'a';
                elseif ($nB > $nA) $winnerSide = 'b';
            }
            $status = (string)($m['status'] ?? 'scheduled');
            $groupCode = trim((string)($m['group_code'] ?? '-'));
            $roundTypeRow = strtolower(trim((string)($m['round_type'] ?? '')));
            if (($groupCode === '' || $groupCode === '-') && $roundTypeRow === 'knockout') {
                $groupCode = 'Knockout';
            }
            $rows[] = [
                'id' => (int)$m['id'],
                'round_id' => (int)$m['round_id'],
                'nama_round' => $m['nama_round'] ?? '',
                'group_code' => $groupCode,
                'match_no' => $m['match_no'] ?? '',
                'team_a' => $teamA,
                'team_b' => $teamB,
                'venue_name' => $m['venue_name'] ?? '',
                'venue_detail' => $m['venue_detail'] ?? '',
                'result_text' => $resultText,
                'has_set_results' => isset($setCountByMatch[(int)$m['id']]) ? 1 : 0,
                'winner_side' => $winnerSide,
                'tarikh' => $m['tarikh'] ?? '',
                'status' => $status,
                'action_label' => ($status === 'completed' ? 'Edit Result' : 'Key In Result'),
            ];
        }

        $roundMeta = matches_get_round_meta($db, $roundId);
        $roundType = strtolower((string)($roundMeta['round_type'] ?? ''));
        $isLocked = (int)($roundMeta['is_locked'] ?? 0);
        $isComplete = RoundService::isRoundComplete($roundId, $db);
        $knockoutRoundId = matches_find_knockout_round($db, $roundId);
        // Generation is centralized in round-standing.php (global stage flow).
        $canGenerateKnockout = false;

        echo json_encode([
            'success' => true,
            'rows' => $rows,
            'meta' => [
                'round_type' => $roundType,
                'is_locked' => $isLocked,
                'is_complete' => $isComplete ? 1 : 0,
                'knockout_round_id' => $knockoutRoundId,
                'can_generate_knockout' => $canGenerateKnockout ? 1 : 0,
            ],
        ]);
    } catch (Exception $e) {
        error_log('[matches.php:list_matches] ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Unable to load matches.']);
    }
    exit;
}

$db = getDB();
$statusCondition = matches_has_column($db, 'table_sukan', 'status') ? ' AND status = 1' : '';
$sportsStmt = $db->query("
    SELECT id, nama_sukan
    FROM table_sukan
    WHERE deleted_at IS NULL {$statusCondition}
    ORDER BY nama_sukan ASC
");
$sports = $sportsStmt ? $sportsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$preselectedSukanId = isset($_GET['sukan_id']) ? (int)$_GET['sukan_id'] : 0;
$preselectedKategoriId = isset($_GET['kategori_id']) ? (int)$_GET['kategori_id'] : 0;
$preselectedRoundId = isset($_GET['round_id']) ? (int)$_GET['round_id'] : 0;
$saved = isset($_GET['saved']) && (string)$_GET['saved'] === '1';
if ($preselectedSukanId <= 0 && $preselectedRoundId > 0) {
    $resolvedSukan = matches_get_sukan_by_round($db, $preselectedRoundId);
    if ($resolvedSukan !== null) {
        $preselectedSukanId = $resolvedSukan;
    }
}
if ($preselectedKategoriId <= 0 && $preselectedRoundId > 0) {
    $ctxByRound = matches_pick_round_result_context($db, $preselectedRoundId);
    $resolvedKategori = (int)($ctxByRound['kategori_id'] ?? 0);
    if ($resolvedKategori > 0) {
        $preselectedKategoriId = $resolvedKategori;
    }
}

ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/matches.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/matches.css'); ?>">
<div class="w-100 px-3 matches-view">
    <?php if ($saved): ?>
        <div class="alert alert-success">Result saved successfully.</div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div>
                        <div class="matches-eyebrow">SAM 2026 &middot; MATCH MANAGEMENT</div><h4 class="mb-1">Match</h4>
                        <p class="text-muted mb-0">Select a sport, category and round to view fixtures and enter results.</p>
                    </div>
                    <fieldset class="border rounded p-3 mt-3">
                        <legend class="float-none w-auto px-2 fs-6 fw-semibold mb-0">Filters & Actions</legend>
                        <div class="d-flex align-items-center gap-2 justify-content-start flex-nowrap">
                            <div class="match-filter"><label for="matchSport">Sport</label><select id="matchSport" class="form-select" style="min-width:240px;">
                                <option value="">-- Please Select --</option>
                                <?php foreach ($sports as $sport): ?>
                                    <option value="<?php echo (int)$sport['id']; ?>" <?php echo ((int)$sport['id'] === $preselectedSukanId) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(english_sport_label((string)$sport['nama_sukan']), ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select></div>

                            <div class="match-filter"><label for="matchCategory">Category</label><select id="matchCategory" class="form-select" style="min-width:340px;" disabled>
                                <option value="">-- Please Select --</option>
                            </select></div>

                            <div class="match-filter"><label for="matchRound">Round</label><select id="matchRound" class="form-select" style="min-width:240px;" disabled>
                                <option value="">-- Please Select --</option>
                            </select></div>

                            <button id="btnGenerateKnockout" type="button" class="btn btn-warning d-none text-nowrap px-4">Knockout Round</button>
                        </div>
                    </fieldset>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header">
            <strong>Match List</strong>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th style="width: 6%;">Group</th>
                            <th style="width: 6%;">Match No</th>
                            <th style="width: 18%;">Team A</th>
                            <th style="width: 18%;">Team B</th>
                            <th style="width: 20%;">Venue</th>
                            <th style="width: 8%;">Results</th>
                            <th style="width: 10%;">Date</th>
                            <th style="width: 7%;">Status</th>
                            <th style="width: 7%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="matchesBody">
                        <tr>
                            <td colspan="9" class="text-muted text-center py-4">Please select a sport, category and round.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
    .modal-professional .modal-content {
        border: 0;
        border-radius: 14px;
        box-shadow: 0 18px 50px rgba(20, 35, 60, .18);
    }
    .modal-professional .modal-header {
        background: linear-gradient(180deg, #f8fbff 0%, #f1f6ff 100%);
        border-bottom: 1px solid #e2eaf6;
        padding: .9rem 1rem;
    }
    .modal-professional .modal-title {
        font-weight: 700;
        color: #1f3555;
    }
    .modal-professional .modal-body {
        padding: 1rem;
    }
    .modal-professional .modal-footer {
        border-top: 1px solid #e8edf5;
        background: #fbfcff;
        padding: .75rem 1rem;
    }
    .modal-professional .btn {
        border-radius: 8px;
    }
</style>

<div class="modal fade modal-professional" id="resultModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Key In Result</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="resultModalMsg" class="alert alert-danger d-none mb-3"></div>
                <input type="hidden" id="rmMatchId">
                <input type="hidden" id="rmMatchParticipantA">
                <input type="hidden" id="rmMatchParticipantB">

                <div class="mb-3">
                    <div class="small text-muted">Round / Match</div>
                    <div id="rmMeta" class="fw-semibold">-</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" id="rmLabelA">Team A - Score</label>
                    <input id="rmScoreA" type="number" min="0" step="1" class="form-control" placeholder="Score Team A Name">
                </div>

                <div class="mb-3">
                    <label class="form-label" id="rmLabelB">Team B - Score</label>
                    <input id="rmScoreB" type="number" min="0" step="1" class="form-control" placeholder="Score Team B Name">
                </div>

                <div class="mb-3">
                    <label class="form-label">Recording Method</label>
                    <select id="rmScoringMode" class="form-select">
                        <option value="score">Standard Score (one value per team)</option>
                        <option value="set3">Best of 3 Sets</option>
                        <option value="set5">Best of 5 Sets</option>
                        <option value="set7">Best of 7 Sets</option>
                    </select>
                </div>

                <div id="rmSetWrap" class="d-none">
                    <div class="small text-muted mb-2">Enter each set score. The system will calculate the match result automatically (e.g. 2–1).</div>
                    <div class="d-flex align-items-center gap-0 mb-2" style="width:100%;">
                        <div class="fw-semibold" style="flex:0 0 10%; max-width:10%;">Set</div>
                        <div class="fw-semibold px-1" id="rmSetHeadA" style="flex:0 0 45%; max-width:45%;">Team A Name</div>
                        <div class="fw-semibold px-1" id="rmSetHeadB" style="flex:0 0 45%; max-width:45%;">Team B Name</div>
                    </div>
                    <div class="d-flex align-items-center gap-0 mb-2" style="width:100%;" data-set-row="1">
                        <div style="flex:0 0 10%; max-width:10%;" class="pt-2">Set 1</div>
                        <div class="px-1" style="flex:0 0 45%; max-width:45%;"><input id="rmSetA1" type="number" min="0" step="1" inputmode="numeric" class="form-control" placeholder="Score Team A Name"></div>
                        <div class="px-1" style="flex:0 0 45%; max-width:45%;"><input id="rmSetB1" type="number" min="0" step="1" inputmode="numeric" class="form-control" placeholder="Score Team B Name"></div>
                    </div>
                    <div class="d-flex align-items-center gap-0 mb-2" style="width:100%;" data-set-row="2">
                        <div style="flex:0 0 10%; max-width:10%;" class="pt-2">Set 2</div>
                        <div class="px-1" style="flex:0 0 45%; max-width:45%;"><input id="rmSetA2" type="number" min="0" step="1" inputmode="numeric" class="form-control" placeholder="Score Team A Name"></div>
                        <div class="px-1" style="flex:0 0 45%; max-width:45%;"><input id="rmSetB2" type="number" min="0" step="1" inputmode="numeric" class="form-control" placeholder="Score Team B Name"></div>
                    </div>
                    <div class="d-flex align-items-center gap-0 mb-2" style="width:100%;" data-set-row="3">
                        <div style="flex:0 0 10%; max-width:10%;" class="pt-2">Set 3</div>
                        <div class="px-1" style="flex:0 0 45%; max-width:45%;"><input id="rmSetA3" type="number" min="0" step="1" inputmode="numeric" class="form-control" placeholder="Score Team A Name"></div>
                        <div class="px-1" style="flex:0 0 45%; max-width:45%;"><input id="rmSetB3" type="number" min="0" step="1" inputmode="numeric" class="form-control" placeholder="Score Team B Name"></div>
                    </div>
                    <div class="d-flex align-items-center gap-0 mb-2 d-none" style="width:100%;" data-set-row="4">
                        <div style="flex:0 0 10%; max-width:10%;" class="pt-2">Set 4</div>
                        <div class="px-1" style="flex:0 0 45%; max-width:45%;"><input id="rmSetA4" type="number" min="0" step="1" inputmode="numeric" class="form-control" placeholder="Score Team A Name"></div>
                        <div class="px-1" style="flex:0 0 45%; max-width:45%;"><input id="rmSetB4" type="number" min="0" step="1" inputmode="numeric" class="form-control" placeholder="Score Team B Name"></div>
                    </div>
                    <div class="d-flex align-items-center gap-0 mb-2 d-none" style="width:100%;" data-set-row="5">
                        <div style="flex:0 0 10%; max-width:10%;" class="pt-2">Set 5</div>
                        <div class="px-1" style="flex:0 0 45%; max-width:45%;"><input id="rmSetA5" type="number" min="0" step="1" inputmode="numeric" class="form-control" placeholder="Score Team A Name"></div>
                        <div class="px-1" style="flex:0 0 45%; max-width:45%;"><input id="rmSetB5" type="number" min="0" step="1" inputmode="numeric" class="form-control" placeholder="Score Team B Name"></div>
                    </div>
                    <div class="d-flex align-items-center gap-0 mb-2 d-none" style="width:100%;" data-set-row="6">
                        <div style="flex:0 0 10%; max-width:10%;" class="pt-2">Set 6</div>
                        <div class="px-1" style="flex:0 0 45%; max-width:45%;"><input id="rmSetA6" type="number" min="0" step="1" inputmode="numeric" class="form-control" placeholder="Score Team A Name"></div>
                        <div class="px-1" style="flex:0 0 45%; max-width:45%;"><input id="rmSetB6" type="number" min="0" step="1" inputmode="numeric" class="form-control" placeholder="Score Team B Name"></div>
                    </div>
                    <div class="d-flex align-items-center gap-0 mb-2 d-none" style="width:100%;" data-set-row="7">
                        <div style="flex:0 0 10%; max-width:10%;" class="pt-2">Set 7</div>
                        <div class="px-1" style="flex:0 0 45%; max-width:45%;"><input id="rmSetA7" type="number" min="0" step="1" inputmode="numeric" class="form-control" placeholder="Score Team A Name"></div>
                        <div class="px-1" style="flex:0 0 45%; max-width:45%;"><input id="rmSetB7" type="number" min="0" step="1" inputmode="numeric" class="form-control" placeholder="Score Team B Name"></div>
                    </div>
                    <div class="small">
                        <span class="text-muted">Match result preview:</span>
                        <span id="rmSetSummary" class="fw-semibold">-</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button id="rmCancelBtn" type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button id="rmSaveBtn" type="button" class="btn btn-primary">Save Result</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade modal-professional" id="setInfoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Set Result Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="setInfoMeta" class="small text-muted mb-2">-</div>
                <div id="setInfoSummary" class="mb-3"></div>
                <div id="setInfoBody" class="small text-muted">No set records.</div>
            </div>
            <div class="modal-footer justify-content-end">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var sportEl = document.getElementById('matchSport');
    var categoryEl = document.getElementById('matchCategory');
    var roundEl = document.getElementById('matchRound');
    var bodyEl = document.getElementById('matchesBody');
    var preCategory = <?php echo (int)$preselectedKategoriId; ?>;
    var preRound = <?php echo (int)$preselectedRoundId; ?>;
    var btnGenerateKnockout = document.getElementById('btnGenerateKnockout');
    var currentRoundMeta = null;
    var resultModalEl = document.getElementById('resultModal');
    var resultModal = null;
    if (resultModalEl && window.bootstrap && window.bootstrap.Modal) {
        resultModal = new window.bootstrap.Modal(resultModalEl);
    }
    var setInfoModalEl = document.getElementById('setInfoModal');
    var setInfoModal = null;
    if (setInfoModalEl && window.bootstrap && window.bootstrap.Modal) {
        setInfoModal = new window.bootstrap.Modal(setInfoModalEl);
    }
    var setInfoMeta = document.getElementById('setInfoMeta');
    var setInfoSummary = document.getElementById('setInfoSummary');
    var setInfoBody = document.getElementById('setInfoBody');
    var rmMatchId = document.getElementById('rmMatchId');
    var rmMatchParticipantA = document.getElementById('rmMatchParticipantA');
    var rmMatchParticipantB = document.getElementById('rmMatchParticipantB');
    var rmMeta = document.getElementById('rmMeta');
    var rmLabelA = document.getElementById('rmLabelA');
    var rmLabelB = document.getElementById('rmLabelB');
    var rmScoreA = document.getElementById('rmScoreA');
    var rmScoreB = document.getElementById('rmScoreB');
    var rmScoringMode = document.getElementById('rmScoringMode');
    var rmSetWrap = document.getElementById('rmSetWrap');
    var rmSetSummary = document.getElementById('rmSetSummary');
    var rmSetA1 = document.getElementById('rmSetA1');
    var rmSetB1 = document.getElementById('rmSetB1');
    var rmSetA2 = document.getElementById('rmSetA2');
    var rmSetB2 = document.getElementById('rmSetB2');
    var rmSetA3 = document.getElementById('rmSetA3');
    var rmSetB3 = document.getElementById('rmSetB3');
    var rmSetA4 = document.getElementById('rmSetA4');
    var rmSetB4 = document.getElementById('rmSetB4');
    var rmSetA5 = document.getElementById('rmSetA5');
    var rmSetB5 = document.getElementById('rmSetB5');
    var rmSetA6 = document.getElementById('rmSetA6');
    var rmSetB6 = document.getElementById('rmSetB6');
    var rmSetA7 = document.getElementById('rmSetA7');
    var rmSetB7 = document.getElementById('rmSetB7');
    var rmSetHeadA = document.getElementById('rmSetHeadA');
    var rmSetHeadB = document.getElementById('rmSetHeadB');
    var rmSaveBtn = document.getElementById('rmSaveBtn');
    var rmCancelBtn = document.getElementById('rmCancelBtn');
    var rmMsg = document.getElementById('resultModalMsg');
    var rmSetRows = resultModalEl ? resultModalEl.querySelectorAll('[data-set-row]') : [];
    var rmSetAInputs = [rmSetA1, rmSetA2, rmSetA3, rmSetA4, rmSetA5, rmSetA6, rmSetA7];
    var rmSetBInputs = [rmSetB1, rmSetB2, rmSetB3, rmSetB4, rmSetB5, rmSetB6, rmSetB7];

    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setMsg(msg) {
        bodyEl.innerHTML = '<tr><td colspan="9" class="text-muted text-center py-4">' + esc(msg) + '</td></tr>';
        if (btnGenerateKnockout) btnGenerateKnockout.classList.add('d-none');
        currentRoundMeta = null;
    }

    function setModalError(msg) {
        if (!rmMsg) return;
        if (!msg) {
            rmMsg.classList.add('d-none');
            rmMsg.textContent = '';
            return;
        }
        rmMsg.classList.remove('d-none');
        rmMsg.textContent = msg;
    }

    function setModalSaving(isSaving) {
        if (rmSaveBtn) rmSaveBtn.disabled = !!isSaving;
        if (rmCancelBtn) rmCancelBtn.disabled = !!isSaving;
        if (rmSaveBtn) rmSaveBtn.textContent = isSaving ? 'Saving...' : 'Save Result';
    }

    function getSetPairs() {
        var out = [];
        for (var i = 0; i < 7; i++) {
            var aEl = rmSetAInputs[i];
            var bEl = rmSetBInputs[i];
            out.push({
                a: (aEl && aEl.value ? aEl.value.trim() : ''),
                b: (bEl && bEl.value ? bEl.value.trim() : '')
            });
        }
        return out;
    }

    function modeToSetConfig(mode) {
        if (mode === 'set7') return { maxSets: 7, winTarget: 4 };
        if (mode === 'set5') return { maxSets: 5, winTarget: 3 };
        return { maxSets: 3, winTarget: 2 };
    }

    function renderSetSummary() {
        if (!rmSetSummary) return;
        var mode = (rmScoringMode && rmScoringMode.value) ? rmScoringMode.value : 'set3';
        var cfg = modeToSetConfig(mode);
        var pairs = getSetPairs();
        var winsA = 0;
        var winsB = 0;
        var hasAny = false;
        var invalid = false;
        pairs.forEach(function (p, idx) {
            if (idx >= cfg.maxSets) return;
            if (!p.a && !p.b) return;
            hasAny = true;
            if (!/^\d+$/.test(p.a || '') || !/^\d+$/.test(p.b || '')) {
                invalid = true;
                return;
            }
            var a = parseInt(p.a, 10);
            var b = parseInt(p.b, 10);
            if (a === b) {
                invalid = true;
                return;
            }
            if (a > b) winsA++;
            if (a < b) winsB++;
        });
        if (!hasAny) {
            rmSetSummary.textContent = '-';
            return;
        }
        if (invalid) {
            rmSetSummary.textContent = 'Invalid set input';
            return;
        }
        rmSetSummary.textContent = winsA + '-' + winsB + ' (target menang: ' + cfg.winTarget + ' set)';
    }

    function applyScoringModeUI() {
        var mode = (rmScoringMode && rmScoringMode.value) ? rmScoringMode.value : 'score';
        var isSetMode = mode !== 'score';
        if (rmSetWrap) rmSetWrap.classList.toggle('d-none', !isSetMode);
        if (rmScoreA && rmScoreA.parentElement) rmScoreA.parentElement.classList.toggle('d-none', isSetMode);
        if (rmScoreB && rmScoreB.parentElement) rmScoreB.parentElement.classList.toggle('d-none', isSetMode);
        var cfg = modeToSetConfig(mode);
        if (rmSetRows && rmSetRows.length) {
            rmSetRows.forEach(function (row) {
                var n = parseInt(row.getAttribute('data-set-row') || '0', 10);
                row.classList.toggle('d-none', !(isSetMode && n > 0 && n <= cfg.maxSets));
            });
        }
        if (isSetMode) renderSetSummary();
    }

    function setScorePlaceholders(nameA, nameB) {
        var labelA = (nameA && String(nameA).trim()) ? String(nameA).trim() : 'Team A Name';
        var labelB = (nameB && String(nameB).trim()) ? String(nameB).trim() : 'Team B Name';
        var phA = 'Score ' + labelA;
        var phB = 'Score ' + labelB;

        if (rmScoreA) rmScoreA.placeholder = phA;
        if (rmScoreB) rmScoreB.placeholder = phB;
        rmSetAInputs.forEach(function (el) { if (el) el.placeholder = phA; });
        rmSetBInputs.forEach(function (el) { if (el) el.placeholder = phB; });
        if (rmSetHeadA) rmSetHeadA.textContent = labelA;
        if (rmSetHeadB) rmSetHeadB.textContent = labelB;
    }

    function bindNumericOnly(el) {
        if (!el) return;
        el.addEventListener('input', function () {
            var v = String(el.value || '');
            var cleaned = v.replace(/[^\d]/g, '');
            if (cleaned !== v) el.value = cleaned;
        });
    }

    function ensureSetInfoModal() {
        if (setInfoModal) return setInfoModal;
        if (setInfoModalEl && window.bootstrap && window.bootstrap.Modal) {
            setInfoModal = new window.bootstrap.Modal(setInfoModalEl);
            return setInfoModal;
        }
        return null;
    }

    function showSetInfoModal() {
        var m = ensureSetInfoModal();
        if (m) {
            m.show();
            return true;
        }
        if (setInfoModalEl && window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.modal === 'function') {
            window.jQuery(setInfoModalEl).modal('show');
            return true;
        }
        return false;
    }

    function openResultModal(matchId) {
        if (!matchId || !resultModalEl) return;
        if (!resultModal && window.bootstrap && window.bootstrap.Modal) {
            resultModal = new window.bootstrap.Modal(resultModalEl);
        }
        if (!resultModal) {
            setModalError('The dialog is not ready. Please refresh the page.');
            return;
        }
        setModalError('');
        setModalSaving(false);
        rmMeta.textContent = 'Loading...';
        rmLabelA.textContent = 'Team A - Score';
        rmLabelB.textContent = 'Team B - Score';
        setScorePlaceholders('Team A Name', 'Team B Name');
        rmScoreA.value = '';
        rmScoreB.value = '';
        if (rmScoringMode) rmScoringMode.value = 'score';
        if (rmSetA1) rmSetA1.value = '';
        if (rmSetB1) rmSetB1.value = '';
        if (rmSetA2) rmSetA2.value = '';
        if (rmSetB2) rmSetB2.value = '';
        if (rmSetA3) rmSetA3.value = '';
        if (rmSetB3) rmSetB3.value = '';
        if (rmSetA4) rmSetA4.value = '';
        if (rmSetB4) rmSetB4.value = '';
        if (rmSetA5) rmSetA5.value = '';
        if (rmSetB5) rmSetB5.value = '';
        if (rmSetA6) rmSetA6.value = '';
        if (rmSetB6) rmSetB6.value = '';
        if (rmSetA7) rmSetA7.value = '';
        if (rmSetB7) rmSetB7.value = '';
        applyScoringModeUI();
        rmMatchId.value = String(matchId);
        rmMatchParticipantA.value = '';
        rmMatchParticipantB.value = '';
        resultModal.show();

        fetch('?action=get_match_detail&match_id=' + encodeURIComponent(matchId))
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.success) {
                    setModalError((j && j.message) ? j.message : 'Unable to load match details.');
                    rmMeta.textContent = '-';
                    return;
                }
                var m = j.match || {};
                var participants = j.participants || [];
                if (participants.length < 2) {
                    setModalError('Incomplete team data.');
                    return;
                }
                var a = participants[0];
                var b = participants[1];
                var nameA = (a.nama_pasukan || 'Team A Name');
                var nameB = (b.nama_pasukan || 'Team B Name');

                rmMeta.textContent = (m.nama_round || '-') + ' / Match ' + (m.match_no || '-');
                rmLabelA.textContent = nameA + ' - Score';
                rmLabelB.textContent = nameB + ' - Score';
                setScorePlaceholders(nameA, nameB);
                rmMatchParticipantA.value = String(a.match_participant_id || '');
                rmMatchParticipantB.value = String(b.match_participant_id || '');
                rmScoreA.value = (a.score != null ? String(a.score) : '');
                rmScoreB.value = (b.score != null ? String(b.score) : '');
                var sets = Array.isArray(j.sets) ? j.sets : [];
                if (sets.length > 0 && rmScoringMode) {
                    var scoreAInt = parseInt(String(a.score || '0'), 10);
                    var scoreBInt = parseInt(String(b.score || '0'), 10);
                    var winCount = Math.max(isNaN(scoreAInt) ? 0 : scoreAInt, isNaN(scoreBInt) ? 0 : scoreBInt);
                    if (winCount >= 4) rmScoringMode.value = 'set7';
                    else if (winCount >= 3) rmScoringMode.value = 'set5';
                    else rmScoringMode.value = 'set3';
                    sets.forEach(function (sr) {
                        var setNo = parseInt(sr.set_no, 10);
                        if (setNo === 1) {
                            if (rmSetA1) rmSetA1.value = String(sr.score_a || '');
                            if (rmSetB1) rmSetB1.value = String(sr.score_b || '');
                        } else if (setNo === 2) {
                            if (rmSetA2) rmSetA2.value = String(sr.score_a || '');
                            if (rmSetB2) rmSetB2.value = String(sr.score_b || '');
                        } else if (setNo === 3) {
                            if (rmSetA3) rmSetA3.value = String(sr.score_a || '');
                            if (rmSetB3) rmSetB3.value = String(sr.score_b || '');
                        } else if (setNo === 4) {
                            if (rmSetA4) rmSetA4.value = String(sr.score_a || '');
                            if (rmSetB4) rmSetB4.value = String(sr.score_b || '');
                        } else if (setNo === 5) {
                            if (rmSetA5) rmSetA5.value = String(sr.score_a || '');
                            if (rmSetB5) rmSetB5.value = String(sr.score_b || '');
                        } else if (setNo === 6) {
                            if (rmSetA6) rmSetA6.value = String(sr.score_a || '');
                            if (rmSetB6) rmSetB6.value = String(sr.score_b || '');
                        } else if (setNo === 7) {
                            if (rmSetA7) rmSetA7.value = String(sr.score_a || '');
                            if (rmSetB7) rmSetB7.value = String(sr.score_b || '');
                        }
                    });
                }
                applyScoringModeUI();
            })
            .catch(function () {
                setModalError('Network error while loading details.');
                rmMeta.textContent = '-';
            });
    }

    function renderRows(rows) {
        if (!rows || !rows.length) {
            setMsg('No matches for this round.');
            return;
        }

        function formatDateTimeDisplay(v) {
            var s = (v || '').toString().trim();
            if (!s) return '-';
            s = s.replace('T', ' ');
            var m = s.match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})/);
            if (!m) return s;
            var h24 = parseInt(m[4], 10);
            if (isNaN(h24)) return m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5];
            var ampm = h24 >= 12 ? 'PM' : 'AM';
            var h12 = h24 % 12;
            if (h12 === 0) h12 = 12;
            var hh = String(h12).padStart(2, '0');
            return m[3] + '/' + m[2] + '/' + m[1] + ' ' + hh + ':' + m[5] + ' ' + ampm;
        }

        var html = '';
        var isKnockoutView = rows.some(function (r) {
            return String(r.group_code || '').toLowerCase().indexOf('knockout') !== -1;
        });
        var sortedNos = rows
            .map(function (r) { return parseInt(r.match_no, 10) || 0; })
            .filter(function (n) { return n > 0; })
            .sort(function (a, b) { return b - a; });
        var lastTwoNos = sortedNos.slice(0, 2);

        rows.forEach(function (row) {
            var btnClass = (row.status === 'completed') ? 'btn btn-sm btn-outline-primary' : 'btn btn-sm btn-primary';
            var venueName = (row.venue_name || '').trim();
            var venueDetail = (row.venue_detail || '').trim();
            var venueText = '-';
            if (venueName && venueDetail) venueText = venueName + ' / ' + venueDetail;
            else if (venueName) venueText = venueName;
            else if (venueDetail) venueText = venueDetail;
            var matchNoNum = parseInt(row.match_no, 10) || 0;
            var highlightLastTwo = isKnockoutView && lastTwoNos.indexOf(matchNoNum) !== -1;
            var winnerA = (row.status === 'completed' && row.winner_side === 'a');
            var winnerB = (row.status === 'completed' && row.winner_side === 'b');
            var winnerCellStyle = ' style="background:#eaf7ef;color:#1f6f43;font-weight:600;"';
            var winnerBadgeStyle = ' style="display:inline-block;margin-left:6px;padding:2px 8px;border-radius:10px;background:#d7f0df;color:#1f6f43;font-size:11px;font-weight:700;border:1px solid #b7e1c3;"';
            var teamABadge = winnerA ? ('<span' + winnerBadgeStyle + '>WIN</span>') : '';
            var teamBBadge = winnerB ? ('<span' + winnerBadgeStyle + '>WIN</span>') : '';
            var hasSetResults = Number(row.has_set_results || 0) === 1;
            var infoBtn = hasSetResults
                ? (' <button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline set-info-btn" ' +
                   'data-match-id="' + encodeURIComponent(row.id) + '" title="View detail set" aria-label="View detail set">' +
                   '<i class="fas fa-info-circle text-info" style="font-size:14px;"></i></button>')
                : '';
            html += '<tr' + (highlightLastTwo ? ' class="bg-warning-subtle"' : '') + '>';
            html += '<td>' + esc(row.group_code || '-') + '</td>';
            html += '<td>' + esc(row.match_no) + '</td>';
            html += '<td' + (winnerA ? winnerCellStyle : '') + '>' + esc(row.team_a) + (teamABadge ? (' ' + teamABadge) : '') + '</td>';
            html += '<td' + (winnerB ? winnerCellStyle : '') + '>' + esc(row.team_b) + (teamBBadge ? (' ' + teamBBadge) : '') + '</td>';
            html += '<td>' + esc(venueText) + '</td>';
            if (row.result_text) {
                html += '<td>' + esc(row.result_text) + infoBtn + '</td>';
            } else {
                html += '<td><span class="badge bg-info text-dark">None</span>' + infoBtn + '</td>';
            }
            html += '<td>' + esc(formatDateTimeDisplay(row.tarikh || '')) + '</td>';
            html += '<td><span class="badge ' + (row.status === 'completed' ? 'bg-success' : 'bg-secondary') + '">' + esc(row.status) + '</span></td>';
            html += '<td><button type="button" class="' + btnClass + ' result-btn" data-match-id="' + encodeURIComponent(row.id) + '">' + esc(row.action_label) + '</button></td>';
            html += '</tr>';
        });
        bodyEl.innerHTML = html;
    }

    function loadMatches(roundId) {
        if (!roundId) {
            setMsg('Please select a round.');
            return;
        }
        setMsg('Loading data...');
        fetch('?action=list_matches&round_id=' + encodeURIComponent(roundId))
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.success) {
                    setMsg((j && j.message) ? j.message : 'Unable to load matches.');
                    return;
                }
                renderRows(j.rows || []);
                currentRoundMeta = j.meta || null;
                if (btnGenerateKnockout) {
                    var can = currentRoundMeta && Number(currentRoundMeta.can_generate_knockout || 0) === 1;
                    btnGenerateKnockout.classList.toggle('d-none', !can);
                }
            })
            .catch(function () {
                setMsg('Network error while attempting to load matches.');
            });
    }

    function loadRounds(sukanId, kategoriId) {
        roundEl.innerHTML = '<option value="">-- Please Select --</option>';
        roundEl.disabled = true;
        if (!sukanId) {
            setMsg('Please select a sport, category and round.');
            return;
        }
        if (!kategoriId) {
            setMsg('Please select a category and round.');
            return;
        }
        setMsg('Loading round...');
        fetch('?action=get_rounds&sukan_id=' + encodeURIComponent(sukanId) + '&kategori_id=' + encodeURIComponent(kategoriId))
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.success) {
                    setMsg((j && j.message) ? j.message : 'Unable to load rounds.');
                    return;
                }

                var rounds = j.rounds || [];
                if (!rounds.length) {
                    setMsg('No rounds for this sport/category.');
                    return;
                }

                rounds.forEach(function (round) {
                    var opt = document.createElement('option');
                    opt.value = round.id;
                    var gc = round.group_code ? (' - ' + round.group_code) : '';
                    opt.textContent = (round.nama_round || ('Round #' + round.id)) + gc;
                    if (preRound && parseInt(round.id, 10) === preRound) {
                        opt.selected = true;
                    }
                    roundEl.appendChild(opt);
                });
                roundEl.disabled = false;

                if (roundEl.value) {
                    loadMatches(roundEl.value);
                    preRound = 0;
                } else {
                    setMsg('Please select a round.');
                }
            })
            .catch(function () {
                setMsg('Network error while attempting to load rounds.');
            });
    }

    function loadCategories(sukanId) {
        categoryEl.innerHTML = '<option value="">-- Please Select --</option>';
        categoryEl.disabled = true;
        roundEl.innerHTML = '<option value="">-- Please Select --</option>';
        roundEl.disabled = true;

        if (!sukanId) {
            setMsg('Please select a sport, category and round.');
            return;
        }

        setMsg('Loading categories...');
        fetch('?action=get_categories&sukan_id=' + encodeURIComponent(sukanId))
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.success) {
                    setMsg((j && j.message) ? j.message : 'Unable to load categories.');
                    return;
                }

                var categories = j.categories || [];
                if (!categories.length) {
                    setMsg('No categories for this sport.');
                    return;
                }

                categories.forEach(function (cat) {
                    var opt = document.createElement('option');
                    opt.value = cat.id;
                    opt.textContent = englishSportLabel(cat.nama_kategori) || ('Category #' + cat.id);
                    if (preCategory && parseInt(cat.id, 10) === preCategory) {
                        opt.selected = true;
                    }
                    categoryEl.appendChild(opt);
                });
                categoryEl.disabled = false;

                if (categoryEl.value) {
                    loadRounds(sukanId, categoryEl.value);
                    preCategory = 0;
                } else {
                    setMsg('Please select a category and round.');
                }
            })
            .catch(function () {
                setMsg('Network error while attempting to load categories.');
            });
    }

    sportEl.addEventListener('change', function () {
        preCategory = 0;
        preRound = 0;
        loadCategories(this.value || '');
    });

    categoryEl.addEventListener('change', function () {
        preRound = 0;
        loadRounds(sportEl.value || '', this.value || '');
    });

    roundEl.addEventListener('change', function () {
        loadMatches(this.value || '');
    });

    bodyEl.addEventListener('click', function (evt) {
        var btn = evt.target.closest('.result-btn');
        if (!btn) return;
        var matchId = btn.getAttribute('data-match-id');
        openResultModal(matchId);
    });
    bodyEl.addEventListener('click', function (evt) {
        var btn = evt.target.closest('.set-info-btn');
        if (!btn) return;
        evt.preventDefault();
        var matchId = parseInt(btn.getAttribute('data-match-id') || '0', 10);
        if (matchId <= 0) return;
        if (setInfoMeta) setInfoMeta.textContent = 'Loading detail set...';
        if (setInfoSummary) setInfoSummary.innerHTML = '';
        if (setInfoBody) setInfoBody.textContent = '';
        if (!showSetInfoModal()) {
            alert('The set details dialog is not ready. Please refresh the page.');
            return;
        }

        fetch('?action=get_match_detail&match_id=' + encodeURIComponent(matchId))
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.success) {
                    if (setInfoMeta) setInfoMeta.textContent = '-';
                    if (setInfoBody) setInfoBody.textContent = (j && j.message) ? j.message : 'Unable to load set details.';
                    return;
                }
                var match = j.match || {};
                var parts = Array.isArray(j.participants) ? j.participants : [];
                var sets = Array.isArray(j.sets) ? j.sets : [];
                var nameA = (parts[0] && parts[0].nama_pasukan) ? parts[0].nama_pasukan : 'Team A';
                var nameB = (parts[1] && parts[1].nama_pasukan) ? parts[1].nama_pasukan : 'Team B';
                if (setInfoMeta) setInfoMeta.textContent = (match.nama_round || '-') + ' / Match ' + (match.match_no || '-');

                var scoreA = (parts[0] && parts[0].score != null) ? String(parts[0].score) : '';
                var scoreB = (parts[1] && parts[1].score != null) ? String(parts[1].score) : '';
                var finalResult = (scoreA !== '' && scoreB !== '') ? (scoreA + ' - ' + scoreB) : 'None';
                var tarikhText = '-';
                if (match.tarikh) {
                    var s = String(match.tarikh).replace('T', ' ');
                    var mm = s.match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})/);
                    if (mm) {
                        var h24 = parseInt(mm[4], 10);
                        var ampm = h24 >= 12 ? 'PM' : 'AM';
                        var h12 = h24 % 12;
                        if (h12 === 0) h12 = 12;
                        tarikhText = mm[3] + '/' + mm[2] + '/' + mm[1] + ' ' + String(h12).padStart(2, '0') + ':' + mm[5] + ' ' + ampm;
                    } else {
                        tarikhText = s;
                    }
                }
                if (setInfoSummary) {
                    var summaryHtml = '';
                    summaryHtml += '<div class="row g-2 mb-2">';
                    summaryHtml += '<div class="col-md-3"><span class="text-muted">Round:</span><div class="fw-semibold">' + esc(match.nama_round || '-') + '</div></div>';
                    summaryHtml += '<div class="col-md-2"><span class="text-muted">Group:</span><div class="fw-semibold">' + esc(match.group_code || '-') + '</div></div>';
                    summaryHtml += '<div class="col-md-2"><span class="text-muted">Match No:</span><div class="fw-semibold">' + esc(match.match_no || '-') + '</div></div>';
                    summaryHtml += '<div class="col-md-2"><span class="text-muted">Status:</span><div class="fw-semibold text-capitalize">' + esc(match.status || '-') + '</div></div>';
                    summaryHtml += '<div class="col-md-3"><span class="text-muted">Date:</span><div class="fw-semibold">' + esc(tarikhText) + '</div></div>';
                    summaryHtml += '</div>';
                    summaryHtml += '<div class="p-2 border rounded bg-light">';
                    summaryHtml += '<div class="fw-semibold mb-1">Match</div>';
                    summaryHtml += '<div>' + esc(nameA) + ' <span class="text-muted">vs</span> ' + esc(nameB) + '</div>';
                    summaryHtml += '<div class="mt-1"><span class="text-muted">Final Results:</span> <span class="fw-semibold">' + esc(finalResult) + '</span></div>';
                    summaryHtml += '</div>';
                    setInfoSummary.innerHTML = summaryHtml;
                }
                if (!sets.length) {
                    if (setInfoBody) setInfoBody.textContent = 'No set records for this match.';
                    return;
                }
                var html = '<div class="table-responsive"><table class="table table-sm table-bordered mb-0">';
                html += '<thead><tr><th style="width:16%;">Set</th><th style="width:30%;">' + esc(nameA) + '</th><th style="width:30%;">' + esc(nameB) + '</th><th style="width:24%;">Status</th></tr></thead><tbody>';
                var cumA = 0;
                var cumB = 0;
                sets.forEach(function (sr) {
                    var sa = parseInt(sr.score_a, 10);
                    var sb = parseInt(sr.score_b, 10);
                    if (!isNaN(sa) && !isNaN(sb)) {
                        if (sa > sb) cumA++;
                        else if (sb > sa) cumB++;
                    }
                    var statusSet = cumA + '-' + cumB;
                    html += '<tr><td>Set ' + esc(sr.set_no) + '</td><td>' + esc(sr.score_a) + '</td><td>' + esc(sr.score_b) + '</td><td class="fw-semibold">' + esc(statusSet) + '</td></tr>';
                });
                html += '</tbody></table></div>';
                if (setInfoBody) setInfoBody.innerHTML = html;
            })
            .catch(function () {
                if (setInfoMeta) setInfoMeta.textContent = '-';
                if (setInfoBody) setInfoBody.textContent = 'Network error while attempting to load set details.';
            });
    });

    if (rmSaveBtn) {
        rmSaveBtn.addEventListener('click', function () {
            setModalError('');
            var matchId = (rmMatchId.value || '').trim();
            var mpa = (rmMatchParticipantA.value || '').trim();
            var mpb = (rmMatchParticipantB.value || '').trim();
            var scoreA = (rmScoreA.value || '').trim();
            var scoreB = (rmScoreB.value || '').trim();
            var mode = (rmScoringMode && rmScoringMode.value) ? rmScoringMode.value : 'score';

            if (!matchId || !mpa || !mpb) {
                setModalError('Invalid match data.');
                return;
            }
            if (mode === 'score') {
                if (scoreA === '' || scoreB === '') {
                    setModalError('Please enter the scores for Team A and Team B.');
                    return;
                }
            } else {
                var pairs = getSetPairs();
                var cfg = modeToSetConfig(mode);
                var nonEmptyCount = 0;
                var invalid = false;
                pairs.forEach(function (p, idx) {
                    if (idx >= cfg.maxSets) return;
                    if (!p.a && !p.b) return;
                    nonEmptyCount++;
                    if (!/^\d+$/.test(p.a || '') || !/^\d+$/.test(p.b || '')) invalid = true;
                });
                if (invalid || nonEmptyCount < 2) {
                    setModalError('Please enter valid scores for at least two sets.');
                    return;
                }
            }

            setModalSaving(true);
            var fd = new FormData();
            fd.append('action', 'save_result');
            fd.append('match_id', matchId);
            fd.append('match_participant_a', mpa);
            fd.append('match_participant_b', mpb);
            fd.append('score_a', scoreA);
            fd.append('score_b', scoreB);
            fd.append('scoring_mode', mode);
            if (mode !== 'score') {
                var savePairs = getSetPairs();
                savePairs.forEach(function (p) {
                    fd.append('set_a[]', p.a || '');
                    fd.append('set_b[]', p.b || '');
                });
            }
            if (sportEl && sportEl.value) fd.append('sukan_id', String(sportEl.value));
            if (categoryEl && categoryEl.value) fd.append('kategori_id', String(categoryEl.value));

            fetch('', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j || !j.success) {
                        setModalError((j && j.message) ? j.message : 'Unable to save the result.');
                        setModalSaving(false);
                        return;
                    }
                    setModalSaving(false);
                    resultModal.hide();
                    if (roundEl && roundEl.value) {
                        loadMatches(roundEl.value);
                    }
                })
                .catch(function () {
                    setModalError('Network error while saving results.');
                    setModalSaving(false);
                });
        });
    }

    if (rmScoringMode) rmScoringMode.addEventListener('change', applyScoringModeUI);
    [rmScoreA, rmScoreB].forEach(bindNumericOnly);
    [
        rmSetA1, rmSetB1, rmSetA2, rmSetB2, rmSetA3, rmSetB3,
        rmSetA4, rmSetB4, rmSetA5, rmSetB5, rmSetA6, rmSetB6, rmSetA7, rmSetB7
    ].forEach(function (el) {
        if (!el) return;
        bindNumericOnly(el);
        el.addEventListener('input', renderSetSummary);
    });

    if (btnGenerateKnockout) {
        btnGenerateKnockout.addEventListener('click', function () {
            if (!roundEl || !roundEl.value) return;
            if (!currentRoundMeta) return;

            btnGenerateKnockout.disabled = true;
            var fd = new FormData();
            fd.append('action', 'generate_knockout');
            fd.append('group_round_id', String(roundEl.value));
            if (currentRoundMeta.knockout_round_id) {
                fd.append('knockout_round_id', String(currentRoundMeta.knockout_round_id));
            }

            fetch('', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    btnGenerateKnockout.disabled = false;
                    if (!j || !j.success) {
                        alert((j && j.message) ? j.message : 'Unable to generate the knockout stage.');
                        return;
                    }
                    loadMatches(roundEl.value);
                })
                .catch(function () {
                    btnGenerateKnockout.disabled = false;
                    alert('Network error while attempting to generate the knockout stage.');
                });
        });
    }

    if (sportEl.value) {
        loadCategories(sportEl.value);
    }
})();
</script>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>
