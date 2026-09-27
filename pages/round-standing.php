<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/rbac.php';

$leagueServiceCandidates = [
    __DIR__ . '/../../services/LeagueStandingService.php',
    __DIR__ . '/../services/LeagueStandingService.php',
];
foreach ($leagueServiceCandidates as $svcPath) {
    if (is_file($svcPath)) {
        require_once $svcPath;
        break;
    }
}

Session::start();
$auth = getAuth();
$auth->requireAuth();

$rbac = getRBAC();
$rbac->requirePageAccess('pages/round-standing.php');

$page_title = 'Round Standing';

function rs_has_column(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) return (bool)$cache[$key];

    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
    );
    $stmt->execute([':table_name' => $table, ':column_name' => $column]);
    $ok = ((int)$stmt->fetchColumn() > 0);
    $cache[$key] = $ok;
    return $ok;
}

function rs_pick_col(PDO $db, string $table, array $candidates): ?string
{
    foreach ($candidates as $col) {
        if (rs_has_column($db, $table, $col)) return $col;
    }
    return null;
}

function rs_round_source_sql(PDO $db, int $kategoriId = 0): string
{
    $hasRoundSukanId = rs_has_column($db, 'table_round', 'sukan_id');
    $hasRoundEventId = rs_has_column($db, 'table_round', 'event_id');
    $hasEventSukanId = rs_has_column($db, 'table_event', 'sukan_id');
    $hasEventKategoriId = rs_has_column($db, 'table_event', 'kategori_id');

    // Prefer event-scoped filtering when available to avoid cross-category leakage.
    if ($hasRoundEventId && $hasEventSukanId) {
        return "
            FROM table_round r
            INNER JOIN table_event e ON e.id = r.event_id
            WHERE r.deleted_at IS NULL
              AND e.deleted_at IS NULL
              AND e.sukan_id = :sukan_id
              " . (($kategoriId > 0 && $hasEventKategoriId) ? "AND e.kategori_id = :kategori_id" : "") . "
        ";
    }

    if ($hasRoundSukanId) {
        return "
            FROM table_round r
            WHERE r.deleted_at IS NULL
              AND r.sukan_id = :sukan_id
        ";
    }

    return "
        FROM table_round r
        WHERE r.deleted_at IS NULL
    ";
}

function rs_get_rounds_by_sukan(PDO $db, int $sukanId, int $kategoriId = 0): array
{
    if ($sukanId <= 0) return [];

    $orderParts = [];
    if (rs_has_column($db, 'table_round', 'round_order')) $orderParts[] = 'r.round_order ASC';
    if (rs_has_column($db, 'table_round', 'group_order')) $orderParts[] = 'r.group_order ASC';
    if (rs_has_column($db, 'table_round', 'group_code')) $orderParts[] = 'r.group_code ASC';
    $orderParts[] = 'r.id ASC';
    $orderSql = implode(', ', $orderParts);
    $eventIdSelect = rs_has_column($db, 'table_round', 'event_id') ? 'r.event_id' : 'NULL';
    $sukanIdSelect = rs_has_column($db, 'table_round', 'sukan_id') ? 'r.sukan_id' : 'NULL';

    $sql = "
        SELECT r.id, r.nama_round, COALESCE(r.group_code, '') AS group_code, r.round_type, r.status, r.qualification_rule, COALESCE(r.is_locked, 0) AS is_locked, COALESCE(r.round_order, 0) AS round_order,
               {$eventIdSelect} AS event_id, {$sukanIdSelect} AS sukan_id
        " . rs_round_source_sql($db, $kategoriId) . "
        ORDER BY {$orderSql}
    ";
    $stmt = $db->prepare($sql);
    $params = [':sukan_id' => $sukanId];
    if ($kategoriId > 0 && rs_has_column($db, 'table_round', 'event_id') && rs_has_column($db, 'table_event', 'kategori_id')) {
        $params[':kategori_id'] = $kategoriId;
    }
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = is_array($rows) ? $rows : [];

    // Fallback: include related knockout rounds by next round_order when legacy rows
    // don't carry sukan/event mapping consistently.
    $hasKnockout = false;
    $leagueOrders = [];
    foreach ($rows as $r) {
        $type = strtolower((string)($r['round_type'] ?? ''));
        if ($type === 'knockout') $hasKnockout = true;
        if ($type === 'league') {
            $ord = (int)($r['round_order'] ?? 0);
            if ($ord > 0) $leagueOrders[$ord + 1] = true;
        }
    }

    if (!$hasKnockout && !empty($leagueOrders)) {
        $nextOrders = array_keys($leagueOrders);
        $ph = implode(',', array_fill(0, count($nextOrders), '?'));
        $hasRoundEventId = rs_has_column($db, 'table_round', 'event_id');
        $hasEventSukanId = rs_has_column($db, 'table_event', 'sukan_id');
        $hasEventKategoriId = rs_has_column($db, 'table_event', 'kategori_id');
        $fallbackSql = "
            SELECT r.id, r.nama_round, COALESCE(r.group_code, '') AS group_code, r.round_type, r.status, r.qualification_rule, COALESCE(r.is_locked, 0) AS is_locked, COALESCE(r.round_order, 0) AS round_order,
                   {$eventIdSelect} AS event_id, {$sukanIdSelect} AS sukan_id
            FROM table_round r
        ";
        $params = $nextOrders;
        if ($hasRoundEventId && $hasEventSukanId) {
            $fallbackSql .= " INNER JOIN table_event e ON e.id = r.event_id ";
        }
        $fallbackSql .= "
            WHERE r.deleted_at IS NULL
              AND r.round_type = 'knockout'
              AND r.round_order IN ({$ph})
        ";
        if ($hasRoundEventId && $hasEventSukanId) {
            $fallbackSql .= " AND e.deleted_at IS NULL AND e.sukan_id = ? ";
            $params[] = $sukanId;
            if ($kategoriId > 0 && $hasEventKategoriId) {
                $fallbackSql .= " AND e.kategori_id = ? ";
                $params[] = $kategoriId;
            }
        } elseif (rs_has_column($db, 'table_round', 'sukan_id')) {
            $fallbackSql .= " AND r.sukan_id = ? ";
            $params[] = $sukanId;
        }
        $fallbackSql .= " ORDER BY r.round_order ASC, r.group_code ASC, r.id ASC ";
        $st2 = $db->prepare($fallbackSql);
        $st2->execute($params);
        $extra = $st2->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($extra) && !empty($extra)) {
            $seen = [];
            foreach ($rows as $x) $seen[(int)$x['id']] = true;
            foreach ($extra as $x) {
                $id = (int)($x['id'] ?? 0);
                if ($id > 0 && !isset($seen[$id])) {
                    $rows[] = $x;
                    $seen[$id] = true;
                }
            }
        }
    }

    return $rows;
}

function rs_extract_top_n(?string $rule): int
{
    $rule = trim((string)$rule);
    if ($rule === '') return 0;
    $decoded = json_decode($rule, true);
    if (!is_array($decoded) || !isset($decoded['top_n'])) return 0;
    $n = (int)$decoded['top_n'];
    return $n > 0 ? $n : 0;
}

function rs_find_next_knockout_round(PDO $db, array $leagueRound): ?array
{
    $leagueOrder = (int)($leagueRound['round_order'] ?? 0);
    if ($leagueOrder <= 0) return null;

    $baseSql = "SELECT id, round_order, status FROM table_round WHERE round_type = 'knockout' AND round_order = :next_order";
    $sql = $baseSql;
    $params = [':next_order' => $leagueOrder + 1];
    $hasScopedFilter = false;

    if (rs_has_column($db, 'table_round', 'event_id') && isset($leagueRound['event_id'])) {
        $sql .= " AND event_id = :event_id";
        $params[':event_id'] = (int)$leagueRound['event_id'];
        $hasScopedFilter = true;
    } elseif (rs_has_column($db, 'table_round', 'sukan_id') && isset($leagueRound['sukan_id'])) {
        $sql .= " AND sukan_id = :sukan_id";
        $params[':sukan_id'] = (int)$leagueRound['sukan_id'];
        $hasScopedFilter = true;
    }
    $sql .= " AND deleted_at IS NULL ORDER BY id ASC LIMIT 1";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) return $row;

    // Do not fallback to unscoped lookup; it can leak knockout rounds from other sports/categories.
    return null;
}

function rs_fetch_standing_for_rounds(PDO $db, array $roundRows, ?int $singleRoundId = null): array
{
    $participantCol = rs_pick_col($db, 'table_standing', ['participant_id', 'team_id', 'pasukan_id']);
    if ($participantCol === null) return ['rows' => [], 'top_n_map' => []];

    $positionCol = rs_pick_col($db, 'table_standing', ['position', 'ranking']);
    $lossCol = rs_pick_col($db, 'table_standing', ['loss', 'lose']);
    $gfCol = rs_pick_col($db, 'table_standing', ['goals_for', 'goal_for']);
    $gaCol = rs_pick_col($db, 'table_standing', ['goals_against', 'goal_against']);
    $gdCol = rs_pick_col($db, 'table_standing', ['goal_difference', 'goal_diff']);

    $selectParts = [];
    $selectParts[] = 's.round_id';
    $selectParts[] = ($positionCol ? "s.{$positionCol}" : "0") . ' AS position';
    $selectParts[] = 'p.nama_pasukan';
    $selectParts[] = (rs_has_column($db, 'table_standing', 'played') ? 's.played' : '0') . ' AS played';
    $selectParts[] = (rs_has_column($db, 'table_standing', 'win') ? 's.win' : '0') . ' AS win';
    $selectParts[] = (rs_has_column($db, 'table_standing', 'draw') ? 's.draw' : '0') . ' AS draw';
    $selectParts[] = ($lossCol ? "s.{$lossCol}" : '0') . ' AS loss';
    $selectParts[] = ($gfCol ? "s.{$gfCol}" : '0') . ' AS goals_for';
    $selectParts[] = ($gaCol ? "s.{$gaCol}" : '0') . ' AS goals_against';
    $selectParts[] = ($gdCol ? "s.{$gdCol}" : '0') . ' AS goal_difference';
    $selectParts[] = (rs_has_column($db, 'table_standing', 'points') ? 's.points' : '0') . ' AS points';
    $selectParts[] = 'r.nama_round';
    $selectParts[] = 'r.round_type';
    $selectParts[] = "COALESCE(r.group_code, '') AS group_code";

    $orderBy = $positionCol ? "CAST(s.{$positionCol} AS UNSIGNED) ASC" : 's.points DESC, p.nama_pasukan ASC';

    if ($singleRoundId !== null) {
        $sql = "
            SELECT " . implode(', ', $selectParts) . "
            FROM table_standing s
            INNER JOIN table_pasukan p ON p.id = s.{$participantCol}
            INNER JOIN table_round r ON r.id = s.round_id
            WHERE s.round_id = :round_id
            ORDER BY {$orderBy}
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([':round_id' => $singleRoundId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        if (empty($roundRows)) return ['rows' => [], 'top_n_map' => []];
        $ids = array_map(static fn($r) => (int)$r['id'], $roundRows);
        $ids = array_values(array_filter($ids, static fn($v) => $v > 0));
        if (empty($ids)) return ['rows' => [], 'top_n_map' => []];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "
            SELECT " . implode(', ', $selectParts) . "
            FROM table_standing s
            INNER JOIN table_pasukan p ON p.id = s.{$participantCol}
            INNER JOIN table_round r ON r.id = s.round_id
            WHERE s.round_id IN ({$placeholders})
            ORDER BY COALESCE(r.group_code, '') ASC, {$orderBy}
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $rows = is_array($rows) ? $rows : [];
    $topNMap = [];
    foreach ($roundRows as $rr) {
        $topNMap[(int)$rr['id']] = rs_extract_top_n((string)($rr['qualification_rule'] ?? ''));
    }

    return ['rows' => $rows, 'top_n_map' => $topNMap];
}

function rs_fetch_knockout_rows(PDO $db, int $roundId, string $groupCode = ''): array
{
    if ($roundId <= 0) return [];

    $matchDeleted = rs_has_column($db, 'table_match', 'deleted_at');
    $sqlM = 'SELECT id FROM table_match WHERE round_id = :rid';
    if ($matchDeleted) $sqlM .= ' AND deleted_at IS NULL';
    $sqlM .= ' ORDER BY match_no ASC, id ASC';
    $mSt = $db->prepare($sqlM);
    $mSt->execute([':rid' => $roundId]);
    $matchRows = $mSt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($matchRows) || empty($matchRows)) return [];

    $participantCol = rs_pick_col($db, 'table_match_participant', ['participant_id', 'team_id', 'pasukan_id']) ?: 'participant_id';
    $mpDeleted = rs_has_column($db, 'table_match_participant', 'deleted_at');
    $sqlP = "
        SELECT mp.id AS mpid, mp.{$participantCol} AS participant_id, p.nama_pasukan
        FROM table_match_participant mp
        INNER JOIN table_pasukan p ON p.id = mp.{$participantCol}
        WHERE mp.match_id = :mid
    ";
    if ($mpDeleted) $sqlP .= ' AND mp.deleted_at IS NULL';
    $sqlP .= ' ORDER BY mp.id ASC';
    $pSt = $db->prepare($sqlP);

    $sSt = $db->prepare('SELECT score FROM table_match_result WHERE match_participant_id = :mpid LIMIT 1');

    $stats = [];
    foreach ($matchRows as $m) {
        $mid = (int)($m['id'] ?? 0);
        if ($mid <= 0) continue;

        $pSt->execute([':mid' => $mid]);
        $ps = $pSt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($ps) || count($ps) < 2) continue;

        $a = $ps[0];
        $b = $ps[1];
        $aid = (int)($a['participant_id'] ?? 0);
        $bid = (int)($b['participant_id'] ?? 0);
        if ($aid <= 0 || $bid <= 0) continue;

        if (!isset($stats[$aid])) {
            $stats[$aid] = [
                'participant_id' => $aid,
                'nama_pasukan' => (string)($a['nama_pasukan'] ?? '-'),
                'played' => 0, 'win' => 0, 'draw' => 0, 'loss' => 0,
                'goals_for' => 0, 'goals_against' => 0, 'goal_difference' => 0, 'points' => 0,
            ];
        }
        if (!isset($stats[$bid])) {
            $stats[$bid] = [
                'participant_id' => $bid,
                'nama_pasukan' => (string)($b['nama_pasukan'] ?? '-'),
                'played' => 0, 'win' => 0, 'draw' => 0, 'loss' => 0,
                'goals_for' => 0, 'goals_against' => 0, 'goal_difference' => 0, 'points' => 0,
            ];
        }

        $sSt->execute([':mpid' => (int)$a['mpid']]);
        $sa = $sSt->fetchColumn();
        $sSt->execute([':mpid' => (int)$b['mpid']]);
        $sb = $sSt->fetchColumn();
        if ($sa === false || $sa === null || $sa === '' || $sb === false || $sb === null || $sb === '') {
            continue;
        }
        if (!is_numeric($sa) || !is_numeric($sb)) continue;

        $na = (float)$sa;
        $nb = (float)$sb;
        $stats[$aid]['played']++;
        $stats[$bid]['played']++;
        $stats[$aid]['goals_for'] += $na;
        $stats[$aid]['goals_against'] += $nb;
        $stats[$bid]['goals_for'] += $nb;
        $stats[$bid]['goals_against'] += $na;

        if ($na > $nb) {
            $stats[$aid]['win']++;
            $stats[$aid]['points'] += 3;
            $stats[$bid]['loss']++;
        } elseif ($na < $nb) {
            $stats[$bid]['win']++;
            $stats[$bid]['points'] += 3;
            $stats[$aid]['loss']++;
        } else {
            $stats[$aid]['draw']++;
            $stats[$bid]['draw']++;
            $stats[$aid]['points'] += 1;
            $stats[$bid]['points'] += 1;
        }
    }

    if (empty($stats)) return [];
    foreach ($stats as &$s) {
        $s['goal_difference'] = (float)$s['goals_for'] - (float)$s['goals_against'];
    }
    unset($s);

    $rows = array_values($stats);
    usort($rows, static function ($a, $b) {
        if ((float)$b['points'] !== (float)$a['points']) return ((float)$b['points'] <=> (float)$a['points']);
        if ((float)$b['goal_difference'] !== (float)$a['goal_difference']) return ((float)$b['goal_difference'] <=> (float)$a['goal_difference']);
        if ((float)$b['goals_for'] !== (float)$a['goals_for']) return ((float)$b['goals_for'] <=> (float)$a['goals_for']);
        return strcmp((string)$a['nama_pasukan'], (string)$b['nama_pasukan']);
    });

    $gc = trim($groupCode);
    if ($gc === '' || $gc === '-') $gc = 'Knockout Stage';
    foreach ($rows as $idx => &$r) {
        $r['position'] = $idx + 1;
        $r['round_id'] = $roundId;
        $r['group_code'] = $gc;
        $r['round_type'] = 'knockout';
    }
    unset($r);

    return $rows;
}

function rs_is_league_round_ready(PDO $db, int $roundId): bool
{
    if ($roundId <= 0) return false;

    $sqlMatch = 'SELECT id FROM table_match WHERE round_id = :rid';
    if (rs_has_column($db, 'table_match', 'deleted_at')) {
        $sqlMatch .= ' AND deleted_at IS NULL';
    }
    $sqlMatch .= ' ORDER BY id ASC';
    $mSt = $db->prepare($sqlMatch);
    $mSt->execute([':rid' => $roundId]);
    $matches = $mSt->fetchAll(PDO::FETCH_COLUMN);
    if (!is_array($matches) || empty($matches)) return false;

    $participantCol = rs_pick_col($db, 'table_match_participant', ['participant_id', 'team_id', 'pasukan_id']) ?: 'participant_id';
    $pSql = "SELECT id, {$participantCol} AS participant_id FROM table_match_participant WHERE match_id = :mid";
    if (rs_has_column($db, 'table_match_participant', 'deleted_at')) {
        $pSql .= ' AND deleted_at IS NULL';
    }
    $pSql .= ' ORDER BY id ASC';
    $pSt = $db->prepare($pSql);
    $sSt = $db->prepare('SELECT score FROM table_match_result WHERE match_participant_id = :mpid LIMIT 1');

    foreach ($matches as $midRaw) {
        $mid = (int)$midRaw;
        if ($mid <= 0) return false;

        $pSt->execute([':mid' => $mid]);
        $parts = $pSt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($parts) || count($parts) < 2) return false;

        for ($i = 0; $i < 2; $i++) {
            $mpid = (int)($parts[$i]['id'] ?? 0);
            $pid = (int)($parts[$i]['participant_id'] ?? 0);
            if ($mpid <= 0 || $pid <= 0) return false;

            $sSt->execute([':mpid' => $mpid]);
            $score = $sSt->fetchColumn();
            if ($score === false || $score === null || $score === '' || !is_numeric((string)$score)) {
                return false;
            }
        }
    }

    return true;
}

function rs_round_has_matches(PDO $db, int $roundId): bool
{
    if ($roundId <= 0) return false;
    $sql = 'SELECT COUNT(*) FROM table_match WHERE round_id = :rid';
    if (rs_has_column($db, 'table_match', 'deleted_at')) {
        $sql .= ' AND deleted_at IS NULL';
    }
    $st = $db->prepare($sql);
    $st->execute([':rid' => $roundId]);
    return ((int)$st->fetchColumn() > 0);
}

$db = getDB();

if (isset($_GET['action']) && $_GET['action'] === 'get_rounds') {
    header('Content-Type: application/json; charset=utf-8');
    $sukanId = isset($_GET['sukan_id']) ? (int)$_GET['sukan_id'] : 0;
    $kategoriId = isset($_GET['kategori_id']) ? (int)$_GET['kategori_id'] : 0;
    if ($sukanId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid sport.']);
        exit;
    }
    if ($kategoriId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid category.']);
        exit;
    }

    try {
        $rows = rs_get_rounds_by_sukan($db, $sukanId, $kategoriId);
        echo json_encode(['success' => true, 'rounds' => $rows]);
    } catch (Throwable $e) {
        error_log('[round-standing:get_rounds] ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Unable to load rounds.']);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_categories') {
    header('Content-Type: application/json; charset=utf-8');
    $sukanId = isset($_GET['sukan_id']) ? (int)$_GET['sukan_id'] : 0;
    if ($sukanId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid sport.']);
        exit;
    }

    try {
        $statusClause = rs_has_column($db, 'table_kategori', 'status') ? ' AND status = 1' : '';
        $deletedClause = rs_has_column($db, 'table_kategori', 'deleted_at') ? ' AND deleted_at IS NULL' : '';
        $stmt = $db->prepare("
            SELECT id, nama_kategori
            FROM table_kategori
            WHERE sukan_id = :sukan_id {$statusClause} {$deletedClause}
            ORDER BY nama_kategori ASC, id ASC
        ");
        $stmt->execute([':sukan_id' => $sukanId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'categories' => (is_array($rows) ? $rows : [])]);
    } catch (Throwable $e) {
        error_log('[round-standing:get_categories] ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Unable to load categories.']);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_standings') {
    header('Content-Type: application/json; charset=utf-8');
    $sukanId = isset($_GET['sukan_id']) ? (int)$_GET['sukan_id'] : 0;
    $kategoriId = isset($_GET['kategori_id']) ? (int)$_GET['kategori_id'] : 0;
    $roundParam = isset($_GET['round_id']) ? (string)$_GET['round_id'] : '';

    if ($sukanId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please select a sport.']);
        exit;
    }
    if ($kategoriId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please select a category.']);
        exit;
    }

    try {
        $rounds = rs_get_rounds_by_sukan($db, $sukanId, $kategoriId);
        if (empty($rounds)) {
            echo json_encode(['success' => true, 'meta' => ['mode' => 'empty_round'], 'rows' => []]);
            exit;
        }

        $roundMap = [];
        foreach ($rounds as $r) $roundMap[(int)$r['id']] = $r;

        $singleRoundId = null;
        $selectedRound = null;

        if ($roundParam !== '' && strtolower($roundParam) !== 'all') {
            $rid = (int)$roundParam;
            if (isset($roundMap[$rid])) {
                $singleRoundId = $rid;
                $selectedRound = $roundMap[$rid];
            } else {
                $singleRoundId = (int)$rounds[0]['id'];
                $selectedRound = $rounds[0];
            }
        }

        if ($singleRoundId !== null && $selectedRound) {
            $isLeague = strtolower((string)($selectedRound['round_type'] ?? '')) === 'league';
            if ($isLeague && class_exists('LeagueStandingService')) {
                $tmp = rs_fetch_standing_for_rounds($db, $rounds, $singleRoundId);
                if (empty($tmp['rows'])) {
                    LeagueStandingService::generate($singleRoundId, $db);
                }
            }
        } else {
            if (class_exists('LeagueStandingService')) {
                foreach ($rounds as $rr) {
                    if (strtolower((string)($rr['round_type'] ?? '')) === 'league') {
                        $rid = (int)$rr['id'];
                        $tmp = rs_fetch_standing_for_rounds($db, $rounds, $rid);
                        if (empty($tmp['rows'])) {
                            LeagueStandingService::generate($rid, $db);
                        }
                    }
                }
            }
        }

        $fetched = rs_fetch_standing_for_rounds($db, $rounds, $singleRoundId);
        $rows = $fetched['rows'];
        $topNMap = $fetched['top_n_map'];
        if ($singleRoundId !== null && $selectedRound && strtolower((string)($selectedRound['round_type'] ?? '')) === 'knockout' && empty($rows)) {
            $rows = rs_fetch_knockout_rows($db, $singleRoundId, (string)($selectedRound['group_code'] ?? ''));
        }

        $meta = [
            'mode' => $singleRoundId !== null ? 'single' : 'all',
            'selected_round_id' => $singleRoundId,
            'selected_round' => $selectedRound,
            'top_n_map' => $topNMap,
        ];
        if ($singleRoundId !== null && $selectedRound) {
            $roundType = strtolower((string)($selectedRound['round_type'] ?? ''));
            $isLeague = $roundType === 'league';
            $isKnockout = $roundType === 'knockout';
            $isLocked = (int)($selectedRound['is_locked'] ?? 0) === 1;
            $isLeagueReady = $isLeague ? rs_is_league_round_ready($db, (int)$selectedRound['id']) : false;
            $canGenerate = ($isLeague && $isLeagueReady && !$isLocked);
            $nextKnockout = $isLeague ? rs_find_next_knockout_round($db, $selectedRound) : null;
            // Generate button follows league readiness by actual match results + unlocked.
            $meta['can_generate_knockout'] = $canGenerate ? 1 : 0;
            if ($isKnockout) {
                $hasMatches = rs_round_has_matches($db, (int)$selectedRound['id']);
                $meta['next_knockout_round_id'] = (int)$selectedRound['id'];
                $meta['next_knockout_round_status'] = (string)($selectedRound['status'] ?? '');
                $meta['next_knockout_has_matches'] = $hasMatches ? 1 : 0;
                $meta['show_bracket'] = $hasMatches ? 1 : 0;
            } else {
                $nextKoHasMatches = $nextKnockout ? rs_round_has_matches($db, (int)$nextKnockout['id']) : false;
                $meta['next_knockout_round_id'] = $nextKnockout ? (int)$nextKnockout['id'] : 0;
                $meta['next_knockout_round_status'] = $nextKnockout ? (string)($nextKnockout['status'] ?? '') : '';
                $meta['next_knockout_has_matches'] = $nextKoHasMatches ? 1 : 0;
                $meta['show_bracket'] = 0;
            }
        } else {
            $leagueRounds = array_values(array_filter($rounds, static function ($r) {
                return strtolower((string)($r['round_type'] ?? '')) === 'league';
            }));
            $repLeague = !empty($leagueRounds) ? $leagueRounds[0] : null;
            $allReady = !empty($leagueRounds);
            $allUnlocked = !empty($leagueRounds);
            foreach ($leagueRounds as $lr) {
                if (!rs_is_league_round_ready($db, (int)$lr['id'])) $allReady = false;
                if ((int)($lr['is_locked'] ?? 0) === 1) $allUnlocked = false;
            }

            $nextKnockout = ($repLeague && strtolower((string)($repLeague['round_type'] ?? '')) === 'league')
                ? rs_find_next_knockout_round($db, $repLeague)
                : null;
            $nextKoHasMatches = $nextKnockout ? rs_round_has_matches($db, (int)$nextKnockout['id']) : false;

            $meta['can_generate_knockout'] = ($allReady && $allUnlocked && $repLeague) ? 1 : 0;
            // In "all" mode, use first league round id as generation anchor.
            $meta['selected_round_id'] = $repLeague ? (int)$repLeague['id'] : 0;
            $meta['next_knockout_round_id'] = $nextKnockout ? (int)$nextKnockout['id'] : 0;
            $meta['next_knockout_round_status'] = $nextKnockout ? (string)($nextKnockout['status'] ?? '') : '';
            $meta['next_knockout_has_matches'] = $nextKoHasMatches ? 1 : 0;
            $meta['show_bracket'] = 0;
        }

        echo json_encode(['success' => true, 'meta' => $meta, 'rows' => $rows]);
    } catch (Throwable $e) {
        error_log('[round-standing:get_standings] ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Unable to load standings.']);
    }
    exit;
}

// Initial page render (AJAX-driven).
$sports = [];
$sportsErr = '';
try {
    $statusClause = rs_has_column($db, 'table_sukan', 'status') ? ' AND status = 1' : '';
    $sportsStmt = $db->query("
        SELECT id, nama_sukan
        FROM table_sukan
        WHERE deleted_at IS NULL {$statusClause}
        ORDER BY nama_sukan ASC
    ");
    $sports = $sportsStmt ? $sportsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $sportsErr = 'Unable to load the sports list.';
}

ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/round-standing.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/round-standing.css'); ?>">
<div class="w-100 px-3 standings-view">
    <div class="row mb-3">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div>
                        <div class="standings-eyebrow">SAM 2026 &middot; COMPETITION STANDINGS</div><h4 class="mb-1">Round Standing</h4>
                        <p class="text-muted mb-0">Standings for all league and group rounds.</p>
                    </div>
                    <fieldset class="border rounded p-3 mt-3">
                        <legend class="float-none w-auto px-2 fs-6 fw-semibold mb-0">Filters & Actions</legend>
                        <div class="d-flex align-items-center gap-2 justify-content-start flex-nowrap">
                            <div class="standing-filter"><label for="sukan_id">Sport</label><select id="sukan_id" class="form-select" style="width:180px;height:38px;">
                                <option value="">-- Sport --</option>
                                <?php foreach ($sports as $s): ?>
                                    <option value="<?php echo (int)$s['id']; ?>">
                                        <?php echo htmlspecialchars(english_sport_label((string)$s['nama_sukan']), ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select></div>

                            <div class="standing-filter"><label for="kategori_id">Category</label><select id="kategori_id" class="form-select" style="width:360px;height:38px;" disabled>
                                <option value="">-- Category --</option>
                            </select></div>

                            <div class="standing-filter"><label for="round_id">Round</label><select id="round_id" class="form-select" style="width:300px;height:38px;" disabled>
                                <option value="">-- Round --</option>
                            </select></div>

                            <div id="generateWrap" class="d-none" style="display:flex;align-items:center;flex:0 0 auto;">
                                <form id="generateForm" method="POST" action="<?php echo htmlspecialchars(url('pages/generate-knockout.php'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline-flex align-items-center gap-2 flex-nowrap m-0">
                                    <input type="hidden" name="round_id" id="generateRoundId" value="">
                                    <select name="knockout_preset" id="generatePreset" class="form-select" style="width:340px;height:38px;">
                                        <option value="auto">Preset: Auto</option>
                                        <option value="badminton_4group_qf">Badminton 4 Group (QF/SF/3rd/Final)</option>
                                        <option value="single_group_top4_sf_3rd_final">Single Group Top 4 (SF/3rd/Final)</option>
                                    </select>
                                    <button class="btn btn-primary text-nowrap" style="height:38px;">Generate Knockout Stage</button>
                                </form>
                            </div>
                            <div id="koGenerateWrap" class="d-none" style="display:flex;align-items:center;flex:0 0 auto;">
                                <button id="koGenerateBtn" class="btn btn-primary text-nowrap" style="height:38px;">Generate Knockout Schedule</button>
                            </div>
                            <div id="bracketWrap" class="d-none" style="display:flex;align-items:center;flex:0 0 auto;">
                                <a id="bracketBtn" href="<?php echo htmlspecialchars(url('pages/bracket.php'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-success text-nowrap">
                                    View Bracket
                                </a>
                            </div>
                        </div>
                    </fieldset>
                </div>
            </div>
        </div>
    </div>

    <?php if ($sportsErr !== ''): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($sportsErr, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['flash_msg']) && (string)$_GET['flash_msg'] !== ''): ?>
        <?php $ft = (string)($_GET['flash_type'] ?? 'info'); ?>
        <div class="alert alert-<?php echo htmlspecialchars($ft, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars((string)$_GET['flash_msg'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div id="standingMsg" class="alert alert-info">Please select a sport and category to load rounds.</div>

    <div id="roundInfoCard" class="row mb-3 d-none">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="small text-muted">Round Name</div>
                            <div id="riRoundName" class="fw-semibold">-</div>
                        </div>
                        <div class="col-md-4">
                            <div class="small text-muted">Group Code</div>
                            <div id="riGroupCode" class="fw-semibold">-</div>
                        </div>
                        <div class="col-md-4">
                            <div class="small text-muted">Round Status</div>
                            <span id="riRoundStatus" class="badge bg-secondary">pending</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="table-responsive">
                            <table class="table table-striped table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th class="text-center">Pos</th>
                                        <th class="text-start">Team</th>
                                        <th class="text-center">Played</th>
                                        <th class="text-center">Win</th>
                                        <th class="text-center">Draw</th>
                                        <th class="text-center">Loss</th>
                                        <th class="text-center">Goals For</th>
                                        <th class="text-center">Goals Against</th>
                                        <th class="text-center">Goal Difference</th>
                                        <th class="text-center">Points</th>
                                    </tr>
                                </thead>
                            <tbody id="standingBody">
                                <tr>
                                    <td colspan="10" class="text-muted text-center py-4">No data.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
(function () {
    var RS_DEBUG = true;
    function dbg() {
        if (!RS_DEBUG || !window.console || !console.log) return;
        try {
            var args = Array.prototype.slice.call(arguments);
            args.unshift('[ROUND-STANDING-DEBUG]');
            console.log.apply(console, args);
        } catch (e) {}
    }

    function ensureSwal() {
        return new Promise(function (resolve, reject) {
            if (window.Swal && typeof window.Swal.fire === 'function') {
                resolve(window.Swal);
                return;
            }
            var existing = document.querySelector('script[data-swal2-loader="1"]');
            if (existing) {
                existing.addEventListener('load', function () {
                    if (window.Swal && typeof window.Swal.fire === 'function') resolve(window.Swal);
                    else reject(new Error('Unable to load SweetAlert2'));
                });
                existing.addEventListener('error', function () { reject(new Error('Unable to load SweetAlert2')); });
                return;
            }
            var s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';
            s.async = true;
            s.defer = true;
            s.setAttribute('data-swal2-loader', '1');
            s.onload = function () {
                if (window.Swal && typeof window.Swal.fire === 'function') resolve(window.Swal);
                else reject(new Error('Unable to load SweetAlert2'));
            };
            s.onerror = function () { reject(new Error('Unable to load SweetAlert2')); };
            document.head.appendChild(s);
        });
    }

    async function confirmWithSwal(title, text, confirmText) {
        try {
            var Swal = await ensureSwal();
            var res = await Swal.fire({
                icon: 'question',
                title: title,
                text: text,
                showCancelButton: true,
                confirmButtonText: confirmText || 'Yes, teruskan',
                cancelButtonText: 'Cancel'
            });
            return !!(res && res.isConfirmed);
        } catch (e) {
            dbg('confirmWithSwal:error', e);
            return false;
        }
    }

    var sukanEl = document.getElementById('sukan_id');
    var kategoriEl = document.getElementById('kategori_id');
    var roundEl = document.getElementById('round_id');
    var msgEl = document.getElementById('standingMsg');
    var bodyEl = document.getElementById('standingBody');
    var infoCardEl = document.getElementById('roundInfoCard');
    var infoRoundName = document.getElementById('riRoundName');
    var infoGroupCode = document.getElementById('riGroupCode');
    var infoRoundStatus = document.getElementById('riRoundStatus');
    var generateWrap = document.getElementById('generateWrap');
    var generateForm = document.getElementById('generateForm');
    var generateRoundId = document.getElementById('generateRoundId');
    var generatePresetEl = document.getElementById('generatePreset');
    var koGenerateWrap = document.getElementById('koGenerateWrap');
    var koGenerateBtn = document.getElementById('koGenerateBtn');
    var bracketWrap = document.getElementById('bracketWrap');
    var bracketBtn = document.getElementById('bracketBtn');
    var bracketStatusBadge = document.getElementById('bracketStatusBadge');
    var roundCache = [];
    var currentMeta = null;

    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/\"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setMessage(type, text) {
        msgEl.className = 'alert alert-' + type;
        msgEl.textContent = text;
        msgEl.classList.remove('d-none');
    }

    function clearMessage() {
        msgEl.classList.add('d-none');
        msgEl.textContent = '';
    }

    function statusBadgeClass(status) {
        status = (status || '').toLowerCase();
        if (status === 'completed') return 'bg-success';
        if (status === 'ongoing') return 'bg-warning text-dark';
        return 'bg-secondary';
    }

    function setInfoCard(meta) {
        if (!meta || meta.mode !== 'single' || !meta.selected_round) {
            infoCardEl.classList.add('d-none');
            return;
        }
        var r = meta.selected_round;
        var gc = (r.group_code || '').toString().trim();
        var rt = (r.round_type || '').toString().toLowerCase();
        if ((gc === '' || gc === '-') && rt === 'knockout') gc = 'Knockout Stage';
        infoRoundName.textContent = r.nama_round || '-';
        infoGroupCode.textContent = (gc || '-');
        infoRoundStatus.className = 'badge ' + statusBadgeClass(r.status || 'pending');
        infoRoundStatus.textContent = (r.status || 'pending');
        infoCardEl.classList.remove('d-none');
    }

    function renderRows(rows, meta) {
        if (!rows || !rows.length) {
            bodyEl.innerHTML = '<tr><td colspan="10" class="text-muted text-center py-4">Standings have not been generated.</td></tr>';
            return;
        }

        if (meta && meta.mode === 'all') {
            var grouped = {};
            rows.forEach(function (row) {
                var g = (row.group_code || '-').toString().trim() || '-';
                if (!grouped[g]) grouped[g] = [];
                grouped[g].push(row);
            });
            var groups = Object.keys(grouped).sort(function (a, b) {
                return a.localeCompare(b, undefined, { sensitivity: 'base' });
            });

            var topNMapAll = (meta && meta.top_n_map) ? meta.top_n_map : {};
            var allHtml = '';
            groups.forEach(function (g) {
                allHtml += '<tr class="table-secondary"><td colspan="10" class="fw-semibold">Group ' + esc(g) + '</td></tr>';
                grouped[g].forEach(function (row) {
                    var rid = Number(row.round_id || 0);
                    var pos = Number(row.position || 0);
                    var topN = Number(topNMapAll[rid] || 0);
                    var qualified = (topN > 0 && pos > 0 && pos <= topN);
                    var isKnockoutRow = (String(row.round_type || '').toLowerCase() === 'knockout') || (String(row.group_code || '').toLowerCase() === 'knockout stage');
                    var gfCell = isKnockoutRow ? '<span class="badge bg-danger-subtle text-danger">None</span>' : esc(row.goals_for || 0);
                    var gaCell = isKnockoutRow ? '<span class="badge bg-danger-subtle text-danger">None</span>' : esc(row.goals_against || 0);
                    var gdCell = isKnockoutRow ? '<span class="badge bg-danger-subtle text-danger">None</span>' : esc(row.goal_difference || 0);
                    var ptsCell = isKnockoutRow ? '<span class="badge bg-danger-subtle text-danger">None</span>' : esc(row.points || 0);

                    allHtml += '<tr' + (qualified ? ' class="table-success"' : '') + '>';
                    allHtml += '<td class="text-center">' + esc(pos) + '</td>';
                    allHtml += '<td class="text-start">' + esc(row.nama_pasukan || '-') + '</td>';
                    allHtml += '<td class="text-center">' + esc(row.played || 0) + '</td>';
                    allHtml += '<td class="text-center">' + esc(row.win || 0) + '</td>';
                    allHtml += '<td class="text-center">' + esc(row.draw || 0) + '</td>';
                    allHtml += '<td class="text-center">' + esc(row.loss || 0) + '</td>';
                    allHtml += '<td class="text-center">' + gfCell + '</td>';
                    allHtml += '<td class="text-center">' + gaCell + '</td>';
                    allHtml += '<td class="text-center">' + gdCell + '</td>';
                    allHtml += '<td class="text-center">' + ptsCell + '</td>';
                    allHtml += '</tr>';
                });
            });
            bodyEl.innerHTML = allHtml;
            return;
        }

        var topNMap = (meta && meta.top_n_map) ? meta.top_n_map : {};
        var html = '';
        rows.forEach(function (row) {
            var rid = Number(row.round_id || 0);
            var pos = Number(row.position || 0);
            var topN = Number(topNMap[rid] || 0);
            var qualified = (topN > 0 && pos > 0 && pos <= topN);
            var isKnockoutRow = (String(row.round_type || '').toLowerCase() === 'knockout') || (String(row.group_code || '').toLowerCase() === 'knockout stage');
            var gfCell = isKnockoutRow ? '<span class="badge bg-danger-subtle text-danger">None</span>' : esc(row.goals_for || 0);
            var gaCell = isKnockoutRow ? '<span class="badge bg-danger-subtle text-danger">None</span>' : esc(row.goals_against || 0);
            var gdCell = isKnockoutRow ? '<span class="badge bg-danger-subtle text-danger">None</span>' : esc(row.goal_difference || 0);
            var ptsCell = isKnockoutRow ? '<span class="badge bg-danger-subtle text-danger">None</span>' : esc(row.points || 0);

            html += '<tr' + (qualified ? ' class="table-success"' : '') + '>';
            html += '<td class="text-center">' + esc(pos) + '</td>';
            html += '<td class="text-start">' + esc(row.nama_pasukan || '-') + '</td>';
            html += '<td class="text-center">' + esc(row.played || 0) + '</td>';
            html += '<td class="text-center">' + esc(row.win || 0) + '</td>';
            html += '<td class="text-center">' + esc(row.draw || 0) + '</td>';
            html += '<td class="text-center">' + esc(row.loss || 0) + '</td>';
            html += '<td class="text-center">' + gfCell + '</td>';
            html += '<td class="text-center">' + gaCell + '</td>';
            html += '<td class="text-center">' + gdCell + '</td>';
            html += '<td class="text-center">' + ptsCell + '</td>';
            html += '</tr>';
        });
        bodyEl.innerHTML = html;
    }

    function getRoundById(roundId) {
        var target = String(roundId || '').trim();
        if (!target) return null;
        for (var i = 0; i < roundCache.length; i++) {
            if (String(roundCache[i].id) === target) return roundCache[i];
        }
        return null;
    }

    function getFirstKnockoutRound() {
        for (var i = 0; i < roundCache.length; i++) {
            if (String(roundCache[i].round_type || '').toLowerCase() === 'knockout') {
                return roundCache[i];
            }
        }
        return null;
    }

    function setBracketButtonVisibility(show, roundId, statusText) {
        if (!bracketWrap || !bracketBtn) return;
        var rid = Number(roundId || 0);
        var visible = !!show && rid > 0;
        dbg('setBracketButtonVisibility', { show: !!show, rid: rid, statusText: statusText, visible: visible });
        bracketWrap.classList.toggle('d-none', !visible);
        if (!visible) {
            bracketBtn.href = '<?php echo htmlspecialchars(url('pages/bracket.php'), ENT_QUOTES, 'UTF-8'); ?>';
            if (bracketStatusBadge) bracketStatusBadge.classList.add('d-none');
            return;
        }
        bracketBtn.href = '<?php echo htmlspecialchars(url('pages/bracket.php'), ENT_QUOTES, 'UTF-8'); ?>?round_id=' + encodeURIComponent(rid);
        if (bracketStatusBadge) {
            var ks = String(statusText || '').toLowerCase();
            bracketStatusBadge.classList.toggle('d-none', ks !== 'ongoing');
        }
    }

    function setKoGenerateVisibility(show) {
        if (!koGenerateWrap) return;
        koGenerateWrap.classList.toggle('d-none', !show);
    }

    function resetActionControls() {
        if (generatePresetEl) generatePresetEl.value = 'auto';
        if (generateWrap && generateRoundId) {
            generateWrap.classList.add('d-none');
            generateRoundId.value = '';
        }
        if (bracketWrap && bracketBtn) {
            setBracketButtonVisibility(false, 0, '');
        }
        setKoGenerateVisibility(false);
    }

    function loadStandings() {
        var sukanId = (sukanEl.value || '').trim();
        var kategoriId = (kategoriEl.value || '').trim();
        var roundId = (roundEl.value || '').trim();
        dbg('loadStandings:start', { sukanId: sukanId, kategoriId: kategoriId, roundId: roundId, roundCacheLen: roundCache.length });
        if (!sukanId) {
            setMessage('info', 'Please select a sport and category to load rounds.');
            bodyEl.innerHTML = '<tr><td colspan="10" class="text-muted text-center py-4">No data.</td></tr>';
            infoCardEl.classList.add('d-none');
            return;
        }
        if (!kategoriId) {
            setMessage('info', 'Please select a category to load rounds.');
            bodyEl.innerHTML = '<tr><td colspan="10" class="text-muted text-center py-4">No data.</td></tr>';
            infoCardEl.classList.add('d-none');
            return;
        }
        if (!roundId) {
            setMessage('info', 'Please select a round.');
            bodyEl.innerHTML = '<tr><td colspan="10" class="text-muted text-center py-4">No data.</td></tr>';
            infoCardEl.classList.add('d-none');
            return;
        }

        // Immediate UI fallback based on selected round from dropdown cache.
        setBracketButtonVisibility(false, 0, '');
        var selectedRoundRow = getRoundById(roundId);
        dbg('loadStandings:selectedRoundRow', selectedRoundRow);
        if (selectedRoundRow && String(selectedRoundRow.round_type || '').toLowerCase() === 'knockout') {
            setKoGenerateVisibility(true);
        } else if (roundId === 'all') {
            setBracketButtonVisibility(false, 0, '');
            setKoGenerateVisibility(false);
        }

        setMessage('secondary', 'Loading standing...');
        fetch('?action=get_standings&sukan_id=' + encodeURIComponent(sukanId) + '&kategori_id=' + encodeURIComponent(kategoriId) + '&round_id=' + encodeURIComponent(roundId))
            .then(function (r) { return r.json(); })
            .then(function (j) {
                dbg('loadStandings:response', j);
                if (!j || !j.success) {
                    setMessage('danger', (j && j.message) ? j.message : 'Unable to load standings.');
                    bodyEl.innerHTML = '<tr><td colspan="10" class="text-muted text-center py-4">No data.</td></tr>';
                    infoCardEl.classList.add('d-none');
                    return;
                }
                clearMessage();
                if (j.meta && j.meta.mode === 'all') {
                    setMessage('info', 'Show all groups in A, B, C order.');
                }
                currentMeta = j.meta || null;
                setInfoCard(j.meta || null);
                renderRows(j.rows || [], j.meta || null);
                if (generateWrap && generateRoundId) {
                    var canGenerate = j.meta && Number(j.meta.can_generate_knockout || 0) === 1;
                    var roundIdNum = j.meta ? Number(j.meta.selected_round_id || 0) : 0;
                    dbg('loadStandings:generateState', { canGenerate: canGenerate, roundIdNum: roundIdNum, meta: j.meta || null });
                    generateWrap.classList.toggle('d-none', !canGenerate);
                    generateRoundId.value = canGenerate && roundIdNum > 0 ? String(roundIdNum) : '';
                }
                if (bracketWrap && bracketBtn) {
                    var meta = j.meta || {};
                    var sel = meta.selected_round || {};
                    var selType = String(sel.round_type || '').toLowerCase();
                    var selectedRoundId = Number(meta.selected_round_id || 0);
                    var showBracket = Number(meta.show_bracket || 0) === 1;
                    var kid = Number(meta.next_knockout_round_id || 0);
                    var hasKnockoutMatches = Number(meta.next_knockout_has_matches || 0) === 1;
                    var selectedRoundNow = getRoundById(roundId);
                    var selectedTypeNow = String((selectedRoundNow && selectedRoundNow.round_type) || '').toLowerCase();

                    // Strict rule: show bracket only when selected round is knockout and has matches.
                    if (roundId === 'all') {
                        showBracket = false;
                    } else if (!(selType === 'knockout' && selectedRoundId > 0 && hasKnockoutMatches)) {
                        showBracket = false;
                    } else {
                        showBracket = true;
                        kid = selectedRoundId;
                    }

                    dbg('loadStandings:bracketState', {
                        showBracket: showBracket,
                        kid: kid,
                        selType: selType,
                        selectedTypeNow: selectedTypeNow,
                        selectedRoundId: selectedRoundId,
                        meta: meta
                    });
                    setBracketButtonVisibility(showBracket, kid, (meta.next_knockout_round_status || sel.status || ''));
                    var showKoGenerate = (
                        roundId !== 'all' &&
                        selectedTypeNow === 'knockout' &&
                        !showBracket
                    );
                    setKoGenerateVisibility(showKoGenerate);
                }
            })
            .catch(function () {
                dbg('loadStandings:error');
                setMessage('danger', 'Network error while attempting to load standings.');
                bodyEl.innerHTML = '<tr><td colspan="10" class="text-muted text-center py-4">No data.</td></tr>';
                infoCardEl.classList.add('d-none');
                if (generateWrap && generateRoundId) {
                    generateWrap.classList.add('d-none');
                    generateRoundId.value = '';
                }
                if (bracketWrap && bracketBtn) {
                    setBracketButtonVisibility(false, 0, '');
                }
                setKoGenerateVisibility(false);
            });
    }

    function loadRounds() {
        var sukanId = (sukanEl.value || '').trim();
        var kategoriId = (kategoriEl.value || '').trim();
        dbg('loadRounds:start', { sukanId: sukanId, kategoriId: kategoriId });
        roundEl.innerHTML = '<option value="">-- Round --</option>';
        roundEl.disabled = true;
        roundCache = [];
        if (!sukanId) {
            setMessage('info', 'Please select a sport and category to load rounds.');
            bodyEl.innerHTML = '<tr><td colspan="10" class="text-muted text-center py-4">No data.</td></tr>';
            infoCardEl.classList.add('d-none');
            if (generateWrap && generateRoundId) {
                generateWrap.classList.add('d-none');
                generateRoundId.value = '';
            }
            if (bracketWrap && bracketBtn) {
                setBracketButtonVisibility(false, 0, '');
            }
            return;
        }
        if (!kategoriId) {
            setMessage('info', 'Please select a category to load rounds.');
            bodyEl.innerHTML = '<tr><td colspan="10" class="text-muted text-center py-4">No data.</td></tr>';
            infoCardEl.classList.add('d-none');
            if (generateWrap && generateRoundId) {
                generateWrap.classList.add('d-none');
                generateRoundId.value = '';
            }
            if (bracketWrap && bracketBtn) {
                setBracketButtonVisibility(false, 0, '');
            }
            return;
        }

        setMessage('secondary', 'Loading round...');
        fetch('?action=get_rounds&sukan_id=' + encodeURIComponent(sukanId) + '&kategori_id=' + encodeURIComponent(kategoriId))
            .then(function (r) { return r.json(); })
            .then(function (j) {
                dbg('loadRounds:response', j);
                if (!j || !j.success) {
                    setMessage('danger', (j && j.message) ? j.message : 'Unable to load rounds.');
                    return;
                }

                roundCache = j.rounds || [];
                dbg('loadRounds:roundCache', roundCache);
                if (!roundCache.length) {
                    setMessage('info', 'No rounds available for the selected sport.');
                    bodyEl.innerHTML = '<tr><td colspan="10" class="text-muted text-center py-4">No data.</td></tr>';
                    infoCardEl.classList.add('d-none');
                    if (generateWrap && generateRoundId) {
                        generateWrap.classList.add('d-none');
                        generateRoundId.value = '';
                    }
                    if (bracketWrap && bracketBtn) {
                        setBracketButtonVisibility(false, 0, '');
                    }
                    return;
                }

                roundEl.innerHTML = '';
                var allOpt = document.createElement('option');
                allOpt.value = 'all';
                allOpt.textContent = '-- All Group --';
                roundEl.appendChild(allOpt);

                roundCache.forEach(function (r) {
                    var opt = document.createElement('option');
                    opt.value = String(r.id);
                    var gcRaw = (r.group_code || '').toString().trim();
                    var rt = (r.round_type || '').toString().toLowerCase();
                    if ((gcRaw === '' || gcRaw === '-') && rt === 'knockout') gcRaw = 'Knockout Stage';
                    var gc = gcRaw ? (' - ' + gcRaw) : '';
                    opt.textContent = (r.nama_round || ('Round ' + r.id)) + gc;
                    roundEl.appendChild(opt);
                });
                roundEl.disabled = false;
                roundEl.value = 'all';
                var firstKo = getFirstKnockoutRound();
                dbg('loadRounds:firstKnockout', firstKo);
                if (firstKo) {
                    setBracketButtonVisibility(false, 0, '');
                    setKoGenerateVisibility(true);
                } else {
                    setBracketButtonVisibility(false, 0, '');
                    setKoGenerateVisibility(false);
                }
                loadStandings();
            })
            .catch(function () {
                setMessage('danger', 'Network error while attempting to load rounds.');
                setBracketButtonVisibility(false, 0, '');
                setKoGenerateVisibility(false);
            });
    }

    function loadCategories() {
        var sukanId = (sukanEl.value || '').trim();
        resetActionControls();
        kategoriEl.innerHTML = '<option value="">-- Category --</option>';
        kategoriEl.disabled = true;
        roundEl.innerHTML = '<option value="">-- Round --</option>';
        roundEl.disabled = true;
        roundCache = [];

        if (!sukanId) {
            setMessage('info', 'Please select a sport and category to load rounds.');
            bodyEl.innerHTML = '<tr><td colspan="10" class="text-muted text-center py-4">No data.</td></tr>';
            infoCardEl.classList.add('d-none');
            if (generateWrap && generateRoundId) {
                generateWrap.classList.add('d-none');
                generateRoundId.value = '';
            }
            if (bracketWrap && bracketBtn) {
                setBracketButtonVisibility(false, 0, '');
            }
            setKoGenerateVisibility(false);
            return;
        }

        setMessage('secondary', 'Loading categories...');
        fetch('?action=get_categories&sukan_id=' + encodeURIComponent(sukanId))
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.success) {
                    setMessage('danger', (j && j.message) ? j.message : 'Unable to load categories.');
                    return;
                }
                var rows = j.categories || [];
                if (!rows.length) {
                    setMessage('info', 'No categories for the selected sport.');
                    return;
                }
                rows.forEach(function (k) {
                    var opt = document.createElement('option');
                    opt.value = String(k.id);
                    opt.textContent = englishSportLabel(k.nama_kategori) || ('Category ' + k.id);
                    kategoriEl.appendChild(opt);
                });
                kategoriEl.disabled = false;
                setMessage('info', 'Please select a category and round.');
            })
            .catch(function () {
                setMessage('danger', 'Network error while attempting to load categories.');
            });
    }

    sukanEl.addEventListener('change', loadCategories);
    kategoriEl.addEventListener('change', loadRounds);
    roundEl.addEventListener('change', loadStandings);

    if (generateForm) {
        generateForm.addEventListener('submit', async function (e) {
            var rid = (generateRoundId && generateRoundId.value) ? String(generateRoundId.value).trim() : '';
            if (!rid) {
                e.preventDefault();
                setMessage('warning', 'Invalid round. Please select an eligible league round first.');
                return;
            }
            e.preventDefault();
            var ok = await confirmWithSwal('Generate Knockout', 'Continue generate knockout stage sekarang?', 'Yes, Generate');
            if (!ok) return;
            // Hard-lock action to generate endpoint.
            generateForm.action = '<?php echo htmlspecialchars(url('pages/generate-knockout.php'), ENT_QUOTES, 'UTF-8'); ?>';
            dbg('generateForm:submit', { action: generateForm.action, round_id: rid, preset: (generatePresetEl ? generatePresetEl.value : 'auto') });
            generateForm.submit();
        });
    }

    if (koGenerateBtn) {
        koGenerateBtn.addEventListener('click', async function () {
            var meta = currentMeta || {};
            var sel = meta.selected_round || {};
            var eventId = Number(sel.event_id || 0);
            var namaRound = String(sel.nama_round || '');
            var selType = String(sel.round_type || '').toLowerCase();
            if (selType !== 'knockout' || eventId <= 0 || !namaRound) {
                setMessage('warning', 'Invalid knockout round for schedule generation.');
                return;
            }
            var ok = await confirmWithSwal('Generate Knockout Schedule', 'Generate the knockout schedule for this round?', 'Yes, Generate');
            if (!ok) return;
            koGenerateBtn.disabled = true;
            koGenerateBtn.textContent = 'Menjana...';
            var fd = new FormData();
            fd.append('action', 'generate_schedule');
            fd.append('event_id', String(eventId));
            fd.append('nama_round', namaRound);
            fetch('<?php echo htmlspecialchars(url('pages/setup-jadual.php'), ENT_QUOTES, 'UTF-8'); ?>', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    dbg('koGenerate:response', j);
                    if (j && j.success) {
                        setMessage('success', 'Knockout schedule generated successfully.');
                        ensureSwal()
                            .then(function (Swal) {
                                return Swal.fire({
                                    icon: 'success',
                                    title: 'Success',
                                    text: 'Knockout schedule generated successfully.',
                                    timer: 1400,
                                    showConfirmButton: false
                                });
                            })
                            .catch(function () {})
                            .finally(function () { loadStandings(); });
                    } else {
                        var msg = (j && j.errors && j.errors.length) ? j.errors.join(' | ') : 'Unable to generate the knockout schedule.';
                        setMessage('danger', msg);
                    }
                })
                .catch(function (e) {
                    dbg('koGenerate:error', e);
                    setMessage('danger', 'Network error while attempting to generate the knockout schedule.');
                })
                .finally(function () {
                    koGenerateBtn.disabled = false;
                    koGenerateBtn.textContent = 'Generate Knockout Schedule';
                });
        });
    }

    if (bracketBtn) {
        bracketBtn.addEventListener('click', function (e) {
            var href = (bracketBtn.getAttribute('href') || '').trim();
            dbg('bracketBtn:click', { href: href, visible: !bracketWrap.classList.contains('d-none') });
            if (!href || /bracket\.php$/.test(href)) {
                e.preventDefault();
                dbg('bracketBtn:blocked', { reason: 'invalid_or_missing_round_id', href: href });
                if (window.Swal && typeof window.Swal.fire === 'function') {
                    window.Swal.fire({
                        icon: 'warning',
                        title: 'Information',
                        text: 'No valid knockout round has been selected.'
                    });
                } else {
                    alert('No valid knockout round has been selected.');
                }
                return;
            }
            e.preventDefault();
            window.location.assign(href);
        });
    }
})();
</script>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>
