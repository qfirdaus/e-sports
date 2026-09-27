<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/rbac.php';

Session::start();
$auth = getAuth();
$auth->requireAuth();

$rbac = getRBAC();
$rbac->requirePageAccess('pages/bracket.php');

$page_title = 'Bracket';

function b_has_column(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $k = strtolower($table . '.' . $column);
    if (array_key_exists($k, $cache)) return (bool)$cache[$k];
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c');
    $stmt->execute([':t' => $table, ':c' => $column]);
    $ok = ((int)$stmt->fetchColumn() > 0);
    $cache[$k] = $ok;
    return $ok;
}

function b_pick_col(PDO $db, string $table, array $candidates): ?string
{
    foreach ($candidates as $c) if (b_has_column($db, $table, $c)) return $c;
    return null;
}

function b_has_result(PDO $db, int $matchId): bool
{
    if (b_has_column($db, 'table_match_result', 'match_id')) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM table_match_result WHERE match_id = :mid');
        $stmt->execute([':mid' => $matchId]);
        return ((int)$stmt->fetchColumn() > 0);
    }
    $sql = "SELECT COUNT(*) FROM table_match_result mr INNER JOIN table_match_participant mp ON mp.id = mr.match_participant_id WHERE mp.match_id = :mid";
    if (b_has_column($db, 'table_match_participant', 'deleted_at')) $sql .= " AND mp.deleted_at IS NULL";
    $stmt = $db->prepare($sql);
    $stmt->execute([':mid' => $matchId]);
    return ((int)$stmt->fetchColumn() > 0);
}

function b_get_round_sukan_id(PDO $db, int $roundId): int
{
    if ($roundId <= 0) return 0;
    if (b_has_column($db, 'table_round', 'event_id') && b_has_column($db, 'table_event', 'sukan_id')) {
        $sql = "
            SELECT e.sukan_id
            FROM table_round r
            INNER JOIN table_event e ON e.id = r.event_id
            WHERE r.id = :rid
        ";
        if (b_has_column($db, 'table_round', 'deleted_at')) $sql .= " AND r.deleted_at IS NULL";
        if (b_has_column($db, 'table_event', 'deleted_at')) $sql .= " AND e.deleted_at IS NULL";
        $sql .= " LIMIT 1";
        $st = $db->prepare($sql);
        $st->execute([':rid' => $roundId]);
        return (int)$st->fetchColumn();
    }
    if (b_has_column($db, 'table_round', 'sukan_id')) {
        $st = $db->prepare('SELECT sukan_id FROM table_round WHERE id = :rid LIMIT 1');
        $st->execute([':rid' => $roundId]);
        return (int)$st->fetchColumn();
    }
    return 0;
}

function b_fetch_venues(PDO $db, int $sukanId = 0): array
{
    if (!b_has_column($db, 'table_ref_venues', 'id') || !b_has_column($db, 'table_ref_venues', 'nama_venue')) {
        return [];
    }
    $hasSukanCol = b_has_column($db, 'table_ref_venues', 'sukan');
    $sql = "SELECT id, nama_venue";
    if (b_has_column($db, 'table_ref_venues', 'lokasi')) {
        $sql .= ", lokasi";
    } else {
        $sql .= ", '' AS lokasi";
    }
    if ($hasSukanCol && $sukanId > 0) {
        $sql .= ",
            CASE
                WHEN sukan = :sukan_exact THEN 1
                WHEN FIND_IN_SET(:sukan_csv, REPLACE(COALESCE(sukan,''), ' ', '')) > 0 THEN 1
                ELSE 0
            END AS is_recommended
        ";
    } else {
        $sql .= ", 0 AS is_recommended";
    }
    $sql .= " FROM table_ref_venues WHERE 1=1";
    if (b_has_column($db, 'table_ref_venues', 'status')) $sql .= " AND status = 1";
    if (b_has_column($db, 'table_ref_venues', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
    if ($hasSukanCol && $sukanId > 0) {
        $sql .= " ORDER BY is_recommended DESC, nama_venue ASC";
        $st = $db->prepare($sql);
        $st->execute([
            ':sukan_exact' => (string)$sukanId,
            ':sukan_csv' => (string)$sukanId,
        ]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $sql .= " ORDER BY nama_venue ASC";
    $st = $db->query($sql);
    return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function b_fetch_venue_detail_suggestions(PDO $db, int $sukanId): array
{
    if ($sukanId <= 0) return [];
    if (!b_has_column($db, 'table_match', 'venue_detail')) return [];
    if (!b_has_column($db, 'table_round', 'event_id')) return [];
    if (!b_has_column($db, 'table_event', 'sukan_id')) return [];

    $sql = "
        SELECT DISTINCT TRIM(m.venue_detail) AS venue_detail
        FROM table_match m
        INNER JOIN table_round r ON r.id = m.round_id
        INNER JOIN table_event e ON e.id = r.event_id
        WHERE e.sukan_id = :sukan_id
          AND m.venue_detail IS NOT NULL
          AND TRIM(m.venue_detail) <> ''
    ";
    if (b_has_column($db, 'table_match', 'deleted_at')) $sql .= " AND m.deleted_at IS NULL";
    if (b_has_column($db, 'table_round', 'deleted_at')) $sql .= " AND r.deleted_at IS NULL";
    if (b_has_column($db, 'table_event', 'deleted_at')) $sql .= " AND e.deleted_at IS NULL";
    $sql .= " ORDER BY venue_detail ASC LIMIT 100";
    $st = $db->prepare($sql);
    $st->execute([':sukan_id' => $sukanId]);
    $rows = $st->fetchAll(PDO::FETCH_COLUMN, 0);
    return is_array($rows)
        ? array_values(array_filter(array_map(static fn($v) => trim((string)$v), $rows), static fn($v) => $v !== ''))
        : [];
}

function b_fetch_venue_detail_suggestions_by_venue(PDO $db, int $venueId, int $sukanId = 0): array
{
    if ($venueId <= 0) return [];
    if (!b_has_column($db, 'table_match', 'venue_detail')) return [];
    if (!b_has_column($db, 'table_match', 'venue_id')) return [];

    $sql = "
        SELECT DISTINCT TRIM(m.venue_detail) AS venue_detail
        FROM table_match m
    ";
    $params = [':venue_id' => $venueId];

    $canScopeBySukan = (
        $sukanId > 0 &&
        b_has_column($db, 'table_round', 'event_id') &&
        b_has_column($db, 'table_event', 'sukan_id')
    );
    if ($canScopeBySukan) {
        $sql .= "
            INNER JOIN table_round r ON r.id = m.round_id
            INNER JOIN table_event e ON e.id = r.event_id
        ";
        $params[':sukan_id'] = $sukanId;
    }

    $sql .= "
        WHERE m.venue_id = :venue_id
          AND m.venue_detail IS NOT NULL
          AND TRIM(m.venue_detail) <> ''
    ";
    if (b_has_column($db, 'table_match', 'deleted_at')) $sql .= " AND m.deleted_at IS NULL";
    if ($canScopeBySukan) {
        if (b_has_column($db, 'table_round', 'deleted_at')) $sql .= " AND r.deleted_at IS NULL";
        if (b_has_column($db, 'table_event', 'deleted_at')) $sql .= " AND e.deleted_at IS NULL";
        $sql .= " AND e.sukan_id = :sukan_id";
    }
    $sql .= " ORDER BY venue_detail ASC LIMIT 100";

    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_COLUMN, 0);
    return is_array($rows)
        ? array_values(array_filter(array_map(static fn($v) => trim((string)$v), $rows), static fn($v) => $v !== ''))
        : [];
}

function b_build_slot_placeholders(array $round): array
{
    $out = [];
    $rule = trim((string)($round['qualification_rule'] ?? ''));
    if ($rule === '') return $out;
    $decoded = json_decode($rule, true);
    if (!is_array($decoded) || !isset($decoded['advance_map']) || !is_array($decoded['advance_map'])) {
        return $out;
    }

    foreach ($decoded['advance_map'] as $sourceNo => $targets) {
        $src = (int)$sourceNo;
        if ($src <= 0) continue;
        $labelFrom = static function (string $outcome, int $matchNo): string {
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

function b_format_datetime_local($value): string
{
    $s = trim((string)$value);
    if ($s === '') return '-';
    $ts = strtotime($s);
    if ($ts === false) return $s;
    return date('d/m/Y h:i A', $ts);
}

function b_extract_ref_match_no(string $label): array
{
    $out = [];
    if (preg_match_all('/\b(?:MENANG|PEMENANG|KALAH)\s+(\d+)\b/i', $label, $m)) {
        foreach (($m[1] ?? []) as $n) {
            $v = (int)$n;
            if ($v > 0) $out[] = $v;
        }
    }
    return array_values(array_unique($out));
}

function b_parse_rule_edges(array $round): array
{
    $edges = [];
    $rule = trim((string)($round['qualification_rule'] ?? ''));
    if ($rule === '') return $edges;
    $decoded = json_decode($rule, true);
    if (!is_array($decoded) || !isset($decoded['advance_map']) || !is_array($decoded['advance_map'])) {
        return $edges;
    }

    foreach ($decoded['advance_map'] as $sourceNo => $targets) {
        $src = (int)$sourceNo;
        if ($src <= 0) continue;
        if (is_array($targets) && array_is_list($targets)) {
            foreach ($targets as $t) {
                if (!is_array($t)) continue;
                $targetNo = (int)($t['match_no'] ?? 0);
                if ($targetNo <= 0) continue;
                $outcome = strtolower(trim((string)($t['outcome'] ?? 'winner')));
                if ($outcome !== 'loser' && $outcome !== 'kalah') $outcome = 'winner';
                else $outcome = 'loser';
                $slot = strtolower(trim((string)($t['slot'] ?? '')));
                if ($slot !== 'home' && $slot !== 'away') $slot = '';
                $edges[] = ['source' => $src, 'target' => $targetNo, 'outcome' => $outcome, 'slot' => $slot];
            }
        } elseif (is_array($targets)) {
            $targetNo = (int)($targets['match_no'] ?? 0);
            if ($targetNo <= 0) continue;
            $outcome = strtolower(trim((string)($targets['outcome'] ?? 'winner')));
            if ($outcome !== 'loser' && $outcome !== 'kalah') $outcome = 'winner';
            else $outcome = 'loser';
            $slot = strtolower(trim((string)($targets['slot'] ?? '')));
            if ($slot !== 'home' && $slot !== 'away') $slot = '';
            $edges[] = ['source' => $src, 'target' => $targetNo, 'outcome' => $outcome, 'slot' => $slot];
        } else {
            $targetNo = (int)$targets;
            if ($targetNo <= 0) continue;
            $edges[] = ['source' => $src, 'target' => $targetNo, 'outcome' => 'winner', 'slot' => ''];
        }
    }
    return $edges;
}

function b_is_concrete_team_name(string $name): bool
{
    $n = trim($name);
    if ($n === '' || $n === '-' || strtoupper($n) === 'BYE') return false;
    if (preg_match('/^(MENANG|PEMENANG|KALAH)\s+\d+$/i', $n)) return false;
    return true;
}

function b_group_rows_for_bracket(array $rows, array $sourceMap = []): array
{
    $levels = [];
    $byNo = [];
    foreach ($rows as $r) {
        $no = (int)($r['match_no'] ?? 0);
        if ($no <= 0) continue;
        $byNo[$no] = $r;
        $levels[$no] = 1;
    }
    if (empty($byNo)) return [];

    for ($i = 0; $i < 20; $i++) {
        $changed = false;
        foreach ($byNo as $no => $r) {
            $refs = $sourceMap[$no] ?? [];
            if (empty($refs)) {
                $refs = array_merge(
                    b_extract_ref_match_no((string)($r['home'] ?? '')),
                    b_extract_ref_match_no((string)($r['away'] ?? ''))
                );
            }
            $nextLevel = 1;
            foreach ($refs as $refNo) {
                $refLevel = $levels[$refNo] ?? 1;
                if (($refLevel + 1) > $nextLevel) $nextLevel = $refLevel + 1;
            }
            if ($nextLevel > ($levels[$no] ?? 1)) {
                $levels[$no] = $nextLevel;
                $changed = true;
            }
        }
        if (!$changed) break;
    }

    $grouped = [];
    foreach ($byNo as $no => $r) {
        $lv = $levels[$no] ?? 1;
        if (!isset($grouped[$lv])) $grouped[$lv] = [];
        $grouped[$lv][] = $r;
    }
    ksort($grouped);
    foreach ($grouped as &$g) {
        usort($g, static function ($a, $b) {
            return ((int)($a['match_no'] ?? 0)) <=> ((int)($b['match_no'] ?? 0));
        });
    }
    unset($g);

    return $grouped;
}

function b_bracket_level_label(int $level, int $maxLevel): string
{
    $distance = $maxLevel - $level;
    if ($distance === 0) return 'Akhir';
    if ($distance === 1) return 'Separuh Akhir';
    if ($distance === 2) return 'Suku Akhir';
    if ($distance === 3) return 'Pusingan 16';
    if ($distance === 4) return 'Pusingan 32';
    return 'Stage ' . $level;
}

function b_bracket_label_by_match_count(int $matchCount): string
{
    if ($matchCount >= 16) return 'Pusingan 32';
    if ($matchCount === 8) return 'Pusingan 16';
    if ($matchCount === 4) return 'SUKU AKHIR';
    if ($matchCount === 2) return 'SEPARUH AKHIR';
    if ($matchCount === 1) return 'Akhir';
    return 'Stage';
}

function b_main_round_label_by_index(int $mainCount, int $index): string
{
    // Special-case: some sports (e.g. sepak takraw format in this system) use a
    // single opening knockout layer before bronze/final; display it as SUKU AKHIR.
    if ($mainCount <= 1) return 'SUKU AKHIR';

    $distanceFromRight = ($mainCount - 1) - $index;
    if ($distanceFromRight <= 0) return 'SEPARUH AKHIR';
    if ($distanceFromRight === 1) return 'SUKU AKHIR';
    if ($distanceFromRight === 2) return 'Pusingan 16';
    if ($distanceFromRight === 3) return 'Pusingan 32';
    return 'Stage';
}

$db = getDB();
$roundId = isset($_GET['round_id']) ? (int)$_GET['round_id'] : 0;
$error = '';
$round = null;
$rows = [];
$bracketRounds = [];
$bracketMaxLevel = 0;
$bracketRoundLabels = [];
$matchSourcesMap = [];
$byeTeams = [];
$venues = [];
$venueDetailSuggestions = [];
$sukanIdByRound = 0;

if (isset($_GET['action']) && $_GET['action'] === 'load_venue_details_by_venue') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $venueIdReq = isset($_GET['venue_id']) ? (int)$_GET['venue_id'] : 0;
        $roundIdReq = isset($_GET['round_id']) ? (int)$_GET['round_id'] : 0;
        if ($venueIdReq <= 0 || $roundIdReq <= 0) {
            echo json_encode(['success' => true, 'details' => []]);
            exit;
        }
        $sukanReq = b_get_round_sukan_id($db, $roundIdReq);
        $details = b_fetch_venue_detail_suggestions_by_venue($db, $venueIdReq, $sukanReq);
        echo json_encode(['success' => true, 'details' => $details]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Unable to load venue suggestions.']);
    }
    exit;
}

if ($roundId <= 0) {
    $error = 'Invalid knockout round.';
} else {
    $stmt = $db->prepare("SELECT id, nama_round, group_code, round_type, status, qualification_rule FROM table_round WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $roundId]);
    $round = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$round || strtolower((string)$round['round_type']) !== 'knockout') {
        $error = 'This is not a knockout round.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_schedule') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $matchId = isset($_POST['match_id']) ? (int)$_POST['match_id'] : 0;
        $roundIdReq = isset($_POST['round_id']) ? (int)$_POST['round_id'] : 0;
        $tarikhRaw = trim((string)($_POST['tarikh'] ?? ''));
        $venueId = isset($_POST['venue_id']) && $_POST['venue_id'] !== '' ? (int)$_POST['venue_id'] : null;
        $venueDetail = trim((string)($_POST['venue_detail'] ?? ''));

        if ($matchId <= 0 || $roundIdReq <= 0) throw new RuntimeException('Match/Invalid round.');
        if ($tarikhRaw === '') throw new RuntimeException('Date and time are required.');

        $tarikh = str_replace('T', ' ', $tarikhRaw);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $tarikh)) $tarikh .= ':00';
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $tarikh)) {
            throw new RuntimeException('Invalid date format.');
        }

        $mSql = "SELECT id, round_id FROM table_match WHERE id = :id";
        if (b_has_column($db, 'table_match', 'deleted_at')) $mSql .= " AND deleted_at IS NULL";
        $mSql .= " LIMIT 1";
        $mSt = $db->prepare($mSql);
        $mSt->execute([':id' => $matchId]);
        $m = $mSt->fetch(PDO::FETCH_ASSOC);
        if (!$m) throw new RuntimeException('Match not found.');
        if ((int)$m['round_id'] !== $roundIdReq) throw new RuntimeException('Round mismatch.');

        if (b_has_result($db, $matchId)) {
            throw new RuntimeException('This match already has results. The schedule cannot be changed.');
        }

        if ($venueDetail !== '' && strlen($venueDetail) > 100) {
            throw new RuntimeException('Venue details exceed the maximum of 100 characters.');
        }

        if ($venueId !== null && $venueId > 0) {
            $vSql = "SELECT COUNT(*) FROM table_ref_venues WHERE id = :id";
            if (b_has_column($db, 'table_ref_venues', 'status')) $vSql .= " AND status = 1";
            if (b_has_column($db, 'table_ref_venues', 'deleted_at')) $vSql .= " AND deleted_at IS NULL";
            $vSt = $db->prepare($vSql);
            $vSt->execute([':id' => $venueId]);
            if ((int)$vSt->fetchColumn() === 0) {
                throw new RuntimeException('Invalid venue.');
            }
        } else {
            $venueId = null;
        }

        $set = ['tarikh = :tarikh'];
        $params = [':tarikh' => $tarikh, ':id' => $matchId];
        if (b_has_column($db, 'table_match', 'venue_id')) {
            $set[] = 'venue_id = :venue_id';
            $params[':venue_id'] = $venueId;
        }
        if (b_has_column($db, 'table_match', 'venue_detail')) {
            $set[] = 'venue_detail = :venue_detail';
            $params[':venue_detail'] = ($venueDetail === '' ? null : $venueDetail);
        }
        if (b_has_column($db, 'table_match', 'updated_at')) {
            $set[] = 'updated_at = NOW()';
        }
        if (b_has_column($db, 'table_match', 'updated_by') && Session::has('user_id')) {
            $set[] = 'updated_by = :updated_by';
            $params[':updated_by'] = (int)Session::get('user_id');
        }

        $uSql = "UPDATE table_match SET " . implode(', ', $set) . " WHERE id = :id";
        if (b_has_column($db, 'table_match', 'deleted_at')) $uSql .= " AND deleted_at IS NULL";
        $uSt = $db->prepare($uSql);
        $uSt->execute($params);

        echo json_encode(['success' => true, 'message' => 'Match schedule updated successfully.']);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($error === '') {
    $slotPlaceholders = b_build_slot_placeholders($round ?: []);
    $sukanIdByRound = b_get_round_sukan_id($db, $roundId);
    $venues = b_fetch_venues($db, $sukanIdByRound);
    $venueDetailSuggestions = b_fetch_venue_detail_suggestions($db, $sukanIdByRound);
    $venueNameSql = b_has_column($db, 'table_match', 'venue_id') ? "COALESCE(v.nama_venue, '')" : "''";
    $venueDetailSql = b_has_column($db, 'table_match', 'venue_detail') ? "COALESCE(m.venue_detail, '')" : "''";
    $venueJoin = b_has_column($db, 'table_match', 'venue_id') ? " LEFT JOIN table_ref_venues v ON v.id = m.venue_id " : "";

    $mSql = "SELECT m.id, m.round_id, m.match_no, m.status, COALESCE(m.tarikh, '') AS tarikh, {$venueNameSql} AS venue_name, {$venueDetailSql} AS venue_detail FROM table_match m {$venueJoin} WHERE m.round_id = :rid";
    if (b_has_column($db, 'table_match', 'deleted_at')) $mSql .= " AND m.deleted_at IS NULL";
    $mSql .= " ORDER BY match_no ASC, id ASC";
    $mStmt = $db->prepare($mSql);
    $mStmt->execute([':rid' => $roundId]);
    $matches = $mStmt->fetchAll(PDO::FETCH_ASSOC);

    $mpCol = b_pick_col($db, 'table_match_participant', ['participant_id', 'team_id', 'pasukan_id']) ?: 'participant_id';
    $pScoreSql = "''";
    if (b_has_column($db, 'table_match_result', 'match_participant_id') && b_has_column($db, 'table_match_result', 'score')) {
        $pScoreSql = "(SELECT mr.score FROM table_match_result mr WHERE mr.match_participant_id = mp.id";
        if (b_has_column($db, 'table_match_result', 'deleted_at')) $pScoreSql .= " AND mr.deleted_at IS NULL";
        $pScoreSql .= " AND mr.score IS NOT NULL AND TRIM(CAST(mr.score AS CHAR)) <> ''";
        if (b_has_column($db, 'table_match_result', 'id')) $pScoreSql .= " ORDER BY mr.id DESC";
        $pScoreSql .= " LIMIT 1)";
    }
    $pSql = "SELECT mp.id, p.nama_pasukan, {$pScoreSql} AS score FROM table_match_participant mp INNER JOIN table_pasukan p ON p.id = mp.{$mpCol} WHERE mp.match_id = :mid";
    if (b_has_column($db, 'table_match_participant', 'deleted_at')) $pSql .= " AND mp.deleted_at IS NULL";
    $pSql .= " ORDER BY mp.id ASC";
    $pStmt = $db->prepare($pSql);
    $scoreByMatchStmt = null;
    if (b_has_column($db, 'table_match_result', 'match_id') && b_has_column($db, 'table_match_result', 'score')) {
        $sql = "SELECT match_participant_id, score FROM table_match_result WHERE match_id = :mid";
        if (b_has_column($db, 'table_match_result', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
        $sql .= " AND score IS NOT NULL AND TRIM(CAST(score AS CHAR)) <> ''";
        if (b_has_column($db, 'table_match_result', 'id')) $sql .= " ORDER BY id ASC";
        $scoreByMatchStmt = $db->prepare($sql);
    }

    $existingMatchNos = [];
    $appendRowFromMatch = static function (array $m) use (&$rows, &$existingMatchNos, &$byeTeams, $pStmt, $scoreByMatchStmt, $slotPlaceholders, $db) {
        $mid = (int)$m['id'];
        $mno = (int)($m['match_no'] ?? 0);
        if ($mid <= 0 || $mno <= 0) return;
        if (isset($existingMatchNos[$mno])) return;
        $pStmt->execute([':mid' => $mid]);
        $ps = $pStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($ps) === 1) {
            $singleName = trim((string)($ps[0]['nama_pasukan'] ?? ''));
            if ($singleName !== '') $byeTeams[] = $singleName;
        }
        $home = $ps[0]['nama_pasukan'] ?? '-';
        $away = $ps[1]['nama_pasukan'] ?? '-';
        $homeScore = isset($ps[0]['score']) && $ps[0]['score'] !== null ? trim((string)$ps[0]['score']) : '';
        $awayScore = isset($ps[1]['score']) && $ps[1]['score'] !== null ? trim((string)$ps[1]['score']) : '';
        $homeWin = false;
        $awayWin = false;
        if (($homeScore === '' || $awayScore === '') && $scoreByMatchStmt) {
            $homeMpid = (int)($ps[0]['id'] ?? 0);
            $awayMpid = (int)($ps[1]['id'] ?? 0);
            $scoreByMatchStmt->execute([':mid' => $mid]);
            $mrRows = $scoreByMatchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!empty($mrRows)) {
                $byMpid = [];
                $free = [];
                foreach ($mrRows as $rr) {
                    $mpid = (int)($rr['match_participant_id'] ?? 0);
                    $scr = isset($rr['score']) ? trim((string)$rr['score']) : '';
                    if ($scr === '') continue;
                    if ($mpid > 0) $byMpid[$mpid] = $scr;
                    else $free[] = $scr;
                }
                if ($homeScore === '' && $homeMpid > 0 && isset($byMpid[$homeMpid])) $homeScore = (string)$byMpid[$homeMpid];
                if ($awayScore === '' && $awayMpid > 0 && isset($byMpid[$awayMpid])) $awayScore = (string)$byMpid[$awayMpid];
                if (($homeScore === '' || $awayScore === '') && count($free) >= 2) {
                    if ($homeScore === '') $homeScore = (string)$free[0];
                    if ($awayScore === '') $awayScore = (string)$free[1];
                }
            }
        }
        if ($homeScore !== '' && $awayScore !== '' && is_numeric((string)$homeScore) && is_numeric((string)$awayScore)) {
            $ha = (float)$homeScore;
            $hb = (float)$awayScore;
            if ($ha > $hb) $homeWin = true;
            elseif ($hb > $ha) $awayWin = true;
        }
        $phHome = $slotPlaceholders[$mno]['home'] ?? '';
        $phAway = $slotPlaceholders[$mno]['away'] ?? '';
        if ((string)$home === '-' && $phHome !== '') $home = $phHome;
        if ((string)$away === '-' && $phAway !== '') $away = $phAway;
        if ((string)$home === '-' && (string)$away !== '-') $home = 'BYE';
        if ((string)$away === '-' && (string)$home !== '-') $away = 'BYE';
        if ((string)$home === 'BYE' && (string)$away !== '' && (string)$away !== '-' && stripos((string)$away, 'MENANG ') !== 0 && stripos((string)$away, 'KALAH ') !== 0 && stripos((string)$away, 'PEMENANG ') !== 0) {
            $byeTeams[] = (string)$away;
        }
        if ((string)$away === 'BYE' && (string)$home !== '' && (string)$home !== '-' && stripos((string)$home, 'MENANG ') !== 0 && stripos((string)$home, 'KALAH ') !== 0 && stripos((string)$home, 'PEMENANG ') !== 0) {
            $byeTeams[] = (string)$home;
        }
        $hasResult = b_has_result($db, $mid);
        $rows[] = [
            'match_id' => $mid,
            'match_no' => $mno,
            'home' => $home,
            'away' => $away,
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'home_win' => $homeWin,
            'away_win' => $awayWin,
            'status' => $m['status'] ?? '',
            'tarikh' => $m['tarikh'] ?? '',
            'tarikh_display' => b_format_datetime_local($m['tarikh'] ?? ''),
            'venue_name' => $m['venue_name'] ?? '',
            'venue_detail' => $m['venue_detail'] ?? '',
            'has_result' => $hasResult,
        ];
        $existingMatchNos[$mno] = true;
    };

    foreach ($matches as $m) {
        $appendRowFromMatch($m);
    }

    $byeTeams = array_values(array_unique(array_map(static fn($v) => trim((string)$v), $byeTeams)));
    $edges = b_parse_rule_edges($round ?: []);
    $sourceMap = [];
    $hasLoserIncoming = [];
    $incomingWinnerByTarget = [];
    foreach ($edges as $e) {
        $targetNo = (int)($e['target'] ?? 0);
        $sourceNo = (int)($e['source'] ?? 0);
        if ($targetNo <= 0 || $sourceNo <= 0) continue;
        if (!isset($sourceMap[$targetNo])) $sourceMap[$targetNo] = [];
        $sourceMap[$targetNo][] = $sourceNo;
        if (($e['outcome'] ?? 'winner') === 'loser') {
            $hasLoserIncoming[$targetNo] = true;
        } else {
            if (!isset($incomingWinnerByTarget[$targetNo])) $incomingWinnerByTarget[$targetNo] = [];
            $incomingWinnerByTarget[$targetNo][] = [
                'source' => $sourceNo,
                'slot' => (string)($e['slot'] ?? '')
            ];
        }
    }
    foreach ($sourceMap as $k => $list) {
        $sourceMap[$k] = array_values(array_unique(array_map('intval', $list)));
    }
    $matchSourcesMap = $sourceMap;

    // Include feeder matches from previous/group rounds if advance_map references
    // source match numbers that are not in current knockout round rows.
    $missingSourceNos = [];
    foreach ($sourceMap as $targetNo => $srcList) {
        foreach ($srcList as $srcNo) {
            $sno = (int)$srcNo;
            if ($sno <= 0) continue;
            if (!isset($existingMatchNos[$sno])) $missingSourceNos[$sno] = true;
        }
    }
    // Fallback: derive source references from placeholder text shown in slots
    // (e.g. "PEMENANG 1", "KALAH 5") in case advance_map is incomplete.
    foreach ($rows as $_r) {
        $refs = array_merge(
            b_extract_ref_match_no((string)($_r['home'] ?? '')),
            b_extract_ref_match_no((string)($_r['away'] ?? ''))
        );
        foreach ($refs as $refNo) {
            $rno = (int)$refNo;
            if ($rno <= 0) continue;
            if (!isset($existingMatchNos[$rno])) $missingSourceNos[$rno] = true;
        }
    }
    // Strict mode: bracket view only shows knockout matches.
    // Do not auto-inject synthetic opening matches from inferred numbering.
    if (!empty($missingSourceNos)) {
        $nos = array_values(array_map('intval', array_keys($missingSourceNos)));
        if (!empty($nos)) {
            $in = implode(',', array_fill(0, count($nos), '?'));
            $minCurrentMatchId = 0;
            foreach ($matches as $_m0) {
                $mid0 = (int)($_m0['id'] ?? 0);
                if ($mid0 <= 0) continue;
                if ($minCurrentMatchId === 0 || $mid0 < $minCurrentMatchId) $minCurrentMatchId = $mid0;
            }

            $fetchWithSql = static function (PDO $db, string $sql, array $params): array {
                $st = $db->prepare($sql);
                $st->execute($params);
                return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            };

            $feederMatches = [];
            $eventId = 0;
            if (b_has_column($db, 'table_round', 'event_id')) {
                $rEvtStmt = $db->prepare("SELECT event_id FROM table_round WHERE id = :rid LIMIT 1");
                $rEvtStmt->execute([':rid' => $roundId]);
                $eventId = (int)$rEvtStmt->fetchColumn();
            }
            if ($eventId > 0) {
                $fSql = "
                    SELECT m.id, m.round_id, m.match_no, m.status, COALESCE(m.tarikh, '') AS tarikh, {$venueNameSql} AS venue_name, {$venueDetailSql} AS venue_detail,
                           CASE WHEN LOWER(COALESCE(r2.round_type,'')) = 'knockout' THEN 1 ELSE 0 END AS is_knockout
                    FROM table_match m
                    INNER JOIN table_round r2 ON r2.id = m.round_id
                    {$venueJoin}
                    WHERE r2.event_id = ?
                      AND LOWER(COALESCE(r2.round_type,'')) = 'knockout'
                      AND m.match_no IN ({$in})
                ";
                if (b_has_column($db, 'table_match', 'deleted_at')) $fSql .= " AND m.deleted_at IS NULL";
                if (b_has_column($db, 'table_round', 'deleted_at')) $fSql .= " AND r2.deleted_at IS NULL";
                $fSql .= " ORDER BY m.match_no ASC, is_knockout DESC, m.id ASC";
                $feederMatches = $fetchWithSql($db, $fSql, array_merge([$eventId], $nos));
            }

            // Fallback by sukan scope when event scope is unavailable/empty.
            if (empty($feederMatches)) {
                $sukanId = b_get_round_sukan_id($db, $roundId);
                if ($sukanId > 0 && b_has_column($db, 'table_round', 'event_id') && b_has_column($db, 'table_event', 'sukan_id')) {
                    $fSql = "
                        SELECT m.id, m.round_id, m.match_no, m.status, COALESCE(m.tarikh, '') AS tarikh, {$venueNameSql} AS venue_name, {$venueDetailSql} AS venue_detail,
                               CASE WHEN LOWER(COALESCE(r2.round_type,'')) = 'knockout' THEN 1 ELSE 0 END AS is_knockout
                        FROM table_match m
                        INNER JOIN table_round r2 ON r2.id = m.round_id
                        INNER JOIN table_event e2 ON e2.id = r2.event_id
                        {$venueJoin}
                        WHERE e2.sukan_id = ?
                          AND LOWER(COALESCE(r2.round_type,'')) = 'knockout'
                          AND m.match_no IN ({$in})
                    ";
                    if (b_has_column($db, 'table_match', 'deleted_at')) $fSql .= " AND m.deleted_at IS NULL";
                    if (b_has_column($db, 'table_round', 'deleted_at')) $fSql .= " AND r2.deleted_at IS NULL";
                    if (b_has_column($db, 'table_event', 'deleted_at')) $fSql .= " AND e2.deleted_at IS NULL";
                    $fSql .= " ORDER BY m.match_no ASC, is_knockout DESC, m.id ASC";
                    $feederMatches = $fetchWithSql($db, $fSql, array_merge([$sukanId], $nos));
                }
            }

            // Strict mode: do not fallback to non-knockout matches.

            foreach ($feederMatches as $fm) {
                $appendRowFromMatch($fm);
            }

            // Last-resort fallback: pick nearest historical knockout match by match_no globally.
            $stillMissing = [];
            foreach ($nos as $noX) {
                $nx = (int)$noX;
                if ($nx > 0 && !isset($existingMatchNos[$nx])) $stillMissing[] = $nx;
            }
            if (!empty($stillMissing)) {
                $gSqlBase = "
                    SELECT m.id, m.round_id, m.match_no, m.status, COALESCE(m.tarikh, '') AS tarikh, {$venueNameSql} AS venue_name, {$venueDetailSql} AS venue_detail,
                           CASE WHEN LOWER(COALESCE(r2.round_type,'')) = 'knockout' THEN 1 ELSE 0 END AS is_knockout
                    FROM table_match m
                    INNER JOIN table_round r2 ON r2.id = m.round_id
                    {$venueJoin}
                    WHERE m.match_no = :mno
                      AND LOWER(COALESCE(r2.round_type,'')) = 'knockout'
                ";
                if (b_has_column($db, 'table_match', 'deleted_at')) $gSqlBase .= " AND m.deleted_at IS NULL";
                if (b_has_column($db, 'table_round', 'deleted_at')) $gSqlBase .= " AND r2.deleted_at IS NULL";
                $gPrior = $gSqlBase;
                if ($minCurrentMatchId > 0) $gPrior .= " AND m.id < :min_id";
                $gPrior .= " ORDER BY is_knockout DESC, m.id DESC LIMIT 1";
                $gAny = $gSqlBase . " ORDER BY is_knockout DESC, m.id ASC LIMIT 1";
                $gPriorStmt = $db->prepare($gPrior);
                $gAnyStmt = $db->prepare($gAny);

                foreach ($stillMissing as $sx) {
                    $fm = null;
                    if ($minCurrentMatchId > 0) {
                        $gPriorStmt->execute([':mno' => $sx, ':min_id' => $minCurrentMatchId]);
                        $fm = $gPriorStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    }
                    if (!$fm) {
                        $gAnyStmt->execute([':mno' => $sx]);
                        $fm = $gAnyStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    }
                    if ($fm) {
                        $appendRowFromMatch($fm);
                        continue;
                    }
                    // Ensure at least a visible source block exists.
                    // Strict mode: skip synthetic placeholder rows for missing non-existent sources.
                }
            }
        }
    }

    // Normalize semifinal source ordering for the common pattern:
    // first semifinal should reference earlier opening matches.
    if (count($sourceMap) >= 2) {
        $targetNos = array_values(array_map('intval', array_keys($sourceMap)));
        sort($targetNos);
        if (count($targetNos) >= 2) {
            $tA = (int)$targetNos[0];
            $tB = (int)$targetNos[1];
            $aRefs = array_values(array_map('intval', $sourceMap[$tA] ?? []));
            $bRefs = array_values(array_map('intval', $sourceMap[$tB] ?? []));
            sort($aRefs);
            sort($bRefs);
            if (count($aRefs) >= 2 && count($bRefs) >= 2) {
                $minA = (int)$aRefs[0];
                $minB = (int)$bRefs[0];
                if ($minA > $minB) {
                    $sourceMap[$tA] = $bRefs;
                    $sourceMap[$tB] = $aRefs;
                    $matchSourcesMap = $sourceMap;
                }
            }
        }
    }

    usort($rows, static function ($a, $b) {
        return ((int)($a['match_no'] ?? 0)) <=> ((int)($b['match_no'] ?? 0));
    });

    $bracketRounds = b_group_rows_for_bracket($rows, $sourceMap);
    if (!empty($bracketRounds)) {
        $keys = array_map(static fn($k) => (int)$k, array_keys($bracketRounds));
        $bracketMaxLevel = (int)max($keys);

        $maxRows = $bracketRounds[$bracketMaxLevel] ?? [];
        if (!empty($maxRows)) {
            $bronzeRows = [];
            $finalRows = [];
            foreach ($maxRows as $mr) {
                $mno = (int)($mr['match_no'] ?? 0);
                if (isset($hasLoserIncoming[$mno])) $bronzeRows[] = $mr;
                else $finalRows[] = $mr;
            }
            if (!empty($bronzeRows) && !empty($finalRows)) {
                $bracketRounds[$bracketMaxLevel] = $bronzeRows;
                $bracketRounds[$bracketMaxLevel + 1] = $finalRows;
                $bracketMaxLevel++;
                ksort($bracketRounds);
            }
        }

        foreach ($bracketRounds as $lv => $_rowsLv) {
            $lvInt = (int)$lv;
            if ($lvInt === $bracketMaxLevel) {
                $bracketRoundLabels[$lvInt] = 'GOLD AND SILVER MEDAL MATCH';
                continue;
            }
            $allBronze = true;
            foreach ($_rowsLv as $_r) {
                $mno = (int)($_r['match_no'] ?? 0);
                if (!isset($hasLoserIncoming[$mno])) {
                    $allBronze = false;
                    break;
                }
            }
            if ($allBronze && !empty($_rowsLv)) {
                $bracketRoundLabels[$lvInt] = 'BRONZE MEDAL MATCH';
            }
        }

        // Main knockout rounds (non-bronze/non-final) are labeled by stage order
        // to support cases with BYE where two consecutive columns can have equal match counts.
        $mainLevels = [];
        foreach ($bracketRounds as $lv => $_rowsLv) {
            $lvInt = (int)$lv;
            if (isset($bracketRoundLabels[$lvInt])) continue;
            $mainLevels[] = $lvInt;
        }
        sort($mainLevels);
        $mainCount = count($mainLevels);
        foreach ($mainLevels as $i => $lvInt) {
            $bracketRoundLabels[$lvInt] = b_main_round_label_by_index($mainCount, (int)$i);
        }

        // Extra BYE detection:
        // If a target match has only 1 incoming winner source, the opposite slot is seeded directly (BYE).
        $rowsByMatchNo = [];
        foreach ($rows as $_r) {
            $rowsByMatchNo[(int)($_r['match_no'] ?? 0)] = $_r;
        }
        foreach ($incomingWinnerByTarget as $targetNo => $wins) {
            $tn = (int)$targetNo;
            if ($tn <= 0 || count($wins) !== 1) continue;
            if (!isset($rowsByMatchNo[$tn])) continue;
            $tr = $rowsByMatchNo[$tn];
            $slot = strtolower(trim((string)($wins[0]['slot'] ?? '')));
            $candidate = '';
            if ($slot === 'home') $candidate = (string)($tr['away'] ?? '');
            elseif ($slot === 'away') $candidate = (string)($tr['home'] ?? '');
            else {
                $h = (string)($tr['home'] ?? '');
                $a = (string)($tr['away'] ?? '');
                if (b_is_concrete_team_name($h) && !b_is_concrete_team_name($a)) $candidate = $h;
                elseif (b_is_concrete_team_name($a) && !b_is_concrete_team_name($h)) $candidate = $a;
            }
            if (b_is_concrete_team_name($candidate)) $byeTeams[] = $candidate;
        }
    }
}

ob_start();
?>
<div class="w-100 px-3">
    <div class="row mb-3">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <h4 class="mb-1">Bracket Knockout</h4>
                    <p class="text-muted mb-0">View knockout matches.</p>
                </div>
            </div>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['flash_msg']) && $_GET['flash_msg'] !== ''): ?>
        <?php $t = ($_GET['flash_type'] ?? 'info'); ?>
        <div class="alert alert-<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$_GET['flash_msg'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($error === ''): ?>
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong><?php echo htmlspecialchars((string)($round['nama_round'] ?? 'Knockout'), ENT_QUOTES, 'UTF-8'); ?></strong>
            <div class="d-inline-flex align-items-center gap-2">
                <div class="btn-group btn-group-sm" role="group" aria-label="Switch view">
                    <button type="button" class="btn btn-outline-primary active js-view-toggle" data-view="table">Table View</button>
                    <button type="button" class="btn btn-outline-primary js-view-toggle" data-view="bracket">Bracket View</button>
                </div>
                <a href="<?php echo htmlspecialchars(url('pages/round-standing.php'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-secondary">Back</a>
            </div>
        </div>
        <div class="card-body">
            <style>
                .bracket-scroll {
                    overflow-x: auto;
                    overflow-y: hidden;
                    padding-bottom: .5rem;
                }
                .bracket-canvas {
                    position: relative;
                    width: max-content;
                    min-width: 100%;
                }
                .bracket-lines {
                    position: absolute;
                    inset: 0;
                    width: 100%;
                    height: 100%;
                    pointer-events: none;
                    z-index: 1;
                }
                .bracket-board {
                    position: relative;
                    display: flex;
                    gap: 2.5rem;
                    z-index: 2;
                    padding: .35rem .5rem 1rem .5rem;
                }
                .bracket-col {
                    flex: 0 0 320px;
                    max-width: 320px;
                    min-width: 320px;
                }
                .bracket-col-title {
                    font-weight: 600;
                    font-size: .9rem;
                    color: #4f5d73;
                    margin-bottom: .75rem;
                }
                .bracket-col-matches {
                    display: flex;
                    flex-direction: column;
                    min-height: 100%;
                }
                .bracket-node {
                    border: 1px solid #d9e2ef;
                    border-radius: .65rem;
                    background: #fff;
                    box-shadow: 0 2px 8px rgba(15, 35, 60, .06);
                    overflow: hidden;
                    min-height: 132px;
                    position: relative;
                    z-index: 3;
                }
                .bracket-node.is-completed {
                    border-color: #b9dfc3;
                }
                .bracket-node.is-ongoing {
                    border-color: #f3d59a;
                }
                .bracket-node.is-scheduled {
                    border-color: #d9e2ef;
                }
                .bracket-node-head {
                    display: flex;
                    justify-content: space-between;
                    gap: .5rem;
                    padding: .45rem .7rem;
                    border-bottom: 1px solid #edf1f7;
                    background: #f7f9fc;
                    font-size: .78rem;
                    color: #607089;
                }
                .bracket-head-right {
                    display: inline-flex;
                    align-items: center;
                    gap: .35rem;
                }
                .bracket-result-pill {
                    font-size: .72rem;
                    font-weight: 700;
                    border-radius: 999px;
                    background: #edf3ff;
                    color: #34527a;
                    padding: .1rem .4rem;
                    line-height: 1.2;
                }
                .bracket-status-pill {
                    font-size: .72rem;
                    border-radius: 999px;
                    padding: .1rem .42rem;
                    line-height: 1.2;
                    border: 1px solid transparent;
                }
                .bracket-status-pill.st-completed {
                    background: #eaf8ee;
                    color: #1f7a3d;
                    border-color: #b9dfc3;
                }
                .bracket-status-pill.st-ongoing {
                    background: #fff8e8;
                    color: #8d5d00;
                    border-color: #f3d59a;
                }
                .bracket-status-pill.st-scheduled {
                    background: #f3f6fb;
                    color: #607089;
                    border-color: #d9e2ef;
                }
                .bracket-team {
                    padding: .5rem .7rem;
                    line-height: 1.2;
                    font-size: .93rem;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: .5rem;
                }
                .bracket-team + .bracket-team {
                    border-top: 1px solid #edf1f7;
                }
                .bracket-team.win {
                    background: #f1fbf4;
                }
                .bracket-team-name {
                    min-width: 0;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                }
                .bracket-team-score {
                    min-width: 20px;
                    text-align: right;
                    font-weight: 700;
                    color: #32445d;
                }
                .bracket-team.win .bracket-team-name,
                .bracket-team.win .bracket-team-score {
                    color: #1f7a3d;
                    font-weight: 800;
                }
                .bracket-team.lose {
                    opacity: .78;
                }
                .bracket-meta {
                    padding: .45rem .7rem .55rem .7rem;
                    border-top: 1px solid #edf1f7;
                    font-size: .75rem;
                    color: #75849b;
                    background: #fbfcfe;
                }
                .bracket-legend {
                    display: flex;
                    flex-wrap: wrap;
                    gap: .5rem 1rem;
                    margin-bottom: .65rem;
                    font-size: .78rem;
                    color: #5a6b84;
                }
                .bracket-toolbar {
                    display: flex;
                    flex-wrap: wrap;
                    justify-content: space-between;
                    align-items: center;
                    gap: .6rem 1rem;
                    margin-bottom: .55rem;
                }
                .bracket-toolbar-right {
                    display: inline-flex;
                    align-items: center;
                    gap: .45rem;
                }
                .bracket-toolbar .form-select,
                .bracket-toolbar .form-control {
                    min-width: 120px;
                }
                .bracket-node.is-filter-hidden {
                    display: none !important;
                }
                #view-bracket.bracket-enter .bracket-node {
                    animation: bracketNodeIn .36s ease both;
                    animation-delay: var(--bn-delay, 0ms);
                }
                .bracket-lines.is-anim path,
                .bracket-lines.is-anim line {
                    stroke-dasharray: 8 6;
                    animation: bracketLineIn .55s ease forwards;
                }
                .winner-flow {
                    fill: none;
                    stroke: #1f7a3d;
                    stroke-width: 2;
                    stroke-linecap: round;
                    stroke-linejoin: round;
                    stroke-dasharray: 10 8;
                    animation: winnerFlowMove 1.15s linear infinite;
                }
                @keyframes bracketNodeIn {
                    from { opacity: 0; transform: translateY(8px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                @keyframes bracketLineIn {
                    from { stroke-dashoffset: 24; opacity: .35; }
                    to { stroke-dashoffset: 0; opacity: 1; }
                }
                @keyframes winnerFlowMove {
                    from { stroke-dashoffset: 0; }
                    to { stroke-dashoffset: -36; }
                }
                .bracket-legend-item {
                    display: inline-flex;
                    align-items: center;
                    gap: .35rem;
                }
                .legend-dot {
                    width: 10px;
                    height: 10px;
                    border-radius: 50%;
                    display: inline-block;
                }
                .legend-dot.completed { background: #1f7a3d; }
                .legend-dot.ongoing { background: #d09100; }
                .legend-dot.scheduled { background: #8ea0b8; }
                .legend-dot.win { background: #1f7a3d; }
                .legend-dot.lose { background: #aeb8c7; }
                @media (max-width: 991.98px) {
                    .bracket-col {
                        flex-basis: 280px;
                        max-width: 280px;
                        min-width: 280px;
                    }
                }
                @media print {
                    @page { size: landscape; margin: 10mm; }
                    body * {
                        visibility: hidden !important;
                    }
                    #print-bracket-only,
                    #print-bracket-only * {
                        visibility: visible !important;
                    }
                    #print-bracket-only {
                        position: absolute;
                        left: 0;
                        top: 0;
                        width: 100%;
                    }
                    .js-view-toggle,
                    .bracket-toolbar,
                    .btn-edit-schedule,
                    #editScheduleModal,
                    .modal,
                    .card-header a.btn,
                    .card-header .btn {
                        display: none !important;
                    }
                    #view-table {
                        display: none !important;
                    }
                    #view-bracket {
                        display: block !important;
                    }
                    .bracket-legend,
                    .small.mt-2 {
                        display: none !important;
                    }
                    .card, .card-body, .w-100, .row, .col-12 {
                        box-shadow: none !important;
                    }
                }
            </style>

            <div id="view-table" class="js-view-pane">
                <div class="table-responsive">
                    <table class="table table-striped table-bordered align-middle">
                        <thead>
                            <tr>
                                <th class="text-center">Match No</th>
                                <th>Home Team</th>
                                <th>Away Team</th>
                                <th>Venue</th>
                                <th>Date/Time</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="7" class="text-muted text-center py-4">No knockout matches.</td></tr>
                            <?php else: ?>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="text-center"><?php echo htmlspecialchars((string)$r['match_no'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)$r['home'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)$r['away'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars(trim((string)($r['venue_name'] ?? '') . ((string)($r['venue_detail'] ?? '') !== '' ? ' / ' . (string)$r['venue_detail'] : '')) ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)($r['tarikh_display'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="text-center"><?php echo htmlspecialchars((string)$r['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="text-center">
                                            <?php if (!$r['has_result']): ?>
                                                <div class="d-inline-flex gap-1">
                                                    <a class="btn btn-sm btn-primary" href="<?php echo htmlspecialchars(url('pages/edit-knockout-match.php') . '?match_id=' . (int)$r['match_id'], ENT_QUOTES, 'UTF-8'); ?>">Team</a>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-secondary btn-edit-schedule"
                                                        data-match-id="<?php echo (int)$r['match_id']; ?>"
                                                        data-match-no="<?php echo htmlspecialchars((string)$r['match_no'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-tarikh="<?php echo htmlspecialchars((string)$r['tarikh'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-venue-name="<?php echo htmlspecialchars((string)$r['venue_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-venue-detail="<?php echo htmlspecialchars((string)$r['venue_detail'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    >
                                                        Jadual
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-secondary" disabled>Edit</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="view-bracket" class="js-view-pane d-none">
                <?php if (empty($rows)): ?>
                    <div class="text-muted py-3">No knockout matches to display.</div>
                <?php else: ?>
                    <div class="bracket-toolbar">
                        <div class="bracket-legend">
                            <span class="bracket-legend-item"><span class="legend-dot completed"></span>Completed</span>
                            <span class="bracket-legend-item"><span class="legend-dot ongoing"></span>Ongoing</span>
                            <span class="bracket-legend-item"><span class="legend-dot scheduled"></span>Scheduled</span>
                            <span class="bracket-legend-item"><span class="legend-dot win"></span>Winner</span>
                            <span class="bracket-legend-item"><span class="legend-dot lose"></span>Loss</span>
                        </div>
                        <div class="bracket-toolbar-right">
                            <label for="bracket-status-filter" class="small text-muted mb-0">Status</label>
                            <select id="bracket-status-filter" class="form-select form-select-sm">
                                <option value="all">All</option>
                                <option value="completed">Completed</option>
                                <option value="ongoing">Ongoing</option>
                                <option value="scheduled">Scheduled</option>
                            </select>
                            <label for="bracket-zoom" class="small text-muted mb-0">Zoom</label>
                            <select id="bracket-zoom" class="form-select form-select-sm">
                                <option value="0.8">80%</option>
                                <option value="1" selected>100%</option>
                                <option value="1.2">120%</option>
                            </select>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="bracket-print-btn">Print</button>
                        </div>
                    </div>
                    <div id="print-bracket-only">
                        <div id="bracket-scroll" class="bracket-scroll">
                            <div id="bracket-canvas" class="bracket-canvas">
                                <svg id="bracket-lines" class="bracket-lines" aria-hidden="true"></svg>
                                <div id="bracket-board" class="bracket-board">
                                <?php $colIdx = 0; ?>
                                <?php
                                    $firstRound = reset($bracketRounds);
                                    $baseMatchCount = max(1, is_array($firstRound) ? count($firstRound) : 1);
                                    $nodeHeightPx = 132;
                                    $baseGapPx = 28;
                                    $stageHeightPx = max($nodeHeightPx, ($baseMatchCount * $nodeHeightPx) + (max(0, $baseMatchCount - 1) * $baseGapPx));
                                ?>
                                <?php foreach ($bracketRounds as $level => $matchesAtLevel): ?>
                                    <?php
                                        $colIdx++;
                                        $colMatchCount = count($matchesAtLevel);
                                        $rowGap = 16;
                                        $topOffset = 0;
                                        if ($colMatchCount <= 0) {
                                            $topOffset = 0;
                                        } else {
                                            // Keep bracket nodes centered between parent pairs (e.g. SF between two QF boxes)
                                            $ratio = ($colMatchCount > 0) ? ($baseMatchCount / $colMatchCount) : 0;
                                            $ratioInt = (int)round($ratio);
                                            $isRatioInt = abs($ratio - $ratioInt) < 0.00001;
                                            $step = -1;
                                            if ($isRatioInt && $ratioInt >= 1) {
                                                $tmp = $ratioInt;
                                                $pow = 0;
                                                while ($tmp > 1 && ($tmp % 2) === 0) {
                                                    $tmp = (int)($tmp / 2);
                                                    $pow++;
                                                }
                                                if ($tmp === 1) $step = $pow;
                                            }

                                            if ($step >= 0) {
                                                $unit = $nodeHeightPx + $baseGapPx;
                                                $centerSpacing = (int)round($unit * (2 ** $step));
                                                $rowGap = (int)max(16, $centerSpacing - $nodeHeightPx);
                                                $topOffset = (int)max(0, round(((2 ** $step) - 1) * $unit / 2));
                                            } elseif ($colMatchCount === 1) {
                                                $topOffset = (int)max(0, floor(($stageHeightPx - $nodeHeightPx) / 2));
                                            } else {
                                                $rowGap = (int)max(16, floor(($stageHeightPx - ($colMatchCount * $nodeHeightPx)) / max(1, ($colMatchCount - 1))));
                                                $usedHeight = ($colMatchCount * $nodeHeightPx) + (($colMatchCount - 1) * $rowGap);
                                                $topOffset = (int)max(0, floor(($stageHeightPx - $usedHeight) / 2));
                                            }
                                        }
                                    ?>
                                    <div class="bracket-col" data-round="<?php echo (int)$colIdx; ?>">
                                        <?php $colRoundLabel = (string)($bracketRoundLabels[(int)$level] ?? b_bracket_level_label((int)$level, $bracketMaxLevel)); ?>
                                        <div class="bracket-col-title"><?php echo htmlspecialchars($colRoundLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="bracket-col-matches" style="min-height: <?php echo (int)$stageHeightPx; ?>px; padding-top: <?php echo (int)$topOffset; ?>px; gap: <?php echo (int)$rowGap; ?>px;">
                                            <?php foreach ($matchesAtLevel as $mIdx => $m): ?>
                                                <?php
                                                    $mno = (int)($m['match_no'] ?? 0);
                                                    $srcNos = $matchSourcesMap[$mno] ?? [];
                                                    $srcAttr = implode(',', array_map('intval', $srcNos));
                                                    $statusRaw = trim((string)($m['status'] ?? ''));
                                                    $statusKey = strtolower($statusRaw);
                                                    $nodeStatusClass = 'is-scheduled';
                                                    $pillStatusClass = 'st-scheduled';
                                                    if (in_array($statusKey, ['completed', 'selesai'], true)) {
                                                        $nodeStatusClass = 'is-completed';
                                                        $pillStatusClass = 'st-completed';
                                                    } elseif (in_array($statusKey, ['ongoing', 'in_progress', 'in progress', 'berlangsung'], true)) {
                                                        $nodeStatusClass = 'is-ongoing';
                                                        $pillStatusClass = 'st-ongoing';
                                                    }
                                                    $resultSummary = '';
                                                    if ((string)($m['home_score'] ?? '') !== '' && (string)($m['away_score'] ?? '') !== '') {
                                                        $resultSummary = (string)$m['home_score'] . '-' . (string)$m['away_score'];
                                                    }
                                                ?>
                                                <?php
                                                    $bnDelay = (($colIdx - 1) * 45) + (($mIdx % 8) * 25);
                                                ?>
                                                <div class="bracket-node <?php echo htmlspecialchars($nodeStatusClass, ENT_QUOTES, 'UTF-8'); ?>" data-status-key="<?php echo htmlspecialchars($statusKey !== '' ? $statusKey : 'scheduled', ENT_QUOTES, 'UTF-8'); ?>" data-round-name="<?php echo htmlspecialchars($colRoundLabel, ENT_QUOTES, 'UTF-8'); ?>" data-has-winner="<?php echo (!empty($m['home_win']) || !empty($m['away_win'])) ? '1' : '0'; ?>" data-node-round="<?php echo (int)$colIdx; ?>" data-node-index="<?php echo (int)$mIdx; ?>" data-match-no="<?php echo (int)$mno; ?>" data-source-nos="<?php echo htmlspecialchars($srcAttr, ENT_QUOTES, 'UTF-8'); ?>" style="--bn-delay: <?php echo (int)$bnDelay; ?>ms;">
                                                    <div class="bracket-node-head">
                                                        <span>Match <?php echo htmlspecialchars((string)$m['match_no'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                        <span class="bracket-head-right">
                                                            <?php if ($resultSummary !== ''): ?>
                                                                <span class="bracket-result-pill"><?php echo htmlspecialchars($resultSummary, ENT_QUOTES, 'UTF-8'); ?></span>
                                                            <?php endif; ?>
                                                            <span class="bracket-status-pill <?php echo htmlspecialchars($pillStatusClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusRaw !== '' ? $statusRaw : 'scheduled', ENT_QUOTES, 'UTF-8'); ?></span>
                                                        </span>
                                                    </div>
                                                    <div class="bracket-team<?php echo !empty($m['home_win']) ? ' win' : (!empty($m['away_win']) ? ' lose' : ''); ?>">
                                                        <span class="bracket-team-name"><?php echo htmlspecialchars((string)$m['home'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                        <span class="bracket-team-score"><?php echo htmlspecialchars((string)($m['home_score'] !== '' ? $m['home_score'] : ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                                    </div>
                                                    <div class="bracket-team<?php echo !empty($m['away_win']) ? ' win' : (!empty($m['home_win']) ? ' lose' : ''); ?>">
                                                        <span class="bracket-team-name"><?php echo htmlspecialchars((string)$m['away'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                        <span class="bracket-team-score"><?php echo htmlspecialchars((string)($m['away_score'] !== '' ? $m['away_score'] : ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                                    </div>
                                                    <div class="bracket-meta"><?php echo htmlspecialchars((string)($m['tarikh_display'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php if (!empty($byeTeams)): ?>
                        <div class="small mt-2">
                            <span class="text-danger fw-semibold">NOTE:</span>
                            <span class="text-muted"> The team receiving a bye is <?php echo htmlspecialchars(implode(', ', $byeTeams), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($error === ''): ?>
<div class="modal fade" id="editScheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update Knockout Schedule</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" data-coreui-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editScheduleForm" method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_schedule">
                    <input type="hidden" name="round_id" value="<?php echo (int)$roundId; ?>">
                    <input type="hidden" name="match_id" id="es-match-id" value="">

                    <div class="mb-2 small text-muted">Match No: <span id="es-match-no">-</span></div>

                    <div class="mb-3">
                        <label class="form-label">Date / Time</label>
                        <input type="datetime-local" class="form-control" name="tarikh" id="es-tarikh" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Venue</label>
                        <select class="form-select" name="venue_id" id="es-venue-id">
                            <option value="">-- Please Select --</option>
                            <?php foreach ($venues as $v): ?>
                                <option value="<?php echo (int)$v['id']; ?>" <?php echo ((int)($v['is_recommended'] ?? 0) === 1) ? 'data-recommended="1"' : ''; ?>>
                                    <?php echo htmlspecialchars((string)$v['nama_venue'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-1">
                        <label class="form-label">Venue Detail</label>
                        <input type="text" class="form-control" maxlength="100" name="venue_detail" id="es-venue-detail" list="es-venue-detail-list" placeholder="e.g. Court A">
                        <datalist id="es-venue-detail-list">
                            <?php foreach ($venueDetailSuggestions as $vd): ?>
                                <option value="<?php echo htmlspecialchars((string)$vd, ENT_QUOTES, 'UTF-8'); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-coreui-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var toggles = document.querySelectorAll('.js-view-toggle');
    var panes = {
        table: document.getElementById('view-table'),
        bracket: document.getElementById('view-bracket')
    };
    var bracketScrollEl = document.getElementById('bracket-scroll');
    var bracketCanvasEl = document.getElementById('bracket-canvas');
    var bracketLinesEl = document.getElementById('bracket-lines');
    var bracketBoardEl = document.getElementById('bracket-board');
    var bracketStatusFilterEl = document.getElementById('bracket-status-filter');
    var bracketZoomEl = document.getElementById('bracket-zoom');
    var bracketPrintBtnEl = document.getElementById('bracket-print-btn');
    var currentZoom = 1;

    function normalizeStatusKey(raw) {
        var v = (raw || '').toString().trim().toLowerCase();
        if (v === 'completed' || v === 'selesai') return 'completed';
        if (v === 'ongoing' || v === 'in_progress' || v === 'in progress' || v === 'berlangsung') return 'ongoing';
        return 'scheduled';
    }

    function normalizeRoundName(raw) {
        return (raw || '').toString().trim().toUpperCase();
    }

    function applyStatusFilter() {
        if (!bracketBoardEl) return;
        var filter = (bracketStatusFilterEl && bracketStatusFilterEl.value) ? bracketStatusFilterEl.value : 'all';
        bracketBoardEl.querySelectorAll('.bracket-node').forEach(function (node) {
            var key = normalizeStatusKey(node.getAttribute('data-status-key') || '');
            var hide = (filter !== 'all' && key !== filter);
            node.classList.toggle('is-filter-hidden', hide);
        });
        drawBracketLines();
    }

    function applyZoom(scale, persist) {
        if (!bracketCanvasEl || !bracketZoomEl) return;
        var z = parseFloat(scale);
        if (!isFinite(z) || z <= 0) z = 1;
        currentZoom = z;
        bracketCanvasEl.style.zoom = String(z);
        bracketZoomEl.value = String(z);
        if (persist) {
            try { window.localStorage.setItem('bracket_zoom', String(z)); } catch (_e) {}
        }
        setTimeout(drawBracketLines, 0);
    }

    function playBracketEnterAnimation() {
        var bracketPane = panes.bracket;
        if (!bracketPane) return;
        bracketPane.classList.remove('bracket-enter');
        if (bracketLinesEl) bracketLinesEl.classList.remove('is-anim');
        void bracketPane.offsetWidth;
        bracketPane.classList.add('bracket-enter');
        if (bracketLinesEl) bracketLinesEl.classList.add('is-anim');
        setTimeout(function () {
            bracketPane.classList.remove('bracket-enter');
            if (bracketLinesEl) bracketLinesEl.classList.remove('is-anim');
        }, 850);
    }

    function clearSvg(el) {
        if (!el) return;
        while (el.firstChild) el.removeChild(el.firstChild);
    }

    function drawConnector(svg, x1, y1, x2, y2) {
        if (!svg) return;
        x1 = Math.round(x1);
        y1 = Math.round(y1);
        x2 = Math.round(x2);
        y2 = Math.round(y2);
        var midX = Math.round(x1 + ((x2 - x1) * 0.5));
        var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', 'M ' + x1 + ' ' + y1 + ' L ' + midX + ' ' + y1 + ' L ' + midX + ' ' + y2 + ' L ' + x2 + ' ' + y2);
        path.setAttribute('fill', 'none');
        path.setAttribute('stroke', '#b4c2d8');
        path.setAttribute('stroke-width', '1.2');
        path.setAttribute('stroke-linecap', 'round');
        path.setAttribute('stroke-linejoin', 'round');
        svg.appendChild(path);
    }

    function getNodePointInCanvas(node, side) {
        var x = 0;
        var y = 0;
        var el = node;
        while (el && el !== bracketCanvasEl) {
            x += (el.offsetLeft || 0);
            y += (el.offsetTop || 0);
            el = el.offsetParent;
        }
        var w = node ? (node.offsetWidth || 0) : 0;
        var h = node ? (node.offsetHeight || 0) : 0;
        return {
            x: x + (side === 'right' ? w : 0),
            y: y + (h / 2)
        };
    }

    function drawLine(svg, x1, y1, x2, y2) {
        if (!svg) return;
        x1 = Math.round(x1);
        y1 = Math.round(y1);
        x2 = Math.round(x2);
        y2 = Math.round(y2);
        var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        line.setAttribute('x1', String(x1));
        line.setAttribute('y1', String(y1));
        line.setAttribute('x2', String(x2));
        line.setAttribute('y2', String(y2));
        line.setAttribute('stroke', '#b4c2d8');
        line.setAttribute('stroke-width', '1.2');
        line.setAttribute('stroke-linecap', 'round');
        svg.appendChild(line);
    }

    function drawWinnerFlowPath(svg, d) {
        if (!svg || !d) return;
        var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', d);
        path.setAttribute('class', 'winner-flow');
        svg.appendChild(path);
    }

    function drawBracketLines() {
        if (!bracketCanvasEl || !bracketLinesEl || !bracketBoardEl) return;
        if (!bracketCanvasEl.offsetWidth || !bracketCanvasEl.offsetHeight) return;

        var nodes = Array.prototype.slice.call(bracketBoardEl.querySelectorAll('.bracket-node:not(.is-filter-hidden)'));
        if (!nodes.length) {
            clearSvg(bracketLinesEl);
            return;
        }
        var nodeByMatchNo = {};
        nodes.forEach(function (node) {
            var mNo = parseInt(node.getAttribute('data-match-no') || '0', 10);
            if (mNo > 0) nodeByMatchNo[mNo] = node;
        });

        var svgWidth = Math.ceil(bracketCanvasEl.scrollWidth || bracketCanvasEl.offsetWidth || 1);
        var svgHeight = Math.ceil(bracketCanvasEl.scrollHeight || bracketCanvasEl.offsetHeight || 1);
        bracketLinesEl.setAttribute('viewBox', '0 0 ' + svgWidth + ' ' + svgHeight);
        bracketLinesEl.setAttribute('width', String(svgWidth));
        bracketLinesEl.setAttribute('height', String(svgHeight));
        clearSvg(bracketLinesEl);

        var incomingBySource = {};
        var incomingByTarget = {};
        nodes.forEach(function (targetNode) {
            var raw = (targetNode.getAttribute('data-source-nos') || '').trim();
            if (!raw) return;
            var sourceNos = raw.split(',').map(function (v) { return parseInt(v, 10); }).filter(function (v) { return !isNaN(v) && v > 0; });
            if (!sourceNos.length) return;

            var tp = getNodePointInCanvas(targetNode, 'left');
            var targetNo = parseInt(targetNode.getAttribute('data-match-no') || '0', 10);
            var targetPoint = {
                x2: tp.x + 2,
                y2: tp.y,
                targetNo: (!isNaN(targetNo) ? targetNo : 0)
            };

            sourceNos.forEach(function (srcNo) {
                if (!incomingBySource[srcNo]) incomingBySource[srcNo] = [];
                incomingBySource[srcNo].push(targetPoint);
                if (!incomingByTarget[targetNo]) incomingByTarget[targetNo] = [];
                incomingByTarget[targetNo].push(srcNo);
            });
        });

        var drawnEdge = {};
        function edgeKey(srcNo, tgtNo) { return String(srcNo) + '>' + String(tgtNo); }

        // 1) Source-branch connector (contoh SF -> Final + Bronze) kekal.
        Object.keys(incomingBySource).forEach(function (srcKey) {
            var srcNo = parseInt(srcKey, 10);
            if (isNaN(srcNo) || srcNo <= 0) return;
            var sourceNode = nodeByMatchNo[srcNo];
            if (!sourceNode) return;
            var sp = getNodePointInCanvas(sourceNode, 'right');
            var x1 = sp.x - 1;
            var y1 = sp.y;
            var sourceRoundName = normalizeRoundName(sourceNode.getAttribute('data-round-name') || '');
            var sourceHasWinner = (sourceNode.getAttribute('data-has-winner') || '0') === '1';
            var targets = incomingBySource[srcNo] || [];
            if (!targets.length) return;
            if (targets.length <= 1) return;
            var uniq = {};
            targets = targets.filter(function (t) {
                var key = String(Math.round(t.x2)) + ':' + String(Math.round(t.y2));
                if (uniq[key]) return false;
                uniq[key] = 1;
                return true;
            });

            targets.sort(function (a, b) { return a.y2 - b.y2; });
            var minTargetX = targets.reduce(function (acc, t) { return Math.min(acc, t.x2); }, Number.MAX_SAFE_INTEGER);
            var splitX = minTargetX - 52;
            if (splitX <= (x1 + 24)) splitX = x1 + 24;
            var minY = Math.min(y1, targets[0].y2);
            var maxY = Math.max(y1, targets[targets.length - 1].y2);

            drawLine(bracketLinesEl, x1, y1, splitX, y1);
            drawLine(bracketLinesEl, splitX, minY, splitX, maxY);
            targets.forEach(function (t) {
                drawLine(bracketLinesEl, splitX, t.y2, t.x2, t.y2);
                if (t.targetNo > 0) drawnEdge[edgeKey(srcNo, t.targetNo)] = 1;

                if (sourceHasWinner && t.targetNo > 0) {
                    var targetNode = nodeByMatchNo[t.targetNo] || null;
                    var targetRoundName = normalizeRoundName(targetNode ? (targetNode.getAttribute('data-round-name') || '') : '');
                    var allowFlow = (
                        (sourceRoundName === 'SUKU AKHIR' && targetRoundName === 'SEPARUH AKHIR') ||
                        (sourceRoundName === 'SEPARUH AKHIR' && (
                            targetRoundName === 'BRONZE MEDAL MATCH' ||
                            targetRoundName === 'GOLD AND SILVER MEDAL MATCH'
                        ))
                    );
                    if (allowFlow) {
                        var dBranch = 'M ' + Math.round(x1) + ' ' + Math.round(y1)
                            + ' L ' + Math.round(splitX) + ' ' + Math.round(y1)
                            + ' L ' + Math.round(splitX) + ' ' + Math.round(t.y2)
                            + ' L ' + Math.round(t.x2) + ' ' + Math.round(t.y2);
                        drawWinnerFlowPath(bracketLinesEl, dBranch);
                    }
                }
            });
        });

        // 2) Target-merge connector (contoh QF -> SF) untuk pastikan center tepat.
        Object.keys(incomingByTarget).forEach(function (tKey) {
            var targetNo = parseInt(tKey, 10);
            if (isNaN(targetNo) || targetNo <= 0) return;
            var srcNos = incomingByTarget[targetNo] || [];
            if (!srcNos.length) return;

            var targetNode = nodeByMatchNo[targetNo];
            if (!targetNode) return;
            var tp = getNodePointInCanvas(targetNode, 'left');
            var x2 = tp.x + 2;
            var y2 = tp.y;
            var targetRoundName = normalizeRoundName(targetNode.getAttribute('data-round-name') || '');

            var srcPoints = [];
            srcNos.forEach(function (srcNo) {
                var key = edgeKey(srcNo, targetNo);
                if (drawnEdge[key]) return;
                var sourceNode = nodeByMatchNo[srcNo];
                if (!sourceNode) return;
                var sp = getNodePointInCanvas(sourceNode, 'right');
                srcPoints.push({ srcNo: srcNo, x1: sp.x - 1, y1: sp.y });
            });
            if (!srcPoints.length) return;

            if (srcPoints.length === 1) {
                drawConnector(bracketLinesEl, srcPoints[0].x1, srcPoints[0].y1, x2, y2);
                drawnEdge[edgeKey(srcPoints[0].srcNo, targetNo)] = 1;
                return;
            }

            srcPoints.sort(function (a, b) { return a.y1 - b.y1; });
            var maxSourceX = srcPoints.reduce(function (acc, s) { return Math.max(acc, s.x1); }, 0);
            var mergeX = x2 - 52;
            if (mergeX <= (maxSourceX + 22)) mergeX = maxSourceX + 22;
            var minY = Math.min(y2, srcPoints[0].y1);
            var maxY = Math.max(y2, srcPoints[srcPoints.length - 1].y1);
            var midSourceY = (srcPoints[0].y1 + srcPoints[srcPoints.length - 1].y1) / 2;
            var mergeOutY = midSourceY;

            srcPoints.forEach(function (s) {
                drawLine(bracketLinesEl, s.x1, s.y1, mergeX, s.y1);
                drawnEdge[edgeKey(s.srcNo, targetNo)] = 1;
            });
            drawLine(bracketLinesEl, mergeX, minY, mergeX, maxY);
            drawLine(bracketLinesEl, mergeX, mergeOutY, x2, mergeOutY);

            // Highlight moving winner flow for key knockout transitions.
            srcPoints.forEach(function (s) {
                var sourceNode = nodeByMatchNo[s.srcNo];
                if (!sourceNode) return;
                var sourceRoundName = normalizeRoundName(sourceNode.getAttribute('data-round-name') || '');
                var hasWinner = (sourceNode.getAttribute('data-has-winner') || '0') === '1';
                if (!hasWinner) return;
                var allowFlow = (
                    (sourceRoundName === 'SUKU AKHIR' && targetRoundName === 'SEPARUH AKHIR') ||
                    (sourceRoundName === 'SEPARUH AKHIR' && (
                        targetRoundName === 'BRONZE MEDAL MATCH' ||
                        targetRoundName === 'GOLD AND SILVER MEDAL MATCH'
                    ))
                );
                if (!allowFlow) return;
                var d = 'M ' + Math.round(s.x1) + ' ' + Math.round(s.y1)
                    + ' L ' + Math.round(mergeX) + ' ' + Math.round(s.y1)
                    + ' L ' + Math.round(mergeX) + ' ' + Math.round(mergeOutY)
                    + ' L ' + Math.round(x2) + ' ' + Math.round(mergeOutY);
                drawWinnerFlowPath(bracketLinesEl, d);
            });
        });
    }

    function activateView(name) {
        var view = (name === 'bracket') ? 'bracket' : 'table';
        Object.keys(panes).forEach(function (k) {
            if (!panes[k]) return;
            panes[k].classList.toggle('d-none', k !== view);
        });
        toggles.forEach(function (btn) {
            var active = (btn.getAttribute('data-view') === view);
            btn.classList.toggle('active', active);
        });
        if (view === 'bracket') {
            applyStatusFilter();
            playBracketEnterAnimation();
            setTimeout(drawBracketLines, 0);
        }
    }

    toggles.forEach(function (btn) {
        btn.addEventListener('click', function () {
            activateView(btn.getAttribute('data-view') || 'table');
        });
    });
    activateView('table');
    if (bracketStatusFilterEl) {
        bracketStatusFilterEl.addEventListener('change', function () {
            applyStatusFilter();
        });
    }
    if (bracketZoomEl) {
        bracketZoomEl.addEventListener('change', function () {
            applyZoom(bracketZoomEl.value || '1', true);
        });
        var savedZoom = '1';
        try { savedZoom = window.localStorage.getItem('bracket_zoom') || '1'; } catch (_e) {}
        applyZoom(savedZoom, false);
    }
    if (bracketPrintBtnEl) {
        bracketPrintBtnEl.addEventListener('click', function () {
            activateView('bracket');
            document.body.classList.add('bracket-print-mode');
            setTimeout(function () { window.print(); }, 80);
        });
        window.addEventListener('afterprint', function () {
            document.body.classList.remove('bracket-print-mode');
        });
    }
    if (bracketScrollEl) {
        bracketScrollEl.addEventListener('scroll', drawBracketLines);
    }
    window.addEventListener('resize', drawBracketLines);

    var modalEl = document.getElementById('editScheduleModal');
    var formEl = document.getElementById('editScheduleForm');
    if (!modalEl || !formEl) return;

    var modal = null;
    if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
        modal = new window.bootstrap.Modal(modalEl);
    } else if (window.coreui && typeof window.coreui.Modal === 'function') {
        modal = new window.coreui.Modal(modalEl);
    }
    var idEl = document.getElementById('es-match-id');
    var noEl = document.getElementById('es-match-no');
    var tarikhEl = document.getElementById('es-tarikh');
    var venueIdEl = document.getElementById('es-venue-id');
    var venueDetailEl = document.getElementById('es-venue-detail');
    var venueDetailListEl = document.getElementById('es-venue-detail-list');
    var activeBtn = null;
    var pageRoundId = <?php echo (int)$roundId; ?>;
    var defaultVenueDetailSuggestions = [];
    if (venueDetailListEl) {
        defaultVenueDetailSuggestions = Array.prototype.map.call(
            venueDetailListEl.querySelectorAll('option'),
            function (o) { return (o.getAttribute('value') || '').toString().trim(); }
        ).filter(function (v) { return v !== ''; });
    }

    function hideModal() {
        if (modal && typeof modal.hide === 'function') {
            modal.hide();
            return;
        }
        modalEl.classList.remove('show');
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.style.display = 'none';
    }

    function toDatetimeLocal(v) {
        var s = (v || '').toString().trim();
        if (!s) return '';
        if (s.length >= 19) return s.substring(0, 16).replace(' ', 'T');
        if (s.length >= 16) return s.substring(0, 16).replace(' ', 'T');
        return '';
    }

    function toLocalDisplay(v) {
        var s = (v || '').toString().trim();
        if (!s) return '-';
        s = s.replace('T', ' ');
        var m = s.match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})/);
        if (!m) return s;
        var hh24 = parseInt(m[4], 10);
        if (isNaN(hh24)) hh24 = 0;
        var ampm = hh24 >= 12 ? 'PM' : 'AM';
        var hh12 = hh24 % 12;
        if (hh12 === 0) hh12 = 12;
        var hh = String(hh12).padStart(2, '0');
        return m[3] + '/' + m[2] + '/' + m[1] + ' ' + hh + ':' + m[5] + ' ' + ampm;
    }

    function findVenueOptionByName(name) {
        var n = (name || '').toString().trim().toLowerCase();
        if (!n || !venueIdEl) return '';
        for (var i = 0; i < venueIdEl.options.length; i++) {
            var txt = (venueIdEl.options[i].text || '').toLowerCase();
            if (txt.indexOf(n) !== -1) return venueIdEl.options[i].value;
        }
        return '';
    }

    function findRecommendedVenueOption() {
        if (!venueIdEl) return '';
        for (var i = 0; i < venueIdEl.options.length; i++) {
            var opt = venueIdEl.options[i];
            if (opt && opt.value && opt.getAttribute('data-recommended') === '1') {
                return opt.value;
            }
        }
        return '';
    }

    function renderVenueDetailSuggestions(items) {
        if (!venueDetailListEl) return;
        venueDetailListEl.innerHTML = '';
        (items || []).forEach(function (v) {
            var txt = (v || '').toString().trim();
            if (!txt) return;
            var opt = document.createElement('option');
            opt.value = txt;
            venueDetailListEl.appendChild(opt);
        });
    }

    function resetVenueDetailSuggestions() {
        renderVenueDetailSuggestions(defaultVenueDetailSuggestions);
    }

    function loadVenueDetailSuggestionsByVenue(venueId) {
        var vid = (venueId || '').toString().trim();
        if (!vid) {
            resetVenueDetailSuggestions();
            return;
        }
        var q = '?action=load_venue_details_by_venue'
            + '&round_id=' + encodeURIComponent(String(pageRoundId || 0))
            + '&venue_id=' + encodeURIComponent(vid);
        fetch(window.location.pathname + q, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.success) {
                    resetVenueDetailSuggestions();
                    return;
                }
                var rows = Array.isArray(j.details) ? j.details : [];
                if (!rows.length) {
                    resetVenueDetailSuggestions();
                    return;
                }
                renderVenueDetailSuggestions(rows);
            })
            .catch(function () {
                resetVenueDetailSuggestions();
            });
    }

    document.querySelectorAll('.btn-edit-schedule').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var matchId = btn.getAttribute('data-match-id') || '';
            var matchNo = btn.getAttribute('data-match-no') || '-';
            var tarikh = btn.getAttribute('data-tarikh') || '';
            var venueName = btn.getAttribute('data-venue-name') || '';
            var venueDetail = btn.getAttribute('data-venue-detail') || '';
            activeBtn = btn;

            idEl.value = matchId;
            noEl.textContent = matchNo;
            tarikhEl.value = toDatetimeLocal(tarikh);
            venueDetailEl.value = venueDetail;
            var v = findVenueOptionByName(venueName);
            venueIdEl.value = v || findRecommendedVenueOption() || '';
            loadVenueDetailSuggestionsByVenue(venueIdEl.value);

            if (modal && typeof modal.show === 'function') {
                modal.show();
            } else {
                // Last-resort fallback if modal library is unavailable
                modalEl.style.display = 'block';
                modalEl.classList.add('show');
                modalEl.removeAttribute('aria-hidden');
            }
        });
    });

    if (venueIdEl) {
        venueIdEl.addEventListener('change', function () {
            loadVenueDetailSuggestionsByVenue(venueIdEl.value || '');
        });
    }

    modalEl.querySelectorAll('[data-bs-dismiss="modal"], [data-coreui-dismiss="modal"], .btn-close').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            hideModal();
        });
    });

    formEl.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(formEl);
        fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j || !j.success) {
                if (window.Swal) {
                    Swal.fire('Error', (j && j.message) ? j.message : 'Unable to update the schedule.', 'error');
                } else {
                    alert((j && j.message) ? j.message : 'Unable to update the schedule.');
                }
                return;
            }

            // Update row in-place without full page refresh.
            if (activeBtn) {
                var tr = activeBtn.closest('tr');
                if (tr) {
                    var venueText = '';
                    var selectedOpt = venueIdEl.options[venueIdEl.selectedIndex];
                    if (selectedOpt && selectedOpt.value) {
                        venueText = (selectedOpt.text || '').trim();
                    }
                    var venueDetailText = (venueDetailEl.value || '').trim();
                    var venueDisplay = venueText;
                    if (venueDetailText) {
                        venueDisplay = venueDisplay ? (venueDisplay + ' / ' + venueDetailText) : venueDetailText;
                    }
                    if (!venueDisplay) venueDisplay = '-';

                    var tarikhDisplay = toLocalDisplay(tarikhEl.value || '');

                    var cells = tr.querySelectorAll('td');
                    if (cells.length >= 5) {
                        cells[3].textContent = venueDisplay;
                        cells[4].textContent = tarikhDisplay;
                    }

                    activeBtn.setAttribute('data-tarikh', tarikhEl.value || '');
                    activeBtn.setAttribute('data-venue-name', venueText || '');
                    activeBtn.setAttribute('data-venue-detail', venueDetailText || '');
                }
            }

            hideModal();

            if (window.Swal) {
                Swal.fire('Success', j.message || 'Schedule updated successfully.', 'success');
            } else {
                alert(j.message || 'Schedule updated successfully.');
            }
        })
        .catch(function () {
            if (window.Swal) Swal.fire('Error', 'Network error while attempting to update the schedule.', 'error');
            else alert('Network error while attempting to update the schedule.');
        });
    });
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>
