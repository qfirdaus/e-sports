<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/rbac.php';

Session::start();
$auth = getAuth();
$auth->requireAuth();

$rbac = getRBAC();
$rbac->requirePageAccess('pages/generate-knockout.php');

function gk_has_column(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) return (bool)$cache[$key];
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c');
    $stmt->execute([':t' => $table, ':c' => $column]);
    $ok = ((int)$stmt->fetchColumn() > 0);
    $cache[$key] = $ok;
    return $ok;
}

function gk_pick_col(PDO $db, string $table, array $candidates): ?string
{
    foreach ($candidates as $c) {
        if (gk_has_column($db, $table, $c)) return $c;
    }
    return null;
}

function gk_count_match_results(PDO $db, int $roundId): int
{
    if (gk_has_column($db, 'table_match_result', 'match_id')) {
        $sql = "
            SELECT COUNT(*)
            FROM table_match_result mr
            INNER JOIN table_match m ON m.id = mr.match_id
            WHERE m.round_id = :round_id
        ";
        if (gk_has_column($db, 'table_match', 'deleted_at')) $sql .= " AND m.deleted_at IS NULL";
        $stmt = $db->prepare($sql);
        $stmt->execute([':round_id' => $roundId]);
        return (int)$stmt->fetchColumn();
    }

    $mpCol = gk_pick_col($db, 'table_match_participant', ['participant_id', 'team_id', 'pasukan_id']) ?: 'participant_id';
    $sql = "
        SELECT COUNT(*)
        FROM table_match_result mr
        INNER JOIN table_match_participant mp ON mp.id = mr.match_participant_id
        INNER JOIN table_match m ON m.id = mp.match_id
        WHERE m.round_id = :round_id
    ";
    if (gk_has_column($db, 'table_match', 'deleted_at')) $sql .= " AND m.deleted_at IS NULL";
    if (gk_has_column($db, 'table_match_participant', 'deleted_at')) $sql .= " AND mp.deleted_at IS NULL";
    $stmt = $db->prepare($sql);
    $stmt->execute([':round_id' => $roundId]);
    return (int)$stmt->fetchColumn();
}

function gk_redirect(int $roundId, string $type, string $msg): void
{
    $q = http_build_query([
        'round_id' => $roundId,
        'flash_type' => $type,
        'flash_msg' => $msg,
    ]);
    header('Location: ' . url('pages/round-standing.php') . '?' . $q);
    exit;
}

function gk_normalize_seed_token(string $token): string
{
    $t = strtoupper(trim($token));
    $t = str_replace(['-', '.', '  '], ['_', '', ' '], $t);
    $t = preg_replace('/\s+/', ' ', $t);
    $t = str_replace('NAIB JOHAN', 'RUNNER_UP', $t);
    $t = str_replace('JOHAN', 'WINNER', $t);
    $t = str_replace(' ', '_', $t);
    return $t;
}

function gk_resolve_seed_token(string $token, array $rankMap): int
{
    $t = gk_normalize_seed_token($token);
    if ($t === '') return 0;

    // Accept: WINNER_A / RUNNER_UP_B / JUARA_C (normalized to WINNER_C if provided as JOHAN C)
    if (preg_match('/^(WINNER|RUNNER_UP)_([A-Z0-9]+)$/', $t, $m)) {
        $rank = $m[1] === 'WINNER' ? 'winner' : 'runner_up';
        $group = strtoupper($m[2]);
        return (int)($rankMap[$group][$rank] ?? 0);
    }
    return 0;
}

function gk_build_custom_pairs(array $seedSlots, array $rankMap): array
{
    $pairs = [];
    $used = [];
    foreach ($seedSlots as $slot) {
        if (!is_array($slot)) continue;
        $homeTok = trim((string)($slot['home'] ?? ''));
        $awayTok = trim((string)($slot['away'] ?? ''));
        if ($homeTok === '' || $awayTok === '') continue;
        $home = gk_resolve_seed_token($homeTok, $rankMap);
        $away = gk_resolve_seed_token($awayTok, $rankMap);
        if ($home <= 0 || $away <= 0 || $home === $away) continue;

        // Avoid duplicate team assignment in same knockout seed generation.
        if (isset($used[$home]) || isset($used[$away])) continue;
        $used[$home] = true;
        $used[$away] = true;

        $pairs[] = [$home, $away];
    }
    return $pairs;
}

function gk_next_match_no_for_knockout(PDO $db, array $leagueRound, int $knockoutRoundId): int
{
    // If knockout round already has matches, continue after its current max.
    $sqlKo = "SELECT COALESCE(MAX(match_no), 0) FROM table_match WHERE round_id = :rid";
    if (gk_has_column($db, 'table_match', 'deleted_at')) $sqlKo .= " AND deleted_at IS NULL";
    $stKo = $db->prepare($sqlKo);
    $stKo->execute([':rid' => $knockoutRoundId]);
    $maxKo = (int)$stKo->fetchColumn();
    if ($maxKo > 0) return $maxKo + 1;

    // Otherwise continue after max match_no of the same event/sukan scope.
    $sql = "
        SELECT COALESCE(MAX(m.match_no), 0)
        FROM table_match m
        INNER JOIN table_round r ON r.id = m.round_id
        WHERE 1=1
    ";
    $params = [];
    if (isset($leagueRound['event_id'])) {
        $sql .= " AND r.event_id = :event_id";
        $params[':event_id'] = (int)$leagueRound['event_id'];
    } elseif (isset($leagueRound['sukan_id']) && gk_has_column($db, 'table_round', 'sukan_id')) {
        $sql .= " AND r.sukan_id = :sukan_id";
        $params[':sukan_id'] = (int)$leagueRound['sukan_id'];
    }
    if (gk_has_column($db, 'table_match', 'deleted_at')) $sql .= " AND m.deleted_at IS NULL";
    if (gk_has_column($db, 'table_round', 'deleted_at')) $sql .= " AND r.deleted_at IS NULL";
    $st = $db->prepare($sql);
    $st->execute($params);
    $maxAll = (int)$st->fetchColumn();
    return $maxAll > 0 ? ($maxAll + 1) : 1;
}

function gk_upsert_match_participant(PDO $db, int $matchId, int $participantId, string $participantCol): void
{
    if ($matchId <= 0 || $participantId <= 0) return;

    $sqlExists = "SELECT COUNT(*) FROM table_match_participant WHERE match_id = :match_id AND {$participantCol} = :participant_id";
    if (gk_has_column($db, 'table_match_participant', 'deleted_at')) $sqlExists .= " AND deleted_at IS NULL";
    $stExists = $db->prepare($sqlExists);
    $stExists->execute([':match_id' => $matchId, ':participant_id' => $participantId]);
    if ((int)$stExists->fetchColumn() > 0) return;

    $sqlCount = "SELECT COUNT(*) FROM table_match_participant WHERE match_id = :match_id";
    if (gk_has_column($db, 'table_match_participant', 'deleted_at')) $sqlCount .= " AND deleted_at IS NULL";
    $stCount = $db->prepare($sqlCount);
    $stCount->execute([':match_id' => $matchId]);
    if ((int)$stCount->fetchColumn() >= 2) return;

    $cols = ['match_id', $participantCol];
    $vals = [':match_id', ':participant_id'];
    $params = [':match_id' => $matchId, ':participant_id' => $participantId];
    if (gk_has_column($db, 'table_match_participant', 'created_at')) {
        $cols[] = 'created_at';
        $vals[] = ':created_at';
        $params[':created_at'] = date('Y-m-d H:i:s');
    }

    $ins = $db->prepare('INSERT INTO table_match_participant (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')');
    $ins->execute($params);
}

function gk_find_or_create_knockout_round(PDO $db, array $leagueRound, int $nextOrder): array
{
    $scopeSql = '';
    $scopeParams = [];
    if (isset($leagueRound['event_id'])) {
        $scopeSql = ' AND event_id = :event_id';
        $scopeParams[':event_id'] = (int)$leagueRound['event_id'];
    } elseif (isset($leagueRound['sukan_id'])) {
        $scopeSql = ' AND sukan_id = :sukan_id';
        $scopeParams[':sukan_id'] = (int)$leagueRound['sukan_id'];
    }

    $deletedSql = gk_has_column($db, 'table_round', 'deleted_at') ? ' AND deleted_at IS NULL' : '';

    // 1) Preferred: knockout row at the next round_order.
    $sql1 = "SELECT id, status, qualification_rule
             FROM table_round
             WHERE round_type = 'knockout'
               AND round_order = :round_order
               {$scopeSql}
               {$deletedSql}
             ORDER BY id ASC
             LIMIT 1";
    $st1 = $db->prepare($sql1);
    $st1->execute(array_merge([':round_order' => $nextOrder], $scopeParams));
    $row = $st1->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    // 2) Fallback: any knockout row in same scope (legacy data without proper round_order).
    $sql2 = "SELECT id, status, qualification_rule
             FROM table_round
             WHERE round_type = 'knockout'
               {$scopeSql}
               {$deletedSql}
             ORDER BY COALESCE(round_order, 0) ASC, id ASC
             LIMIT 1";
    $st2 = $db->prepare($sql2);
    $st2->execute($scopeParams);
    $row = $st2->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    // 3) Auto-create knockout row for current scope.
    $cols = ['nama_round', 'round_type', 'status', 'round_order'];
    $vals = [':nama_round', "'knockout'", "'pending'", ':round_order'];
    $params = [
        ':nama_round' => 'Knockout Stage',
        ':round_order' => $nextOrder,
    ];

    if (gk_has_column($db, 'table_round', 'event_id') && isset($leagueRound['event_id'])) {
        $cols[] = 'event_id';
        $vals[] = ':event_id';
        $params[':event_id'] = (int)$leagueRound['event_id'];
    } elseif (gk_has_column($db, 'table_round', 'sukan_id') && isset($leagueRound['sukan_id'])) {
        $cols[] = 'sukan_id';
        $vals[] = ':sukan_id';
        $params[':sukan_id'] = (int)$leagueRound['sukan_id'];
    }
    if (gk_has_column($db, 'table_round', 'is_locked')) {
        $cols[] = 'is_locked';
        $vals[] = ':is_locked';
        $params[':is_locked'] = 0;
    }
    if (gk_has_column($db, 'table_round', 'qualification_rule')) {
        $cols[] = 'qualification_rule';
        $vals[] = ':qualification_rule';
        $params[':qualification_rule'] = null;
    }
    if (gk_has_column($db, 'table_round', 'created_by') && Session::has('user_id')) {
        $cols[] = 'created_by';
        $vals[] = ':created_by';
        $params[':created_by'] = (int)Session::get('user_id');
    }

    $ins = $db->prepare('INSERT INTO table_round (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')');
    $ins->execute($params);

    return [
        'id' => (int)$db->lastInsertId(),
        'status' => 'pending',
        'qualification_rule' => null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gk_redirect(0, 'danger', 'Invalid method.');
}

$roundId = isset($_POST['round_id']) ? (int)$_POST['round_id'] : 0;
$knockoutPreset = isset($_POST['knockout_preset']) ? trim((string)$_POST['knockout_preset']) : '';
if ($knockoutPreset === '') $knockoutPreset = 'auto';
if ($roundId <= 0) {
    gk_redirect(0, 'danger', 'Invalid round.');
}

try {
    $db = getDB();
    $db->beginTransaction();

    $cols = ['id', 'nama_round', 'round_type', 'status', 'is_locked', 'round_order', 'qualification_rule'];
    if (gk_has_column($db, 'table_round', 'event_id')) $cols[] = 'event_id';
    if (gk_has_column($db, 'table_round', 'sukan_id')) $cols[] = 'sukan_id';

    $stmt = $db->prepare('SELECT ' . implode(', ', $cols) . ' FROM table_round WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $roundId]);
    $leagueRound = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$leagueRound) throw new RuntimeException('Rounds not found.');

    if (strtolower((string)$leagueRound['round_type']) !== 'league') {
        throw new RuntimeException('This is not a league round.');
    }
    if (strtolower((string)$leagueRound['status']) !== 'completed') {
        throw new RuntimeException('The league round is not yet complete.');
    }
    // Global mode: collect all league rounds for same stage in same event/sukan.
    // Prefer same nama_round (covers group A/B/C setup where round_order may differ),
    // then fallback to same round_order.
    $selectedNamaRound = trim((string)($leagueRound['nama_round'] ?? ''));
    $leagueScopeSql = "SELECT id, status, is_locked, qualification_rule, COALESCE(group_code, '') AS group_code
                       FROM table_round
                       WHERE round_type = 'league'";
    $leagueScopeParams = [];
    if (isset($leagueRound['event_id'])) {
        $leagueScopeSql .= " AND event_id = :event_id";
        $leagueScopeParams[':event_id'] = (int)$leagueRound['event_id'];
    } elseif (isset($leagueRound['sukan_id'])) {
        $leagueScopeSql .= " AND sukan_id = :sukan_id";
        $leagueScopeParams[':sukan_id'] = (int)$leagueRound['sukan_id'];
    }
    if ($selectedNamaRound !== '') {
        $leagueScopeSql .= " AND nama_round = :nama_round";
        $leagueScopeParams[':nama_round'] = $selectedNamaRound;
    } else {
        $leagueScopeSql .= " AND round_order = :round_order";
        $leagueScopeParams[':round_order'] = (int)$leagueRound['round_order'];
    }
    if (gk_has_column($db, 'table_round', 'deleted_at')) $leagueScopeSql .= " AND deleted_at IS NULL";
    $leagueScopeSql .= " ORDER BY group_code ASC, id ASC";
    $leagueScopeStmt = $db->prepare($leagueScopeSql);
    $leagueScopeStmt->execute($leagueScopeParams);
    $leagueRounds = $leagueScopeStmt->fetchAll(PDO::FETCH_ASSOC);
    if (($selectedNamaRound !== '') && (empty($leagueRounds) || count($leagueRounds) === 1)) {
        $leagueScopeSql2 = "SELECT id, status, is_locked, qualification_rule, COALESCE(group_code, '') AS group_code
                            FROM table_round
                            WHERE round_type = 'league'";
        $leagueScopeParams2 = [];
        if (isset($leagueRound['event_id'])) {
            $leagueScopeSql2 .= " AND event_id = :event_id";
            $leagueScopeParams2[':event_id'] = (int)$leagueRound['event_id'];
        } elseif (isset($leagueRound['sukan_id'])) {
            $leagueScopeSql2 .= " AND sukan_id = :sukan_id";
            $leagueScopeParams2[':sukan_id'] = (int)$leagueRound['sukan_id'];
        }
        $leagueScopeSql2 .= " AND round_order = :round_order";
        $leagueScopeParams2[':round_order'] = (int)$leagueRound['round_order'];
        if (gk_has_column($db, 'table_round', 'deleted_at')) $leagueScopeSql2 .= " AND deleted_at IS NULL";
        $leagueScopeSql2 .= " ORDER BY group_code ASC, id ASC";
        $leagueScopeStmt2 = $db->prepare($leagueScopeSql2);
        $leagueScopeStmt2->execute($leagueScopeParams2);
        $rows2 = $leagueScopeStmt2->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows2)) {
            $leagueRounds = $rows2;
        }
    }
    if (empty($leagueRounds)) {
        throw new RuntimeException('The source league round was not found.');
    }

    $checkCompletedSql = "SELECT COUNT(*) FROM table_match WHERE round_id = :round_id AND status != 'completed'";
    if (gk_has_column($db, 'table_match', 'deleted_at')) $checkCompletedSql .= " AND deleted_at IS NULL";
    $checkCompletedStmt = $db->prepare($checkCompletedSql);
    foreach ($leagueRounds as $lr) {
        $lrId = (int)$lr['id'];
        $lrGroup = trim((string)($lr['group_code'] ?? ''));
        $groupLabel = $lrGroup !== '' ? "Group {$lrGroup}" : "Round {$lrId}";
        if (strtolower((string)($lr['status'] ?? '')) !== 'completed') {
            throw new RuntimeException("{$groupLabel} is not yet complete.");
        }
        $checkCompletedStmt->execute([':round_id' => $lrId]);
        if ((int)$checkCompletedStmt->fetchColumn() > 0) {
            throw new RuntimeException("Some matches in {$groupLabel} are not yet complete.");
        }
    }

    $nextOrder = ((int)$leagueRound['round_order']) + 1;
    $knockout = gk_find_or_create_knockout_round($db, $leagueRound, $nextOrder);
    $knockoutRoundId = (int)$knockout['id'];

    // Safety: block regenerate when knockout already has any result.
    if (gk_count_match_results($db, $knockoutRoundId) > 0) {
        throw new RuntimeException('The knockout stage already has results. Generation is blocked.');
    }

    $standingParticipantCol = gk_pick_col($db, 'table_standing', ['participant_id', 'team_id', 'pasukan_id']);
    if ($standingParticipantCol === null) throw new RuntimeException('Participant column not found in table_standing.');
    $posCol = gk_pick_col($db, 'table_standing', ['position', 'ranking']);
    if ($posCol === null) throw new RuntimeException('Position/ranking column not found in table_standing.');

    $qualified = [];
    $qualifiedMeta = [];
    $sSql = "SELECT {$standingParticipantCol} AS participant_id, {$posCol} AS pos
             FROM table_standing
             WHERE round_id = :round_id
             ORDER BY CAST({$posCol} AS UNSIGNED) ASC";
    $sStmt = $db->prepare($sSql);
    foreach ($leagueRounds as $lr) {
        $lrId = (int)$lr['id'];
        $lrGroup = trim((string)($lr['group_code'] ?? ''));
        $groupLabel = $lrGroup !== '' ? "Group {$lrGroup}" : "Round {$lrId}";

        $rule = trim((string)($lr['qualification_rule'] ?? ''));
        $decoded = $rule !== '' ? json_decode($rule, true) : null;
        $topN = (is_array($decoded) && isset($decoded['top_n'])) ? (int)$decoded['top_n'] : 0;
        if ($topN <= 0) {
            throw new RuntimeException("Invalid qualification_rule.top_n for {$groupLabel}.");
        }

        $sStmt->execute([':round_id' => $lrId]);
        $standRows = $sStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$standRows) {
            throw new RuntimeException("Standings have not been generated for {$groupLabel}.");
        }

        foreach ($standRows as $r) {
            $p = (int)$r['participant_id'];
            $pos = (int)$r['pos'];
            if ($p > 0 && $pos > 0 && $pos <= $topN) {
                $qualified[] = $p;
                $qualifiedMeta[] = [
                    'participant_id' => $p,
                    'pos' => $pos,
                    'group_code' => $lrGroup,
                ];
            }
        }
    }
    $qualified = array_values(array_unique($qualified));
    if (count($qualified) < 2) throw new RuntimeException('Not enough eligible teams.');

    $mSql = "SELECT id, match_no FROM table_match WHERE round_id = :round_id";
    if (gk_has_column($db, 'table_match', 'deleted_at')) $mSql .= " AND deleted_at IS NULL";
    $mSql .= " ORDER BY match_no ASC, id ASC";
    $mStmt = $db->prepare($mSql);
    $mStmt->execute([':round_id' => $knockoutRoundId]);
    $matches = $mStmt->fetchAll(PDO::FETCH_ASSOC);

    // Build rank map by group (winner/runner-up) for configurable seed slots.
    $rankMap = [];
    foreach ($qualifiedMeta as $qm) {
        $pid = (int)$qm['participant_id'];
        $grp = strtoupper(trim((string)$qm['group_code']));
        $pos = (int)$qm['pos'];
        if ($pid <= 0 || $grp === '' || $pos <= 0) continue;
        if (!isset($rankMap[$grp])) {
            $rankMap[$grp] = ['winner' => 0, 'runner_up' => 0];
        }
        if ($pos === 1 && $rankMap[$grp]['winner'] === 0) $rankMap[$grp]['winner'] = $pid;
        if ($pos === 2 && $rankMap[$grp]['runner_up'] === 0) $rankMap[$grp]['runner_up'] = $pid;
    }

    // Seeding strategy:
    // 0) If knockout qualification_rule.seed_slots exists, use it first.
    // 1) For 3 groups x top2 (6 teams), use cross-group pairing:
    //    A1 vs B2, B1 vs C2, C1 vs A2 (group order sorted by code).
    // 2) Fallback default seeding: top vs bottom.
    $pairs = [];
    $customSeed = [];
    $kRule = trim((string)($knockout['qualification_rule'] ?? ''));
    if ($kRule !== '') {
        $decodedK = json_decode($kRule, true);
        if (is_array($decodedK) && isset($decodedK['seed_slots']) && is_array($decodedK['seed_slots'])) {
            $customSeed = $decodedK['seed_slots'];
        }
    }
    if ($knockoutPreset === 'badminton_4group_qf') {
        $customSeed = [
            ['home' => 'WINNER_A', 'away' => 'RUNNER_UP_C'],
            ['home' => 'WINNER_B', 'away' => 'RUNNER_UP_D'],
            ['home' => 'WINNER_C', 'away' => 'RUNNER_UP_A'],
            ['home' => 'WINNER_D', 'away' => 'RUNNER_UP_B'],
        ];
    }
    $useSingleGroupTop4Preset = false;
    if ($knockoutPreset === 'single_group_top4_sf_3rd_final') {
        $rankedByPos = [];
        foreach ($qualifiedMeta as $qm) {
            $pid = (int)($qm['participant_id'] ?? 0);
            $pos = (int)($qm['pos'] ?? 0);
            if ($pid <= 0 || $pos <= 0 || $pos > 4) continue;
            if (!isset($rankedByPos[$pos])) $rankedByPos[$pos] = $pid;
        }
        if (
            !isset($rankedByPos[1], $rankedByPos[2], $rankedByPos[3], $rankedByPos[4]) ||
            count(array_unique([(int)$rankedByPos[1], (int)$rankedByPos[2], (int)$rankedByPos[3], (int)$rankedByPos[4]])) !== 4
        ) {
            throw new RuntimeException('The Single Group Top 4 preset requires four valid top-ranked participants (ranks 1–4).');
        }
        // Semi final fixed pairing:
        // Match 1: Rank 1 vs Rank 2
        // Match 2: Rank 3 vs Rank 4
        $pairs[] = [(int)$rankedByPos[1], (int)$rankedByPos[2]];
        $pairs[] = [(int)$rankedByPos[3], (int)$rankedByPos[4]];
        $useSingleGroupTop4Preset = true;
    }
    $useCustomSeed = !empty($customSeed);
    if ($useCustomSeed) {
        $pairs = gk_build_custom_pairs($customSeed, $rankMap);
        if (empty($pairs)) {
            throw new RuntimeException('Custom seed slots are invalid or cannot be matched to the group standings.');
        }
    }

    // Auto default for 4-group x top2:
    // J.A vs NJ.C, J.B vs NJ.D, J.C vs NJ.A, J.D vs NJ.B
    if (empty($pairs) && count($qualified) === 8 && count($rankMap) === 4) {
        $g = array_keys($rankMap);
        sort($g, SORT_NATURAL | SORT_FLAG_CASE);
        $g0 = $g[0] ?? null;
        $g1 = $g[1] ?? null;
        $g2 = $g[2] ?? null;
        $g3 = $g[3] ?? null;
        if (
            $g0 && $g1 && $g2 && $g3 &&
            !empty($rankMap[$g0]['winner']) && !empty($rankMap[$g0]['runner_up']) &&
            !empty($rankMap[$g1]['winner']) && !empty($rankMap[$g1]['runner_up']) &&
            !empty($rankMap[$g2]['winner']) && !empty($rankMap[$g2]['runner_up']) &&
            !empty($rankMap[$g3]['winner']) && !empty($rankMap[$g3]['runner_up'])
        ) {
            $pairs[] = [(int)$rankMap[$g0]['winner'], (int)$rankMap[$g2]['runner_up']];
            $pairs[] = [(int)$rankMap[$g1]['winner'], (int)$rankMap[$g3]['runner_up']];
            $pairs[] = [(int)$rankMap[$g2]['winner'], (int)$rankMap[$g0]['runner_up']];
            $pairs[] = [(int)$rankMap[$g3]['winner'], (int)$rankMap[$g1]['runner_up']];
            $customSeed = [
                ['home' => 'WINNER_' . $g0, 'away' => 'RUNNER_UP_' . $g2],
                ['home' => 'WINNER_' . $g1, 'away' => 'RUNNER_UP_' . $g3],
                ['home' => 'WINNER_' . $g2, 'away' => 'RUNNER_UP_' . $g0],
                ['home' => 'WINNER_' . $g3, 'away' => 'RUNNER_UP_' . $g1],
            ];
            $useCustomSeed = true;
        }
    }

    $crossGroupPaired = false;
    $useThreeGroupByeFlow = false;
    $threeGroupAutoSeed = [];
    $groups = [];
    if (count($rankMap) === 3) {
        $groups = array_keys($rankMap);
        sort($groups, SORT_NATURAL | SORT_FLAG_CASE);
    }
    if (empty($pairs) && count($qualified) === 6 && count($groups) === 3) {
        $g0 = $groups[0] ?? null; // A
        $g1 = $groups[1] ?? null; // B
        $g2 = $groups[2] ?? null; // C
        if (
            $g0 && $g1 && $g2 &&
            !empty($rankMap[$g0]['winner']) && !empty($rankMap[$g0]['runner_up']) &&
            !empty($rankMap[$g1]['winner']) && !empty($rankMap[$g1]['runner_up']) &&
            !empty($rankMap[$g2]['winner']) && !empty($rankMap[$g2]['runner_up'])
        ) {
            // Football-style 3 group flow:
            // opening 2 matches, 2 top group winners get bye to semifinal (home slot).
            $pairs[] = [(int)$rankMap[$g1]['runner_up'], (int)$rankMap[$g2]['runner_up']];
            $pairs[] = [(int)$rankMap[$g2]['winner'], (int)$rankMap[$g0]['runner_up']];
            $useThreeGroupByeFlow = true;
            $threeGroupAutoSeed = [
                ['home' => 'RUNNER_UP_' . $g1, 'away' => 'RUNNER_UP_' . $g2],
                ['home' => 'WINNER_' . $g2, 'away' => 'RUNNER_UP_' . $g0],
            ];
        }
    }

    if (empty($pairs) && count($qualified) === 6 && count($rankMap) === 3) {
        $g0 = $groups[0] ?? null;
        $g1 = $groups[1] ?? null;
        $g2 = $groups[2] ?? null;
        if (
            $g0 && $g1 && $g2 &&
            !empty($rankMap[$g0]['winner']) && !empty($rankMap[$g0]['runner_up']) &&
            !empty($rankMap[$g1]['winner']) && !empty($rankMap[$g1]['runner_up']) &&
            !empty($rankMap[$g2]['winner']) && !empty($rankMap[$g2]['runner_up'])
        ) {
            $pairs[] = [(int)$rankMap[$g0]['winner'], (int)$rankMap[$g1]['runner_up']];
            $pairs[] = [(int)$rankMap[$g1]['winner'], (int)$rankMap[$g2]['runner_up']];
            $pairs[] = [(int)$rankMap[$g2]['winner'], (int)$rankMap[$g0]['runner_up']];
            $crossGroupPaired = true;
        }
    }

    if (empty($pairs) && !$crossGroupPaired) {
        $i = 0;
        $j = count($qualified) - 1;
        while ($i < $j) {
            $pairs[] = [$qualified[$i], $qualified[$j]];
            $i++;
            $j--;
        }
    }
    if (empty($pairs)) {
        throw new RuntimeException('Unable to create knockout pairings.');
    }

    // Optional auto-followup matches for current configured flow.
    $autoFollowHomePids = [];
    $enablePlacementFinal = false;
    if (($useCustomSeed && count($pairs) === 2) || $useThreeGroupByeFlow) {
        $wA = (int)($rankMap['A']['winner'] ?? 0);
        $wB = (int)($rankMap['B']['winner'] ?? 0);
        if ($wA > 0 && $wB > 0 && $wA !== $wB) {
            $autoFollowHomePids = [$wA, $wB];
            $enablePlacementFinal = true;
        }
    }
    // General rule: 4 opening matches means 8-team knockout flow
    // QF -> SF -> 3rd/4th + Final (football-style last 2 games).
    $useQfPresetFlow = (count($pairs) === 4);

    // Always prune extra knockout matches to match planned count.
    // Planned count = opening pairs + auto followup matches (if any).
    $openingMatchCount = count($pairs);
    $plannedTotalMatches = $openingMatchCount + count($autoFollowHomePids) + ($enablePlacementFinal ? 2 : 0);
    if ($useQfPresetFlow) {
        // QF(4) + SF(2) + 3rd(1) + Final(1)
        $plannedTotalMatches = $openingMatchCount + 4;
    } elseif ($useSingleGroupTop4Preset) {
        // SF(2) + 3rd(1) + Final(1)
        $plannedTotalMatches = $openingMatchCount + 2;
    }
    $currentMatches = is_array($matches) ? $matches : [];
    if (count($currentMatches) > $plannedTotalMatches) {
        $extraMatches = array_slice($currentMatches, $plannedTotalMatches);
        if (!empty($extraMatches)) {
            $extraIds = array_values(array_filter(array_map(static fn($m) => (int)($m['id'] ?? 0), $extraMatches), static fn($v) => $v > 0));
            if (!empty($extraIds)) {
                $ph = implode(',', array_fill(0, count($extraIds), '?'));

                // Clear participants for extra matches first.
                if (gk_has_column($db, 'table_match_participant', 'deleted_at')) {
                    $stMp = $db->prepare("UPDATE table_match_participant SET deleted_at = NOW() WHERE match_id IN ({$ph}) AND deleted_at IS NULL");
                    $stMp->execute($extraIds);
                } else {
                    $stMp = $db->prepare("DELETE FROM table_match_participant WHERE match_id IN ({$ph})");
                    $stMp->execute($extraIds);
                }

                // Remove/soft-delete extra matches.
                if (gk_has_column($db, 'table_match', 'deleted_at')) {
                    $stM = $db->prepare("UPDATE table_match SET deleted_at = NOW() WHERE id IN ({$ph}) AND deleted_at IS NULL");
                    $stM->execute($extraIds);
                } else {
                    $stM = $db->prepare("DELETE FROM table_match WHERE id IN ({$ph})");
                    $stM->execute($extraIds);
                }
            }
        }

        // Reload after pruning.
        $mStmt->execute([':round_id' => $knockoutRoundId]);
        $matches = $mStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Auto-create knockout matches if missing (or not enough), based on planned total.
    $neededMatches = $plannedTotalMatches;
    $existingMatches = is_array($matches) ? count($matches) : 0;
    if ($existingMatches < $neededMatches) {
        $insCols = ['round_id', 'match_no'];
        $insVals = [':round_id', ':match_no'];
        if (gk_has_column($db, 'table_match', 'status')) {
            $insCols[] = 'status';
            $insVals[] = ':status';
        }
        if (gk_has_column($db, 'table_match', 'created_at')) {
            $insCols[] = 'created_at';
            $insVals[] = ':created_at';
        }
        $insMatch = $db->prepare('INSERT INTO table_match (' . implode(',', $insCols) . ') VALUES (' . implode(',', $insVals) . ')');

        $nextNo = gk_next_match_no_for_knockout($db, $leagueRound, $knockoutRoundId);

        for ($k = $existingMatches; $k < $neededMatches; $k++) {
            $params = [
                ':round_id' => $knockoutRoundId,
                ':match_no' => $nextNo++,
            ];
            if (gk_has_column($db, 'table_match', 'status')) {
                $params[':status'] = 'scheduled';
            }
            if (gk_has_column($db, 'table_match', 'created_at')) {
                $params[':created_at'] = date('Y-m-d H:i:s');
            }
            $insMatch->execute($params);
        }

        // Reload latest knockout matches after auto-create.
        $mStmt->execute([':round_id' => $knockoutRoundId]);
        $matches = $mStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $mpCol = gk_pick_col($db, 'table_match_participant', ['participant_id', 'team_id', 'pasukan_id']) ?: 'participant_id';
    $countSql = "SELECT COUNT(*) FROM table_match_participant WHERE match_id = :match_id";
    if (gk_has_column($db, 'table_match_participant', 'deleted_at')) $countSql .= " AND deleted_at IS NULL";
    $countStmt = $db->prepare($countSql);

    $insertCols = ['match_id', $mpCol];
    $insertVals = [':match_id', ':participant_id'];
    if (gk_has_column($db, 'table_match_participant', 'created_at')) {
        $insertCols[] = 'created_at';
        $insertVals[] = ':created_at';
    }
    $insStmt = $db->prepare('INSERT INTO table_match_participant (' . implode(',', $insertCols) . ') VALUES (' . implode(',', $insertVals) . ')');

    $pairIdx = 0;
    foreach ($matches as $m) {
        if (!isset($pairs[$pairIdx])) break;
        $matchId = (int)$m['id'];

        $countStmt->execute([':match_id' => $matchId]);
        $existing = (int)$countStmt->fetchColumn();
        // Never overwrite.
        if ($existing >= 2) {
            $pairIdx++;
            continue;
        }
        if ($existing > 0) {
            // Partial filled considered manual state; keep as-is.
            $pairIdx++;
            continue;
        }

        [$home, $away] = $pairs[$pairIdx];
        if ($home <= 0 || $away <= 0 || $home === $away) {
            $pairIdx++;
            continue;
        }

        $paramsA = [':match_id' => $matchId, ':participant_id' => $home];
        $paramsB = [':match_id' => $matchId, ':participant_id' => $away];
        if (gk_has_column($db, 'table_match_participant', 'created_at')) {
            $now = date('Y-m-d H:i:s');
            $paramsA[':created_at'] = $now;
            $paramsB[':created_at'] = $now;
        }
        $insStmt->execute($paramsA);
        $insStmt->execute($paramsB);
        $pairIdx++;
    }

    // Auto-seed home teams for followup matches (if configured by current flow).
    // followup match index starts right after opening matches.
    if (!empty($autoFollowHomePids)) {
        for ($i = 0; $i < count($autoFollowHomePids); $i++) {
            $targetIdx = $openingMatchCount + $i;
            if (!isset($matches[$targetIdx])) break;
            $targetMatchId = (int)($matches[$targetIdx]['id'] ?? 0);
            $homePid = (int)$autoFollowHomePids[$i];
            gk_upsert_match_participant($db, $targetMatchId, $homePid, $mpCol);
        }
    }

    // Persist dynamic advance map for knockout auto-progression.
    if ($useQfPresetFlow) {
        $advanceMap = [];
        // QF winners -> SF
        if (isset($matches[0], $matches[1], $matches[2], $matches[3], $matches[4], $matches[5], $matches[6], $matches[7])) {
            $qf1 = (int)($matches[0]['match_no'] ?? 0);
            $qf2 = (int)($matches[1]['match_no'] ?? 0);
            $qf3 = (int)($matches[2]['match_no'] ?? 0);
            $qf4 = (int)($matches[3]['match_no'] ?? 0);
            $sf1 = (int)($matches[4]['match_no'] ?? 0);
            $sf2 = (int)($matches[5]['match_no'] ?? 0);
            $thirdNo = (int)($matches[6]['match_no'] ?? 0);
            $finalNo = (int)($matches[7]['match_no'] ?? 0);

            if ($qf1 > 0 && $sf1 > 0) $advanceMap[(string)$qf1][] = ['match_no' => $sf1, 'slot' => 'home', 'outcome' => 'winner'];
            if ($qf2 > 0 && $sf1 > 0) $advanceMap[(string)$qf2][] = ['match_no' => $sf1, 'slot' => 'away', 'outcome' => 'winner'];
            if ($qf3 > 0 && $sf2 > 0) $advanceMap[(string)$qf3][] = ['match_no' => $sf2, 'slot' => 'home', 'outcome' => 'winner'];
            if ($qf4 > 0 && $sf2 > 0) $advanceMap[(string)$qf4][] = ['match_no' => $sf2, 'slot' => 'away', 'outcome' => 'winner'];

            if ($sf1 > 0 && $thirdNo > 0) $advanceMap[(string)$sf1][] = ['match_no' => $thirdNo, 'slot' => 'home', 'outcome' => 'loser'];
            if ($sf2 > 0 && $thirdNo > 0) $advanceMap[(string)$sf2][] = ['match_no' => $thirdNo, 'slot' => 'away', 'outcome' => 'loser'];
            if ($sf1 > 0 && $finalNo > 0) $advanceMap[(string)$sf1][] = ['match_no' => $finalNo, 'slot' => 'home', 'outcome' => 'winner'];
            if ($sf2 > 0 && $finalNo > 0) $advanceMap[(string)$sf2][] = ['match_no' => $finalNo, 'slot' => 'away', 'outcome' => 'winner'];
        }
        if (!empty($advanceMap)) {
            $kRuleDecoded = [];
            if ($kRule !== '') {
                $tmp = json_decode($kRule, true);
                if (is_array($tmp)) $kRuleDecoded = $tmp;
            }
            if (!is_array($kRuleDecoded)) $kRuleDecoded = [];
            $kRuleDecoded['seed_slots'] = $customSeed;
            $kRuleDecoded['advance_map'] = $advanceMap;
            $updRule = $db->prepare("UPDATE table_round SET qualification_rule = :rule WHERE id = :id");
            $updRule->execute([
                ':rule' => json_encode($kRuleDecoded, JSON_UNESCAPED_UNICODE),
                ':id' => $knockoutRoundId,
            ]);
        }
    } elseif ($useSingleGroupTop4Preset) {
        $advanceMap = [];
        // SF1/SF2 -> Tempat ke-3 + Final
        if (isset($matches[0], $matches[1], $matches[2], $matches[3])) {
            $sf1 = (int)($matches[0]['match_no'] ?? 0);
            $sf2 = (int)($matches[1]['match_no'] ?? 0);
            $thirdNo = (int)($matches[2]['match_no'] ?? 0);
            $finalNo = (int)($matches[3]['match_no'] ?? 0);

            if ($sf1 > 0 && $thirdNo > 0) $advanceMap[(string)$sf1][] = ['match_no' => $thirdNo, 'slot' => 'home', 'outcome' => 'loser'];
            if ($sf2 > 0 && $thirdNo > 0) $advanceMap[(string)$sf2][] = ['match_no' => $thirdNo, 'slot' => 'away', 'outcome' => 'loser'];
            if ($sf1 > 0 && $finalNo > 0) $advanceMap[(string)$sf1][] = ['match_no' => $finalNo, 'slot' => 'home', 'outcome' => 'winner'];
            if ($sf2 > 0 && $finalNo > 0) $advanceMap[(string)$sf2][] = ['match_no' => $finalNo, 'slot' => 'away', 'outcome' => 'winner'];
        }
        if (!empty($advanceMap)) {
            $kRuleDecoded = [];
            if ($kRule !== '') {
                $tmp = json_decode($kRule, true);
                if (is_array($tmp)) $kRuleDecoded = $tmp;
            }
            if (!is_array($kRuleDecoded)) $kRuleDecoded = [];
            $kRuleDecoded['preset'] = 'single_group_top4_sf_3rd_final';
            $kRuleDecoded['advance_map'] = $advanceMap;
            $updRule = $db->prepare("UPDATE table_round SET qualification_rule = :rule WHERE id = :id");
            $updRule->execute([
                ':rule' => json_encode($kRuleDecoded, JSON_UNESCAPED_UNICODE),
                ':id' => $knockoutRoundId,
            ]);
        }
    } elseif (!empty($autoFollowHomePids)) {
        $advanceMap = [];
        // winners from opening -> semis (away)
        for ($i = 0; $i < count($autoFollowHomePids); $i++) {
            if (!isset($matches[$i], $matches[$openingMatchCount + $i])) continue;
            $srcNo = (int)($matches[$i]['match_no'] ?? 0);
            $dstNo = (int)($matches[$openingMatchCount + $i]['match_no'] ?? 0);
            if ($srcNo > 0 && $dstNo > 0) {
                $advanceMap[(string)$srcNo][] = ['match_no' => $dstNo, 'slot' => 'away', 'outcome' => 'winner'];
            }
        }

        // semis -> final & tempat 3/4
        if ($enablePlacementFinal) {
            $semi1Idx = $openingMatchCount;
            $semi2Idx = $openingMatchCount + 1;
            $thirdIdx = $openingMatchCount + 2;
            $finalIdx = $openingMatchCount + 3;
            if (isset($matches[$semi1Idx], $matches[$semi2Idx], $matches[$thirdIdx], $matches[$finalIdx])) {
                $semi1No = (int)($matches[$semi1Idx]['match_no'] ?? 0);
                $semi2No = (int)($matches[$semi2Idx]['match_no'] ?? 0);
                $thirdNo = (int)($matches[$thirdIdx]['match_no'] ?? 0);
                $finalNo = (int)($matches[$finalIdx]['match_no'] ?? 0);

                if ($semi1No > 0 && $thirdNo > 0) {
                    $advanceMap[(string)$semi1No][] = ['match_no' => $thirdNo, 'slot' => 'home', 'outcome' => 'loser'];
                }
                if ($semi2No > 0 && $thirdNo > 0) {
                    $advanceMap[(string)$semi2No][] = ['match_no' => $thirdNo, 'slot' => 'away', 'outcome' => 'loser'];
                }
                if ($semi1No > 0 && $finalNo > 0) {
                    $advanceMap[(string)$semi1No][] = ['match_no' => $finalNo, 'slot' => 'home', 'outcome' => 'winner'];
                }
                if ($semi2No > 0 && $finalNo > 0) {
                    $advanceMap[(string)$semi2No][] = ['match_no' => $finalNo, 'slot' => 'away', 'outcome' => 'winner'];
                }
            }
        }
        if (!empty($advanceMap)) {
            $kRuleDecoded = [];
            if ($kRule !== '') {
                $tmp = json_decode($kRule, true);
                if (is_array($tmp)) $kRuleDecoded = $tmp;
            }
            if (!is_array($kRuleDecoded)) $kRuleDecoded = [];
            if ($useCustomSeed) {
                $kRuleDecoded['seed_slots'] = $customSeed;
            } elseif ($useThreeGroupByeFlow && !empty($threeGroupAutoSeed)) {
                $kRuleDecoded['seed_slots'] = $threeGroupAutoSeed;
            }
            $kRuleDecoded['advance_map'] = $advanceMap;

            $updRule = $db->prepare("UPDATE table_round SET qualification_rule = :rule WHERE id = :id");
            $updRule->execute([
                ':rule' => json_encode($kRuleDecoded, JSON_UNESCAPED_UNICODE),
                ':id' => $knockoutRoundId,
            ]);
        }
    }

    $lockStmt = $db->prepare("UPDATE table_round SET is_locked = 1 WHERE id = :id");
    foreach ($leagueRounds as $lr) {
        $lockStmt->execute([':id' => (int)$lr['id']]);
    }

    $ongoingStmt = $db->prepare("UPDATE table_round SET status = 'ongoing' WHERE id = :id");
    $ongoingStmt->execute([':id' => $knockoutRoundId]);

    $db->commit();
    gk_redirect($roundId, 'success', 'Knockout stage generated successfully.');
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    error_log('[generate-knockout] ' . $e->getMessage());
    gk_redirect($roundId, 'danger', $e->getMessage());
}
