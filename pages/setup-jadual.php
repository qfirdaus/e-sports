<?php
/**
 * Schedule Setup - placeholder page for schedule management
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/rbac.php';

Session::start();
$auth = getAuth();
$auth->requireAuth();

// Enforce RBAC for this page
$rbac = getRBAC();
$rbac->requirePageAccess('pages/setup-jadual.php');

$page_title = 'Match Scheduling';

function sj_log(string $message): void {
    // Keep default PHP/Apache logging
    error_log($message);

    // Additional file log for easier debugging in Docker dev:
    // Host path: app/logs/setup-jadual.log
    try {
        $logDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'logs';
        if ($logDir && !is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        if ($logDir && is_dir($logDir) && is_writable($logDir)) {
            $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
            @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'setup-jadual.log', $line, FILE_APPEND);
        }
    } catch (Exception $e) {
        // silent fallback
    }
}

function sj_has_column(PDO $db, string $table, string $column): bool {
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) return (bool)$cache[$key];
    try {
        $sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :t
                  AND COLUMN_NAME = :c";
        $st = $db->prepare($sql);
        $st->execute([':t' => $table, ':c' => $column]);
        $ok = ((int)$st->fetchColumn() > 0);
        $cache[$key] = $ok;
        return $ok;
    } catch (Exception $e) {
        $cache[$key] = false;
        return false;
    }
}

function sj_match_group_col(PDO $db): ?string {
    if (sj_has_column($db, 'table_match', 'group_code')) return 'group_code';
    return null;
}

function sj_match_event_col(PDO $db): ?string {
    if (sj_has_column($db, 'table_match', 'event_id')) return 'event_id';
    return null;
}

function sj_participant_team_col(PDO $db): string {
    if (sj_has_column($db, 'table_match_participant', 'participant_id')) return 'participant_id';
    if (sj_has_column($db, 'table_match_participant', 'team_id')) return 'team_id';
    if (sj_has_column($db, 'table_match_participant', 'pasukan_id')) return 'pasukan_id';
    // fallback to existing expected name
    return 'participant_id';
}

function sj_get_event_sukan_id(PDO $db, int $eventId): int {
    if ($eventId <= 0) return 0;
    $st = $db->prepare('SELECT sukan_id FROM table_event WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $st->execute([':id' => $eventId]);
    return (int)$st->fetchColumn();
}

function sj_next_match_no(PDO $db, int $eventId): int {
    $matchEventCol = sj_match_event_col($db);
    if ($matchEventCol) {
        $st = $db->prepare("SELECT COALESCE(MAX(match_no),0)+1 FROM table_match WHERE {$matchEventCol} = :event_id AND deleted_at IS NULL");
        $st->execute([':event_id' => $eventId]);
        $next = (int)$st->fetchColumn();
        return $next > 0 ? $next : 1;
    }

    $st = $db->prepare("
        SELECT COALESCE(MAX(m.match_no),0)+1
        FROM table_match m
        INNER JOIN table_round r ON r.id = m.round_id
        WHERE r.event_id = :event_id
          AND m.deleted_at IS NULL
          AND r.deleted_at IS NULL
    ");
    $st->execute([':event_id' => $eventId]);
    $next = (int)$st->fetchColumn();
    return $next > 0 ? $next : 1;
}

// Utility: find round rows for event + nama_round
function _get_round_rows($db, $event_id, $nama_round) {
    $stmt = $db->prepare("SELECT id, group_code FROM table_round WHERE event_id = :event_id AND nama_round = :nama_round AND deleted_at IS NULL ORDER BY group_code ASC, group_order ASC");
    $stmt->execute([':event_id' => $event_id, ':nama_round' => $nama_round]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function sj_is_no_group_round(array $roundRows): bool {
    if (empty($roundRows)) return false;
    foreach ($roundRows as $r) {
        $c = trim((string)($r['group_code'] ?? ''));
        if ($c !== '') return false;
    }
    return true;
}

function sj_build_slot_placeholders(array $roundRow): array {
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
        }
    }
    return $out;
}

// --- AJAX endpoints ---
if (isset($_GET['action']) && $_GET['action'] === 'get_round_names' && isset($_GET['event_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $event_id = (int)$_GET['event_id'];
    if ($event_id <= 0) { echo json_encode(['success' => false]); exit; }
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT DISTINCT nama_round FROM table_round WHERE event_id = :event_id AND deleted_at IS NULL ORDER BY nama_round ASC");
        $stmt->execute([':event_id' => $event_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        echo json_encode(['success' => true, 'rounds' => $rows]);
    } catch (Exception $e) {
        sj_log('[setup-jadual get_round_names] ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'server_error']);
    }
    exit;
}

// load all rounds + groups + teams for an event in one request
if (isset($_GET['action']) && $_GET['action'] === 'load_event_all' && isset($_GET['event_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $event_id = (int)$_GET['event_id'];
    if ($event_id <= 0) { echo json_encode(['success' => false]); exit; }
    try {
        $db = getDB();
        $ev = $db->prepare('SELECT id, sukan_id FROM table_event WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $ev->execute([':id' => $event_id]);
        $evr = $ev->fetch(PDO::FETCH_ASSOC);
        if (!$evr) { echo json_encode(['success' => false, 'error' => 'event_not_found']); exit; }
        $sukan_id = (int)$evr['sukan_id'];

        // get distinct round names
        $stmt = $db->prepare("SELECT DISTINCT nama_round FROM table_round WHERE event_id = :event_id AND deleted_at IS NULL ORDER BY nama_round ASC");
        $stmt->execute([':event_id' => $event_id]);
        $rounds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        $groups_by_round = [];
        foreach ($rounds as $rname) {
            $rrows = _get_round_rows($db, $event_id, $rname);
            $codes = [];
            foreach ($rrows as $rr) { $c = trim((string)$rr['group_code']); if ($c !== '') $codes[] = $c; }
            $groups = [];
            if (!empty($codes)) {
                $q = $db->prepare('SELECT id, nama_pasukan, initial_group_code FROM table_pasukan WHERE sukan_id = :sukan_id AND initial_group_code IS NOT NULL AND initial_group_code != "" AND deleted_at IS NULL');
                $q->execute([':sukan_id' => $sukan_id]);
                $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $code = trim((string)$row['initial_group_code']);
                    if ($code === '') continue;
                    if (!in_array($code, $codes, true)) continue;
                    if (!isset($groups[$code])) $groups[$code] = [];
                    $groups[$code][] = ['id' => (int)$row['id'], 'nama_pasukan' => $row['nama_pasukan']];
                }
            } elseif (sj_is_no_group_round($rrows)) {
                $q = $db->prepare('SELECT id, nama_pasukan FROM table_pasukan WHERE sukan_id = :sukan_id AND status = 1 AND deleted_at IS NULL ORDER BY nama_pasukan ASC');
                $q->execute([':sukan_id' => $sukan_id]);
                $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                $groups['__ALL__'] = array_map(function($row){
                    return ['id' => (int)$row['id'], 'nama_pasukan' => $row['nama_pasukan']];
                }, $rows ?: []);
            }
            $groups_by_round[$rname] = $groups;
        }

        echo json_encode(['success' => true, 'rounds' => $rounds, 'groups_by_round' => $groups_by_round]);
    } catch (Exception $e) {
        sj_log('[setup-jadual load_event_all] ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'server_error']);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'load_groups' && isset($_GET['event_id']) && isset($_GET['nama_round'])) {
    header('Content-Type: application/json; charset=utf-8');
    $event_id = (int)$_GET['event_id'];
    $nama_round = trim((string)$_GET['nama_round']);
    if ($event_id <= 0 || $nama_round === '') { echo json_encode(['success' => false]); exit; }
    try {
        $db = getDB();
        $ev = $db->prepare('SELECT id, sukan_id FROM table_event WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $ev->execute([':id' => $event_id]);
        $evr = $ev->fetch(PDO::FETCH_ASSOC);
        if (!$evr) { echo json_encode(['success' => false, 'error' => 'event_not_found']); exit; }
        $sukan_id = (int)$evr['sukan_id'];

        // Load round rows ordered alphabetically by group_code
        $rstmt = $db->prepare("SELECT id, group_code FROM table_round WHERE event_id = :event_id AND nama_round = :nama_round AND deleted_at IS NULL ORDER BY group_code ASC, group_order ASC");
        $rstmt->execute([':event_id' => $event_id, ':nama_round' => $nama_round]);
        $roundRows = $rstmt->fetchAll(PDO::FETCH_ASSOC);

        $groups = [];
        // For each group_code in order, fetch teams ordered by nama_pasukan
        $teamStmt = $db->prepare('SELECT id, nama_pasukan, kontinjen_id, initial_group_code FROM table_pasukan WHERE sukan_id = :sukan_id AND initial_group_code = :code AND deleted_at IS NULL ORDER BY nama_pasukan ASC');
        foreach ($roundRows as $rr) {
            $code = trim((string)$rr['group_code']);
            if ($code === '') continue;
            $teamStmt->execute([':sukan_id' => $sukan_id, ':code' => $code]);
            $rows = $teamStmt->fetchAll(PDO::FETCH_ASSOC);
            $teams = [];
            foreach ($rows as $r) {
                $teams[] = ['id' => (int)$r['id'], 'nama_pasukan' => $r['nama_pasukan'], 'kontinjen_id' => isset($r['kontinjen_id']) ? (int)$r['kontinjen_id'] : null, 'initial_group_code' => $r['initial_group_code']];
            }
            $groups[] = ['group_code' => $code, 'teams' => $teams];
        }
        if (empty($groups) && sj_is_no_group_round($roundRows)) {
            $allStmt = $db->prepare('SELECT id, nama_pasukan, kontinjen_id, initial_group_code FROM table_pasukan WHERE sukan_id = :sukan_id AND status = 1 AND deleted_at IS NULL ORDER BY nama_pasukan ASC');
            $allStmt->execute([':sukan_id' => $sukan_id]);
            $rows = $allStmt->fetchAll(PDO::FETCH_ASSOC);
            $teams = [];
            foreach ($rows as $r) {
                $teams[] = ['id' => (int)$r['id'], 'nama_pasukan' => $r['nama_pasukan'], 'kontinjen_id' => isset($r['kontinjen_id']) ? (int)$r['kontinjen_id'] : null, 'initial_group_code' => $r['initial_group_code']];
            }
            $groups[] = ['group_code' => '__ALL__', 'teams' => $teams];
        }

        echo json_encode(['success' => true, 'groups' => $groups]);
    } catch (Exception $e) {
        sj_log('[setup-jadual load_groups] ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'server_error']);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_next_match_no' && isset($_GET['event_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $event_id = (int)$_GET['event_id'];
    if ($event_id <= 0) { echo json_encode(['success' => false]); exit; }
    try {
        $db = getDB();
        echo json_encode(['success' => true, 'next_match_no' => sj_next_match_no($db, $event_id)]);
    } catch (Exception $e) {
        sj_log('[setup-jadual get_next_match_no] ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'server_error']);
    }
    exit;
}

// load venues for modal selection
if (isset($_GET['action']) && $_GET['action'] === 'load_venues') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $db = getDB();
        $eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
        $sukanId = isset($_GET['sukan_id']) ? (int)$_GET['sukan_id'] : 0;
        if ($sukanId <= 0 && $eventId > 0) {
            $sukanId = sj_get_event_sukan_id($db, $eventId);
        }

        $hasSukanCol = sj_has_column($db, 'table_ref_venues', 'sukan');
        if ($hasSukanCol && $sukanId > 0) {
            $sql = "
                SELECT id, nama_venue, lokasi,
                       CASE
                           WHEN sukan = :sukan_exact THEN 1
                           WHEN FIND_IN_SET(:sukan_csv, REPLACE(COALESCE(sukan,''), ' ', '')) > 0 THEN 1
                           ELSE 0
                       END AS is_recommended
                FROM table_ref_venues
                WHERE status = 1 AND deleted_at IS NULL
                ORDER BY is_recommended DESC, nama_venue ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':sukan_exact' => (string)$sukanId,
                ':sukan_csv' => (string)$sukanId,
            ]);
        } else {
            $stmt = $db->prepare('SELECT id, nama_venue, lokasi, 0 AS is_recommended FROM table_ref_venues WHERE status = 1 AND deleted_at IS NULL ORDER BY nama_venue ASC');
            $stmt->execute();
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $disp = trim($r['nama_venue'] . (isset($r['lokasi']) && trim($r['lokasi']) !== '' ? ' (' . $r['lokasi'] . ')' : ''));
            $out[] = ['id' => (int)$r['id'], 'display' => $disp, 'is_recommended' => ((int)($r['is_recommended'] ?? 0) === 1 ? 1 : 0)];
        }
        echo json_encode(['success' => true, 'venues' => $out]);
    } catch (Exception $e) {
        sj_log('[setup-jadual load_venues] ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'server_error']);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'load_venue_details') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $db = getDB();
        $eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
        $sukanId = isset($_GET['sukan_id']) ? (int)$_GET['sukan_id'] : 0;
        if ($sukanId <= 0 && $eventId > 0) {
            $sukanId = sj_get_event_sukan_id($db, $eventId);
        }
        if ($sukanId <= 0) {
            echo json_encode(['success' => true, 'details' => []]);
            exit;
        }

        $sql = "
            SELECT DISTINCT TRIM(m.venue_detail) AS venue_detail
            FROM table_match m
            INNER JOIN table_round r ON r.id = m.round_id
            INNER JOIN table_event e ON e.id = r.event_id
            WHERE e.sukan_id = :sukan_id
              AND m.deleted_at IS NULL
              AND r.deleted_at IS NULL
              AND e.deleted_at IS NULL
              AND m.venue_detail IS NOT NULL
              AND TRIM(m.venue_detail) <> ''
            ORDER BY venue_detail ASC
            LIMIT 100
        ";
        $st = $db->prepare($sql);
        $st->execute([':sukan_id' => $sukanId]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN, 0);
        $rows = is_array($rows) ? array_values(array_filter(array_map(static fn($v) => trim((string)$v), $rows), static fn($v) => $v !== '')) : [];
        echo json_encode(['success' => true, 'details' => $rows]);
    } catch (Exception $e) {
        sj_log('[setup-jadual load_venue_details] ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'server_error']);
    }
    exit;
}

// list matches for selected round (grouped)
if (isset($_GET['action']) && $_GET['action'] === 'list_matches' && isset($_GET['event_id']) && isset($_GET['nama_round'])) {
    header('Content-Type: application/json; charset=utf-8');
    $event_id = (int)$_GET['event_id']; $nama_round = trim((string)$_GET['nama_round']);
    if ($event_id <= 0 || $nama_round === '') { echo json_encode(['success' => false]); exit; }
    try {
        $db = getDB();
        $roundRows = _get_round_rows($db, $event_id, $nama_round);
        if (empty($roundRows)) { echo json_encode(['success' => true, 'matches' => []]); exit; }
        $roundIds = array_map('intval', array_column($roundRows, 'id'));
        $roundMap = [];
        foreach ($roundRows as $rr) {
            $roundMap[(int)$rr['id']] = trim((string)($rr['group_code'] ?? ''));
        }
        $matchGroupCol = sj_match_group_col($db);
        $groupExpr = $matchGroupCol ? ('m.' . $matchGroupCol) : "''";
        $in = implode(',', array_fill(0, count($roundIds), '?'));
        $hasVenueId = sj_has_column($db, 'table_match', 'venue_id');
        $hasVenueDetail = sj_has_column($db, 'table_match', 'venue_detail');
        $venueIdSelect = $hasVenueId ? 'm.venue_id' : 'NULL';
        $venueDetailSelect = $hasVenueDetail ? 'm.venue_detail' : "''";
        $joinVenue = $hasVenueId ? 'LEFT JOIN table_ref_venues v ON v.id = m.venue_id' : '';
        $venueNameSelect = $hasVenueId ? "COALESCE(v.nama_venue, '')" : "''";

        $sql = "SELECT m.id, m.round_id, {$groupExpr} AS group_code, m.match_no, m.status, m.tarikh,
                       {$venueIdSelect} AS venue_id, {$venueDetailSelect} AS venue_detail, {$venueNameSelect} AS venue_name
                FROM table_match m
                {$joinVenue}
                WHERE m.round_id IN ($in) AND m.deleted_at IS NULL
                ORDER BY " . ($matchGroupCol ? "m.{$matchGroupCol} ASC, " : "") . "m.match_no ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($roundIds);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $roundPlaceholders = [];
        if (!empty($roundIds)) {
            $rin = implode(',', array_fill(0, count($roundIds), '?'));
            $rst = $db->prepare("SELECT id, qualification_rule FROM table_round WHERE id IN ({$rin})");
            $rst->execute($roundIds);
            $rrows = $rst->fetchAll(PDO::FETCH_ASSOC);
            if (is_array($rrows)) {
                foreach ($rrows as $rr) {
                    $rid = (int)($rr['id'] ?? 0);
                    if ($rid > 0) $roundPlaceholders[$rid] = sj_build_slot_placeholders($rr);
                }
            }
        }

        $out = [];
        $partTeamCol = sj_participant_team_col($db);
        foreach ($matches as $m) {
            $mid = (int)$m['id'];
            $pstmt = $db->prepare("SELECT p.id AS participant_row_id, p.{$partTeamCol} AS team_id, t.nama_pasukan
                                   FROM table_match_participant p
                                   JOIN table_pasukan t ON t.id = p.{$partTeamCol}
                                   WHERE p.match_id = :mid AND p.deleted_at IS NULL
                                   ORDER BY p.id ASC");
            $pstmt->execute([':mid' => $mid]);
            $parts = $pstmt->fetchAll(PDO::FETCH_ASSOC);
            $teams = [];
            foreach ($parts as $p) $teams[] = ['id' => (int)$p['team_id'], 'nama_pasukan' => $p['nama_pasukan']];
            $gcode = trim((string)($m['group_code'] ?? ''));
            if ($gcode === '') $gcode = (string)($roundMap[(int)$m['round_id']] ?? '');
            if ($gcode === '') $gcode = '__ALL__';
            $mno = (int)($m['match_no'] ?? 0);
            $rid = (int)($m['round_id'] ?? 0);
            $ph = $roundPlaceholders[$rid] ?? [];
            $phA = (string)($ph[$mno]['home'] ?? '');
            $phB = (string)($ph[$mno]['away'] ?? '');
            $teamALabel = (isset($teams[0]['nama_pasukan']) && trim((string)$teams[0]['nama_pasukan']) !== '')
                ? (string)$teams[0]['nama_pasukan']
                : $phA;
            $teamBLabel = (isset($teams[1]['nama_pasukan']) && trim((string)$teams[1]['nama_pasukan']) !== '')
                ? (string)$teams[1]['nama_pasukan']
                : $phB;
            $out[$gcode][] = [
                'id' => $mid,
                'match_no' => (int)$m['match_no'],
                'status' => $m['status'],
                'tarikh' => $m['tarikh'],
                'venue_id' => isset($m['venue_id']) ? (int)$m['venue_id'] : null,
                'venue_name' => $m['venue_name'] ?? '',
                'venue_detail' => $m['venue_detail'] ?? '',
                'teams' => $teams,
                'team_a_label' => $teamALabel,
                'team_b_label' => $teamBLabel
            ];
        }
        echo json_encode(['success' => true, 'matches' => $out]);
    } catch (Exception $e) { sj_log('[setup-jadual list_matches] '.$e->getMessage()); echo json_encode(['success'=>false, 'error'=>'server_error']); }
    exit;
}

// Add manual match
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_manual_match') {
    header('Content-Type: application/json; charset=utf-8');
    $event_id = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
    $nama_round = isset($_POST['nama_round']) ? trim((string)$_POST['nama_round']) : '';
    $group_code = isset($_POST['group_code']) ? trim((string)$_POST['group_code']) : '';
    $team_a = isset($_POST['team_a']) ? (int)$_POST['team_a'] : 0;
    $team_b = isset($_POST['team_b']) ? (int)$_POST['team_b'] : 0;
    $match_no = isset($_POST['match_no']) && $_POST['match_no'] !== '' ? (int)$_POST['match_no'] : null;
    $tarikh = isset($_POST['tarikh']) && $_POST['tarikh'] !== '' ? trim((string)$_POST['tarikh']) : null;
    $venue_id = isset($_POST['venue_id']) && $_POST['venue_id'] !== '' ? (int)$_POST['venue_id'] : null;
    $venue_detail = isset($_POST['venue_detail']) ? trim((string)$_POST['venue_detail']) : null;

    $errors = [];
    if ($event_id <= 0) $errors[] = 'Invalid event';
    if ($nama_round === '') $errors[] = 'No round selected';
    if ($team_a <= 0 || $team_b <= 0) $errors[] = 'Please select both teams';
    if ($team_a === $team_b) $errors[] = 'Team A and Team B must be different';
    if ($venue_detail !== null && strlen($venue_detail) > 100) $errors[] = 'Venue details are too long';
    if ($match_no !== null && $match_no <= 0) $errors[] = 'Invalid match number';
    if (!empty($errors)) { echo json_encode(['success'=>false,'errors'=>$errors]); exit; }

    try {
        $db = getDB();
        // ensure round exists and map to a round_id for this group
        $roundRows = _get_round_rows($db, $event_id, $nama_round);
        if (empty($roundRows)) { echo json_encode(['success'=>false,'errors'=>['No rounds found for this event']]); exit; }
        $isNoGroupRound = sj_is_no_group_round($roundRows);
        if (!$isNoGroupRound && $group_code === '') { echo json_encode(['success'=>false,'errors'=>['No group selected']]); exit; }
        $groupMap = [];
        foreach ($roundRows as $r) { $c = trim((string)$r['group_code']); if ($c !== '') $groupMap[$c] = (int)$r['id']; }
        if ($isNoGroupRound) {
            $round_id = (int)$roundRows[0]['id'];
            $group_code = '';
        } else {
            if (!isset($groupMap[$group_code])) { echo json_encode(['success'=>false,'errors'=>['No groups found for this round']]); exit; }
            $round_id = $groupMap[$group_code];
        }

        // In grouped rounds, enforce both teams in same group.
        if (!$isNoGroupRound) {
            $q = $db->prepare('SELECT id, initial_group_code FROM table_pasukan WHERE id IN (?, ?) AND deleted_at IS NULL');
            $q->execute([$team_a, $team_b]);
            $found = $q->fetchAll(PDO::FETCH_ASSOC);
            $map = [];
            foreach ($found as $f) $map[(int)$f['id']] = $f['initial_group_code'];
            if (!isset($map[$team_a]) || !isset($map[$team_b])) { echo json_encode(['success'=>false,'errors'=>['One of the teams was not found']]); exit; }
            if (trim((string)$map[$team_a]) !== $group_code || trim((string)$map[$team_b]) !== $group_code) { echo json_encode(['success'=>false,'errors'=>['Both teams must belong to the same group']]); exit; }
        }

        // Normalize datetime input from <input type="datetime-local"> (YYYY-MM-DDTHH:MM)
        if ($tarikh !== null && $tarikh !== '') {
            $tarikh = str_replace('T', ' ', $tarikh);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $tarikh)) {
                $tarikh .= ':00';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $tarikh)) {
                echo json_encode(['success'=>false,'errors'=>['Invalid date format']]);
                exit;
            }
        }

        // prevent duplicate pairing within same round
        $partTeamCol = sj_participant_team_col($db);
        $dupSql = "SELECT m.id FROM table_match m
                   JOIN table_match_participant p1 ON p1.match_id=m.id AND p1.{$partTeamCol}=:a
                   JOIN table_match_participant p2 ON p2.match_id=m.id AND p2.{$partTeamCol}=:b
                   WHERE m.round_id = :rid AND m.deleted_at IS NULL LIMIT 1";
        $dup = $db->prepare($dupSql);
        $dup->execute([':a'=>$team_a, ':b'=>$team_b, ':rid'=>$round_id]);
        if ($dup->fetch()) { echo json_encode(['success'=>false,'errors'=>['A match between these teams already exists in this round']]); exit; }

        // validate venue if provided
        $created_by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        if ($venue_id) {
            $vs = $db->prepare('SELECT id FROM table_ref_venues WHERE id = :id AND status = 1 AND deleted_at IS NULL LIMIT 1');
            $vs->execute([':id' => $venue_id]);
            if (!$vs->fetch()) { echo json_encode(['success'=>false,'errors'=>['Invalid venue']]); exit; }
        }

        // Auto next match number globally for this event (not by group)
        if ($match_no === null) {
            $match_no = sj_next_match_no($db, $event_id);
        } else {
            $globalNext = sj_next_match_no($db, $event_id);
            if ($match_no < $globalNext) $match_no = $globalNext;
        }

        // insert
        $db->beginTransaction();
        $inserted = false;
        $matchGroupCol = sj_match_group_col($db);
        $matchEventCol = sj_match_event_col($db);
        // Preferred schema (new columns)
        try {
            $insCols = ['round_id'];
            $insVals = [':round_id'];
            if ($matchEventCol) { $insCols[] = $matchEventCol; $insVals[] = ':event_id'; }
            if ($matchGroupCol) { $insCols[] = $matchGroupCol; $insVals[] = ':group_code'; }
            $insCols = array_merge($insCols, ['match_no','status','created_by','tarikh','venue_id','venue_detail','created_at']);
            $insVals = array_merge($insVals, [':match_no',':status',':created_by',':tarikh',':venue_id',':venue_detail','NOW()']);
            $insSql = 'INSERT INTO table_match (' . implode(',', $insCols) . ') VALUES (' . implode(',', $insVals) . ')';
            $ins = $db->prepare($insSql);
            $params = [
                ':round_id'=>$round_id,
                ':match_no'=>$match_no,
                ':status'=>'scheduled',
                ':created_by'=>$created_by,
                ':tarikh'=>$tarikh,
                ':venue_id'=>$venue_id,
                ':venue_detail'=>$venue_detail
            ];
            if ($matchEventCol) $params[':event_id'] = $event_id;
            if ($matchGroupCol) $params[':group_code'] = $group_code;
            $ins->execute($params);
            $inserted = true;
        } catch (Exception $schemaEx) {
            // Fallback schema compatibility (older production without venue/created_by columns)
            $insCols = ['round_id'];
            $insVals = [':round_id'];
            if ($matchEventCol) { $insCols[] = $matchEventCol; $insVals[] = ':event_id'; }
            if ($matchGroupCol) { $insCols[] = $matchGroupCol; $insVals[] = ':group_code'; }
            $insCols = array_merge($insCols, ['match_no','status','tarikh','created_at']);
            $insVals = array_merge($insVals, [':match_no',':status',':tarikh','NOW()']);
            $insSql = 'INSERT INTO table_match (' . implode(',', $insCols) . ') VALUES (' . implode(',', $insVals) . ')';
            $ins = $db->prepare($insSql);
            $params = [
                ':round_id'=>$round_id,
                ':match_no'=>$match_no,
                ':status'=>'scheduled',
                ':tarikh'=>$tarikh
            ];
            if ($matchEventCol) $params[':event_id'] = $event_id;
            if ($matchGroupCol) $params[':group_code'] = $group_code;
            $ins->execute($params);
            $inserted = true;
        }
        if (!$inserted) {
            throw new Exception('Unable to insert into table_match');
        }
        $mid = (int)$db->lastInsertId();
        $partTeamCol = sj_participant_team_col($db);
        $pins = $db->prepare("INSERT INTO table_match_participant (match_id, {$partTeamCol}, created_at) VALUES (:mid, :tid, NOW())");
        $pins->execute([':mid'=>$mid, ':tid'=>$team_a]);
        $pins->execute([':mid'=>$mid, ':tid'=>$team_b]);
        $db->commit();

        echo json_encode(['success'=>true,'match'=>['id'=>$mid,'match_no'=>$match_no,'group'=>$group_code]]);
    } catch (Exception $e) {
        if (isset($db) && $db && $db->inTransaction()) $db->rollBack();
        sj_log('[setup-jadual add_manual] '.$e->getMessage().' | event_id='.$event_id.' round='.$nama_round.' group='.$group_code.' a='.$team_a.' b='.$team_b.' match_no='.(string)$match_no.' tarikh='.(string)$tarikh);
        echo json_encode(['success'=>false,'errors'=>['Server error']]);
    }
    exit;
}

// Update manual match (scheduled only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_manual_match') {
    header('Content-Type: application/json; charset=utf-8');
    $match_id = isset($_POST['match_id']) ? (int)$_POST['match_id'] : 0;
    $event_id = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
    $nama_round = isset($_POST['nama_round']) ? trim((string)$_POST['nama_round']) : '';
    $group_code = isset($_POST['group_code']) ? trim((string)$_POST['group_code']) : '';
    $team_a = isset($_POST['team_a']) ? (int)$_POST['team_a'] : 0;
    $team_b = isset($_POST['team_b']) ? (int)$_POST['team_b'] : 0;
    $match_no = isset($_POST['match_no']) && $_POST['match_no'] !== '' ? (int)$_POST['match_no'] : null;
    $tarikh = isset($_POST['tarikh']) && $_POST['tarikh'] !== '' ? trim((string)$_POST['tarikh']) : null;
    $venue_id = isset($_POST['venue_id']) && $_POST['venue_id'] !== '' ? (int)$_POST['venue_id'] : null;
    $venue_detail = isset($_POST['venue_detail']) ? trim((string)$_POST['venue_detail']) : null;

    $errors = [];
    if ($match_id <= 0) $errors[] = 'Invalid match';
    if ($event_id <= 0) $errors[] = 'Invalid event';
    if ($nama_round === '') $errors[] = 'No round selected';
    if ($team_a <= 0 || $team_b <= 0) $errors[] = 'Please select both teams';
    if ($team_a === $team_b) $errors[] = 'Team A and Team B must be different';
    if ($venue_detail !== null && strlen($venue_detail) > 100) $errors[] = 'Venue details are too long';
    if ($match_no !== null && $match_no <= 0) $errors[] = 'Invalid match number';
    if (!empty($errors)) { echo json_encode(['success'=>false,'errors'=>$errors]); exit; }

    try {
        $db = getDB();
        $mchk = $db->prepare('SELECT id, round_id, status FROM table_match WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $mchk->execute([':id' => $match_id]);
        $mrow = $mchk->fetch(PDO::FETCH_ASSOC);
        if (!$mrow) { echo json_encode(['success'=>false,'errors'=>['Matches not found']]); exit; }
        if (strtolower((string)$mrow['status']) !== 'scheduled') { echo json_encode(['success'=>false,'errors'=>['Only scheduled matches can be updated']]); exit; }

        $roundRows = _get_round_rows($db, $event_id, $nama_round);
        if (empty($roundRows)) { echo json_encode(['success'=>false,'errors'=>['No rounds found for this event']]); exit; }
        $isNoGroupRound = sj_is_no_group_round($roundRows);
        if (!$isNoGroupRound && $group_code === '') { echo json_encode(['success'=>false,'errors'=>['No group selected']]); exit; }
        $groupMap = [];
        foreach ($roundRows as $r) { $c = trim((string)$r['group_code']); if ($c !== '') $groupMap[$c] = (int)$r['id']; }
        if ($isNoGroupRound) {
            $round_id = (int)$roundRows[0]['id'];
            $group_code = '';
        } else {
            if (!isset($groupMap[$group_code])) { echo json_encode(['success'=>false,'errors'=>['No groups found for this round']]); exit; }
            $round_id = $groupMap[$group_code];
        }

        if (!$isNoGroupRound) {
            $q = $db->prepare('SELECT id, initial_group_code FROM table_pasukan WHERE id IN (?, ?) AND deleted_at IS NULL');
            $q->execute([$team_a, $team_b]);
            $found = $q->fetchAll(PDO::FETCH_ASSOC);
            $map = [];
            foreach ($found as $f) $map[(int)$f['id']] = $f['initial_group_code'];
            if (!isset($map[$team_a]) || !isset($map[$team_b])) { echo json_encode(['success'=>false,'errors'=>['One of the teams was not found']]); exit; }
            if (trim((string)$map[$team_a]) !== $group_code || trim((string)$map[$team_b]) !== $group_code) { echo json_encode(['success'=>false,'errors'=>['Both teams must belong to the same group']]); exit; }
        }

        if ($tarikh !== null && $tarikh !== '') {
            $tarikh = str_replace('T', ' ', $tarikh);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $tarikh)) $tarikh .= ':00';
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $tarikh)) {
                echo json_encode(['success'=>false,'errors'=>['Invalid date format']]); exit;
            }
        }

        $partTeamCol = sj_participant_team_col($db);
        $dupSql = "SELECT m.id FROM table_match m
                   JOIN table_match_participant p1 ON p1.match_id=m.id AND p1.{$partTeamCol}=:a
                   JOIN table_match_participant p2 ON p2.match_id=m.id AND p2.{$partTeamCol}=:b
                   WHERE m.round_id = :rid AND m.deleted_at IS NULL AND m.id <> :mid LIMIT 1";
        $dup = $db->prepare($dupSql);
        $dup->execute([':a'=>$team_a, ':b'=>$team_b, ':rid'=>$round_id, ':mid'=>$match_id]);
        if ($dup->fetch()) { echo json_encode(['success'=>false,'errors'=>['A match between these teams already exists in this round']]); exit; }

        if ($venue_id) {
            $vs = $db->prepare('SELECT id FROM table_ref_venues WHERE id = :id AND status = 1 AND deleted_at IS NULL LIMIT 1');
            $vs->execute([':id' => $venue_id]);
            if (!$vs->fetch()) { echo json_encode(['success'=>false,'errors'=>['Invalid venue']]); exit; }
        }

        if ($match_no === null) {
            $match_no = sj_next_match_no($db, $event_id);
        }

        $db->beginTransaction();
        $matchGroupCol = sj_match_group_col($db);
        $matchEventCol = sj_match_event_col($db);
        $set = ['round_id = :round_id', 'match_no = :match_no', 'tarikh = :tarikh'];
        $params = [':id'=>$match_id, ':round_id'=>$round_id, ':match_no'=>$match_no, ':tarikh'=>$tarikh];
        if ($matchEventCol) { $set[] = "{$matchEventCol} = :event_id"; $params[':event_id'] = $event_id; }
        if ($matchGroupCol) { $set[] = "{$matchGroupCol} = :group_code"; $params[':group_code'] = $group_code; }
        if (sj_has_column($db, 'table_match', 'venue_id')) { $set[] = 'venue_id = :venue_id'; $params[':venue_id'] = $venue_id; }
        if (sj_has_column($db, 'table_match', 'venue_detail')) { $set[] = 'venue_detail = :venue_detail'; $params[':venue_detail'] = $venue_detail; }
        $up = $db->prepare('UPDATE table_match SET ' . implode(', ', $set) . ' WHERE id = :id AND deleted_at IS NULL');
        $up->execute($params);

        if (sj_has_column($db, 'table_match_participant', 'deleted_at')) {
            $db->prepare('UPDATE table_match_participant SET deleted_at = NOW() WHERE match_id = :mid AND deleted_at IS NULL')->execute([':mid' => $match_id]);
        } else {
            $db->prepare('DELETE FROM table_match_participant WHERE match_id = :mid')->execute([':mid' => $match_id]);
        }
        $pins = $db->prepare("INSERT INTO table_match_participant (match_id, {$partTeamCol}, created_at) VALUES (:mid, :tid, NOW())");
        $pins->execute([':mid'=>$match_id, ':tid'=>$team_a]);
        $pins->execute([':mid'=>$match_id, ':tid'=>$team_b]);

        $db->commit();
        echo json_encode(['success'=>true,'match'=>['id'=>$match_id,'match_no'=>$match_no,'group'=>$group_code]]);
    } catch (Exception $e) {
        if (isset($db) && $db && $db->inTransaction()) $db->rollBack();
        sj_log('[setup-jadual update_manual] ' . $e->getMessage() . ' | mid=' . $match_id);
        echo json_encode(['success'=>false,'errors'=>['Server error']]);
    }
    exit;
}

// delete match (soft-delete) — only allowed when all matches for the round are in 'scheduled' status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_match') {
    header('Content-Type: application/json; charset=utf-8');
    $match_id = isset($_POST['match_id']) ? (int)$_POST['match_id'] : 0;
    if ($match_id <= 0) { echo json_encode(['success'=>false,'errors'=>['Invalid match id']]); exit; }
    try {
        $db = getDB();
        $m = $db->prepare('SELECT id, round_id FROM table_match WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $m->execute([':id'=>$match_id]);
        $mr = $m->fetch(PDO::FETCH_ASSOC);
        if (!$mr) { echo json_encode(['success'=>false,'errors'=>['Match not found']]); exit; }
        $rid = (int)$mr['round_id'];
        // check statuses for all matches in this round
        $s = $db->prepare('SELECT COUNT(*) FROM table_match WHERE round_id = :rid AND deleted_at IS NULL AND status != :sched');
        $s->execute([':rid'=>$rid, ':sched'=>'scheduled']);
        $cnt = (int)$s->fetchColumn();
        if ($cnt > 0) { echo json_encode(['success'=>false,'errors'=>['Round is locked — some matches are not in scheduled status']]); exit; }

        $db->beginTransaction();
        $db->prepare('UPDATE table_match_participant SET deleted_at = NOW() WHERE match_id = :mid')->execute([':mid'=>$match_id]);
        $db->prepare('UPDATE table_match SET deleted_at = NOW() WHERE id = :mid')->execute([':mid'=>$match_id]);
        $db->commit();
        echo json_encode(['success'=>true]);
    } catch (Exception $e) {
        if (isset($db) && $db && $db->inTransaction()) $db->rollBack();
        sj_log('[setup-jadual delete_match] '.$e->getMessage());
        echo json_encode(['success'=>false,'errors'=>['Server error']]);
    }
    exit;
}

// POST: generate schedule (auto) — keep previous behavior but ensure no matches exist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_schedule') {
    header('Content-Type: application/json; charset=utf-8');
    $event_id = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
    $nama_round = isset($_POST['nama_round']) ? trim((string)$_POST['nama_round']) : '';
    if ($event_id <= 0 || $nama_round === '') { echo json_encode(['success' => false, 'errors' => ['Invalid parameter']]); exit; }

    try {
        $db = getDB();
        $ev = $db->prepare('SELECT id, sukan_id FROM table_event WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $ev->execute([':id' => $event_id]);
        $evr = $ev->fetch(PDO::FETCH_ASSOC);
        if (!$evr) { echo json_encode(['success' => false, 'errors' => ['Event not found']]); exit; }
        $sukan_id = (int)$evr['sukan_id'];

        $roundRows = _get_round_rows($db, $event_id, $nama_round);
        if (empty($roundRows)) { echo json_encode(['success' => false, 'errors' => ['No rounds found for this event']]); exit; }
        $groupCodes = [];
        $roundIds = [];
        foreach ($roundRows as $rr) { $gc = trim((string)$rr['group_code']); if ($gc === '') continue; $groupCodes[$gc] = (int)$rr['id']; $roundIds[] = (int)$rr['id']; }
        $isNoGroupRound = sj_is_no_group_round($roundRows);
        if (empty($groupCodes) && !$isNoGroupRound) { echo json_encode(['success' => false, 'errors' => ['No groups assigned to this round']]); exit; }
        if (empty($roundIds) && $isNoGroupRound) {
            $roundIds[] = (int)$roundRows[0]['id'];
            $groupCodes['__ALL__'] = (int)$roundRows[0]['id'];
        }

        $in = implode(',', array_fill(0, count($roundIds), '?'));
        $checkSql = "SELECT COUNT(*) AS c FROM table_match WHERE round_id IN ($in) AND deleted_at IS NULL";
        $chk = $db->prepare($checkSql);
        $chk->execute($roundIds);
        $cnt = (int)$chk->fetchColumn();
        if ($cnt > 0 && !$isNoGroupRound) {
            $sampleSql = "SELECT id, match_no, round_id, status FROM table_match WHERE round_id IN ($in) AND deleted_at IS NULL ORDER BY match_no ASC, id ASC LIMIT 8";
            $sst = $db->prepare($sampleSql);
            $sst->execute($roundIds);
            $sampleRows = $sst->fetchAll(PDO::FETCH_ASSOC);
            $sampleNos = [];
            foreach ($sampleRows as $sr) {
                $no = isset($sr['match_no']) ? (int)$sr['match_no'] : 0;
                $sampleNos[] = $no > 0 ? ('#' . $no) : ('ID:' . (int)$sr['id']);
            }
            $sampleText = !empty($sampleNos) ? (' (e.g. ' . implode(', ', $sampleNos) . ')') : '';
            echo json_encode([
                'success' => false,
                'errors' => ["The schedule cannot be generated because {$cnt} matches already exist for this round{$sampleText}."],
                'debug' => [
                    'event_id' => $event_id,
                    'nama_round' => $nama_round,
                    'round_ids' => $roundIds,
                    'existing_match_count' => $cnt,
                    'existing_matches_sample' => $sampleRows,
                ],
            ]);
            exit;
        }

        // Knockout direct (no-group): allow incremental generation.
        // If opening matches already exist, generate only downstream matches
        // declared in qualification_rule.advance_map (e.g. 5,6,7,8).
        if ($isNoGroupRound) {
            $ridPrimary = (int)$roundIds[0];
            $qRuleStmt = $db->prepare("SELECT qualification_rule FROM table_round WHERE id = :id LIMIT 1");
            $qRuleStmt->execute([':id' => $ridPrimary]);
            $qRuleRaw = (string)$qRuleStmt->fetchColumn();
            $qRule = $qRuleRaw !== '' ? json_decode($qRuleRaw, true) : null;
            $advanceMap = (is_array($qRule) && isset($qRule['advance_map']) && is_array($qRule['advance_map'])) ? $qRule['advance_map'] : [];

            // Existing match numbers in this knockout round.
            $existingStmt = $db->prepare("SELECT match_no FROM table_match WHERE round_id = :rid AND deleted_at IS NULL");
            $existingStmt->execute([':rid' => $ridPrimary]);
            $existingNos = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);
            $existingNosSet = [];
            foreach ($existingNos as $n) if ($n > 0) $existingNosSet[$n] = true;

            $targets = [];
            foreach ($advanceMap as $srcNo => $maps) {
                if (is_array($maps) && array_is_list($maps)) {
                    foreach ($maps as $m) {
                        $tn = (int)($m['match_no'] ?? 0);
                        if ($tn > 0) $targets[$tn] = true;
                    }
                } elseif (is_array($maps)) {
                    $tn = (int)($maps['match_no'] ?? 0);
                    if ($tn > 0) $targets[$tn] = true;
                }
            }
            $targetNos = array_keys($targets);
            sort($targetNos, SORT_NUMERIC);

            if (empty($targetNos)) {
                // Fallback default map (standard 8-match flow):
                // 1v2 -> 5, 3v4 -> 6, losers 5/6 -> 7 (bronze), winners 5/6 -> 8 (final).
                $haveOpening = (isset($existingNosSet[1], $existingNosSet[2], $existingNosSet[3], $existingNosSet[4]));
                if ($haveOpening) {
                    $advanceMap = [
                        '1' => [['match_no' => 5, 'slot' => 'home', 'outcome' => 'winner']],
                        '2' => [['match_no' => 5, 'slot' => 'away', 'outcome' => 'winner']],
                        '3' => [['match_no' => 6, 'slot' => 'home', 'outcome' => 'winner']],
                        '4' => [['match_no' => 6, 'slot' => 'away', 'outcome' => 'winner']],
                        '5' => [
                            ['match_no' => 7, 'slot' => 'home', 'outcome' => 'loser'],
                            ['match_no' => 8, 'slot' => 'home', 'outcome' => 'winner'],
                        ],
                        '6' => [
                            ['match_no' => 7, 'slot' => 'away', 'outcome' => 'loser'],
                            ['match_no' => 8, 'slot' => 'away', 'outcome' => 'winner'],
                        ],
                    ];
                    $targetNos = [5, 6, 7, 8];

                    // Persist fallback map into qualification_rule for future bracket rendering.
                    $ruleDecoded = [];
                    if ($qRuleRaw !== '') {
                        $tmpRule = json_decode($qRuleRaw, true);
                        if (is_array($tmpRule)) $ruleDecoded = $tmpRule;
                    }
                    $ruleDecoded['advance_map'] = $advanceMap;
                    $uq = $db->prepare("UPDATE table_round SET qualification_rule = :rule WHERE id = :id");
                    $uq->execute([
                        ':rule' => json_encode($ruleDecoded, JSON_UNESCAPED_UNICODE),
                        ':id' => $ridPrimary,
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'errors' => ['Knockout rules (advance_map) not found. Check the Knockout Rule Editor or ensure matches 1–4 exist.'],
                        'debug' => [
                            'existing_match_nos' => array_values($existingNos),
                            'required_opening_match_nos' => [1, 2, 3, 4],
                        ],
                    ]);
                    exit;
                }
            }

            $toCreate = [];
            foreach ($targetNos as $tn) {
                if (!isset($existingNosSet[$tn])) $toCreate[] = (int)$tn;
            }
            if (empty($toCreate)) {
                echo json_encode([
                    'success' => true,
                    'matches' => ['__ALL__' => []],
                    'message' => 'All subsequent matches already exist. No new matches were generated.',
                    'debug' => [
                        'existing_match_nos' => array_values($existingNos),
                        'target_match_nos' => array_values($targetNos),
                        'created_match_nos' => [],
                    ],
                ]);
                exit;
            }

            $db->beginTransaction();
            $matchGroupCol = sj_match_group_col($db);
            $matchEventCol = sj_match_event_col($db);
            $insCols = ['round_id', 'match_no', 'status', 'tarikh', 'created_at'];
            $insVals = [':round_id', ':match_no', ':status', 'NULL', 'NOW()'];
            if ($matchEventCol) { $insCols[] = $matchEventCol; $insVals[] = ':event_id'; }
            if ($matchGroupCol) { $insCols[] = $matchGroupCol; $insVals[] = ':group_code'; }
            $insSql = "INSERT INTO table_match (" . implode(',', $insCols) . ") VALUES (" . implode(',', $insVals) . ")";
            $ins = $db->prepare($insSql);

            $created = [];
            foreach ($toCreate as $mno) {
                $params = [
                    ':round_id' => $ridPrimary,
                    ':match_no' => (int)$mno,
                    ':status' => 'scheduled',
                ];
                if ($matchEventCol) $params[':event_id'] = $event_id;
                if ($matchGroupCol) $params[':group_code'] = '';
                $ins->execute($params);
                $created[] = ['id' => (int)$db->lastInsertId(), 'match_no' => (int)$mno];
            }
            $db->commit();

            echo json_encode([
                'success' => true,
                'matches' => ['__ALL__' => $created],
                'message' => 'Subsequent knockout matches generated successfully.',
                'debug' => [
                    'existing_match_nos' => array_values($existingNos),
                    'target_match_nos' => array_values($targetNos),
                    'created_match_nos' => array_values($toCreate),
                ],
            ]);
            exit;
        }

        // For each group, fetch teams
        $groups = [];
        $teamStmt = $db->prepare('SELECT id, nama_pasukan FROM table_pasukan WHERE sukan_id = :sukan_id AND initial_group_code = :code AND deleted_at IS NULL ORDER BY id ASC');
        foreach ($groupCodes as $code => $rid) {
            if ($isNoGroupRound && $code === '__ALL__') {
                $allStmt = $db->prepare('SELECT id, nama_pasukan FROM table_pasukan WHERE sukan_id = :sukan_id AND status = 1 AND deleted_at IS NULL ORDER BY id ASC');
                $allStmt->execute([':sukan_id' => $sukan_id]);
                $rows = $allStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $teamStmt->execute([':sukan_id' => $sukan_id, ':code' => $code]);
                $rows = $teamStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            $teams = array_map(function($r){ return ['id' => (int)$r['id'], 'nama_pasukan' => $r['nama_pasukan']]; }, $rows);
            if (count($teams) < 2 && !$isNoGroupRound) {
                echo json_encode(['success' => false, 'errors' => ["Group {$code} must have at least two teams."]]);
                exit;
            }
            if (count($teams) < 2 && $isNoGroupRound) {
                echo json_encode(['success' => false, 'errors' => ['At least two active teams are required to generate a schedule.']]);
                exit;
            }
            $groups[$code] = $teams;
        }

        // All validations passed — generate matches
        $db->beginTransaction();
        $matchGroupCol = sj_match_group_col($db);
        $matchEventCol = sj_match_event_col($db);
        $matchCols = ['round_id'];
        $matchVals = [':round_id'];
        if ($matchEventCol) { $matchCols[] = $matchEventCol; $matchVals[] = ':event_id'; }
        if ($matchGroupCol) { $matchCols[] = $matchGroupCol; $matchVals[] = ':group_code'; }
        $matchCols = array_merge($matchCols, ['match_no','status','tarikh','created_at']);
        $matchVals = array_merge($matchVals, [':match_no',':status','NULL','NOW()']);
        $insertMatch = $db->prepare("INSERT INTO table_match (" . implode(',', $matchCols) . ") VALUES (" . implode(',', $matchVals) . ")");
        $partTeamCol = sj_participant_team_col($db);
        $insertPart = $db->prepare("INSERT INTO table_match_participant (match_id, {$partTeamCol}, created_at) VALUES (:match_id, :team_id, NOW())");

        $output = [];
        foreach ($groups as $code => $teams) {
            $matchNo = 1;
            $teamCount = count($teams);
            for ($i = 0; $i < $teamCount; $i++) {
                for ($j = $i + 1; $j < $teamCount; $j++) {
                    $t1 = $teams[$i]['id'];
                    $t2 = $teams[$j]['id'];
                    $round_id = $groupCodes[$code] ?? $roundIds[0];
                    $params = [
                        ':round_id' => $round_id,
                        ':match_no' => $matchNo,
                        ':status' => 'scheduled'
                    ];
                    if ($matchEventCol) $params[':event_id'] = $event_id;
                    if ($matchGroupCol) $params[':group_code'] = ($code === '__ALL__' ? '' : $code);
                    $insertMatch->execute($params);
                    $matchId = (int)$db->lastInsertId();
                    $insertPart->execute([':match_id' => $matchId, ':team_id' => $t1]);
                    $insertPart->execute([':match_id' => $matchId, ':team_id' => $t2]);
                    $output[$code][] = ['match_no' => $matchNo, 'teams' => [ ['id'=>$t1], ['id'=>$t2] ]];
                    $matchNo++;
                }
            }
        }
        $db->commit();

        echo json_encode(['success' => true, 'matches' => $output]);
        exit;
    } catch (Exception $e) {
        if (isset($db) && $db && $db->inTransaction()) $db->rollBack();
        sj_log('[setup-jadual generate] ' . $e->getMessage());
        echo json_encode(['success' => false, 'errors' => ['Unable to generate the schedule: ' . $e->getMessage()]]);
        exit;
    }
}

// Render page — load events
$events = [];
try {
    $db = getDB();
    // Load non-deleted events (exclude cancelled), show most recent first
    $stmt = $db->prepare("SELECT id, nama_event FROM table_event WHERE deleted_at IS NULL AND status != 'cancelled' ORDER BY tarikh_mula DESC, created_at DESC");
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { sj_log('[setup-jadual] load events: ' . $e->getMessage()); }

ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/schedule-setup.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/schedule-setup.css'); ?>">
<div class="w-100 px-3 schedule-setup">
    <div class="row mb-3">
        <div class="col-12">
            <div class="schedule-eyebrow">SAM 2026 &middot; MATCH SCHEDULING</div><h2 class="mb-1">Match Scheduling</h2>
            <p class="text-muted">Generate or add matches for the group stage.</p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="sj-event">Select Event</label>
                    <select id="sj-event" class="form-select">
                        <option value="">-- Select Event --</option>
                        <?php foreach ($events as $ev): ?>
                            <option value="<?php echo (int)$ev['id']; ?>"><?php echo htmlspecialchars($ev['nama_event'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="sj-round">Select Round</label>
                    <select id="sj-round" class="form-select" disabled>
                        <option value="">-- Select Round --</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Mode</label>
                    <div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="sj-mode" id="mode-manual" value="manual" checked>
                            <label class="form-check-label" for="mode-manual">Schedule Manually</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="sj-mode" id="mode-auto" value="auto">
                            <label class="form-check-label" for="mode-auto">Generate Schedule Automatically</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="manual-section" class="mb-3">
        <div class="card">
            <div class="card-header">Manual Setup</div>
            <div class="card-body">
                <div class="mb-3">
                    <?php if (isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['ADMIN','ORGANIZER'])): ?>
                        <button id="sj-add-btn" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#modalTambahPerlawanan">Add Match</button>
                    <?php endif; ?>
                </div>

                <!-- Modal Add Match -->
                <div class="modal fade" id="modalTambahPerlawanan" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 id="modal-title" class="modal-title">Add Match</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" id="modal-match-id" value="">
                                <div class="mb-3">
                                    <label class="form-label">Round / Stage</label>
                                    <select id="modal-round" class="form-select"></select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Group</label>
                                    <select id="modal-group" class="form-select"></select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Team A</label>
                                    <select id="modal-team-a" class="form-select"></select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Team B</label>
                                    <select id="modal-team-b" class="form-select"></select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Date & Time (optional)</label>
                                    <input id="modal-tarikh" class="form-control" type="datetime-local">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Venue (optional)</label>
                                    <select id="modal-venue" class="form-select">
                                        <option value="">-- Select Venue (optional) --</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Venue Detail (optional)</label>
                                    <input id="modal-venue-detail" class="form-control" type="text" list="modal-venue-detail-list" placeholder="e.g. Field A, Court 2, Lane 4" maxlength="100" disabled>
                                    <datalist id="modal-venue-detail-list"></datalist>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Match Number</label>
                                    <input id="modal-match-no" class="form-control" type="number" min="1">
                                    <div id="modal-match-no-hint" class="form-text">Suggestion: the next match number will be recommended automatically.</div>
                                </div>
                                <div id="modal-msg" class="text-danger"></div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" id="modal-save-btn" class="btn btn-primary">Save Match</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="manual-table" class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th style="width:7%;">Group</th>
                                <th style="width:7%;">Match No</th>
                                <th style="width:18%;">Team A</th>
                                <th style="width:18%;">Team B</th>
                                <th style="width:26%;">Venue</th>
                                <th style="width:10%;">Date</th>
                                <th style="width:7%;">Status</th>
                                <th style="width:7%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="manual-table-body"><tr><td colspan="9" class="text-muted schedule-empty">Select an event and round to load matches.</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="auto-section" style="display:none;" class="mb-3">
        <div class="card">
            <div class="card-header">Generate Schedule Automatically</div>
            <div class="card-body">
                <div class="card mb-3">
                    <div class="card-header">Group Summary</div>
                    <div class="card-body" id="sj-groups">
                        <p class="text-muted">Select an event and round to view the group summary.</p>
                    </div>
                </div>
                <div>
                    <button id="sj-generate" class="btn btn-primary" disabled>Generate Match Schedule</button>
                    <div id="sj-result" class="mt-3"></div>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
(function(){
    const SJ_DEBUG = true;
    function sjDbg(label, payload){
        if (!SJ_DEBUG || !window.console) return;
        try { console.log('[SETUP-JADUAL]', label, payload || ''); } catch(e) {}
    }
    async function sjFetchJson(url, options){
        sjDbg('fetch:start', { url: url, options: options || {} });
        const res = await fetch(url, options || {});
        const text = await res.text();
        sjDbg('fetch:response', { url: url, status: res.status, text: text });
        let json = null;
        try {
            json = JSON.parse(text);
        } catch (e) {
            sjDbg('fetch:json_parse_error', { url: url, error: String(e), text: text });
            throw new Error('JSON parse error for ' + url);
        }
        return json;
    }

    const evt = document.getElementById('sj-event');
    const rnd = document.getElementById('sj-round');
    const modeManual = document.getElementById('mode-manual');
    const modeAuto = document.getElementById('mode-auto');
    const manualSection = document.getElementById('manual-section');
    const autoSection = document.getElementById('auto-section');

    const groupsEl = document.getElementById('sj-groups');
    const genBtn = document.getElementById('sj-generate');
    const resultEl = document.getElementById('sj-result');

    const addBtn = document.getElementById('sj-add-btn');
    const manualGroup = document.getElementById('manual-group') || document.getElementById('modal-group');
    const manualA = document.getElementById('manual-team-a') || document.getElementById('modal-team-a');
    const manualB = document.getElementById('manual-team-b') || document.getElementById('modal-team-b');
    const manualMatchNo = document.getElementById('manual-match-no') || document.getElementById('modal-match-no');
    const manualTarikh = document.getElementById('manual-tarikh') || document.getElementById('modal-tarikh');
    const manualSave = document.getElementById('manual-save') || document.getElementById('modal-save-btn');
    const manualMsg = document.getElementById('manual-msg') || document.getElementById('modal-msg');
    const modalRound = document.getElementById('modal-round');
    const modalTitle = document.getElementById('modal-title');
    const modalMatchId = document.getElementById('modal-match-id');
    const modalGroup = document.getElementById('modal-group');
    const modalA = document.getElementById('modal-team-a');
    const modalB = document.getElementById('modal-team-b');
    const modalMatchNoHint = document.getElementById('modal-match-no-hint');
    const modalMsg = document.getElementById('modal-msg');
    const modalVenue = document.getElementById('modal-venue');
    const modalVenueDetail = document.getElementById('modal-venue-detail');
    const modalVenueDetailList = document.getElementById('modal-venue-detail-list');
    const manualTableBody = document.getElementById('manual-table-body');

    let currentGroups = {}; // code => [{id,name}]
    let currentGroupsOrder = [];
    let modalMode = 'add';
    let editPayload = null;
    let currentRoundName = '';
    let currentMatchMap = {};
    const ALL_GROUP_CODE = '__ALL__';
    function groupLabel(code){
        return String(code || '') === ALL_GROUP_CODE ? 'ALL TEAMS' : String(code || '');
    }

    function setMode() {
        if (modeManual.checked) { manualSection.style.display = ''; autoSection.style.display = 'none'; }
        else { manualSection.style.display = 'none'; autoSection.style.display = ''; }
    }
    setMode();
    modeManual.addEventListener('change', setMode);
    modeAuto.addEventListener('change', setMode);

    evt && evt.addEventListener('change', async function(){
        const id = this.value || '';
        rnd.innerHTML = '<option value="">-- Select Round --</option>';
        rnd.disabled = true; groupsEl.innerHTML = '<p class="text-muted">Select an event and round to view the group summary.</p>';
        genBtn.disabled = true; resultEl.innerHTML = '';
        manualTableBody.innerHTML = '<tr><td colspan="8" class="text-muted">Select an event and round to load matches.</td></tr>';
        window.loadedEventData = null; currentGroups = {};
        if (!id) return;
        try{
            const j = await sjFetchJson('?action=load_event_all&event_id=' + encodeURIComponent(id));
            if (j && j.success){
                window.loadedEventData = j;
                sjDbg('load_event_all:ok', j);
                // populate rounds
                if (Array.isArray(j.rounds)){
                    j.rounds.forEach(r => { const opt = document.createElement('option'); opt.value = r; opt.textContent = r; rnd.appendChild(opt); });
                    rnd.disabled = false;
                    // auto-select first round if available
                    if (j.rounds.length === 1) { rnd.value = j.rounds[0]; loadGroupsAndMatches(); }
                }
            }
        }catch(e){ console.error(e); sjDbg('load_event_all:error', e); }
    });

    async function loadGroupsAndMatches() {
        const eventId = evt.value || '';
        const nama = rnd.value || '';
        currentRoundName = nama;
        groupsEl.innerHTML = '<p class="text-muted">Loading groups...</p>';
        genBtn.disabled = true; resultEl.innerHTML = '';
        manualTableBody.innerHTML = '<tr><td colspan="8" class="text-muted">Loading matches...</td></tr>';
        if (addBtn) addBtn.disabled = false;
        manualMsg.innerHTML = '';
        if (!eventId || !nama) return;
        try{
            let j = null;
            if (window.loadedEventData && window.loadedEventData.groups_by_round && window.loadedEventData.groups_by_round[nama]){
                j = { success: true, groups: window.loadedEventData.groups_by_round[nama] };
            } else {
                j = await sjFetchJson('?action=load_groups&event_id=' + encodeURIComponent(eventId) + '&nama_round=' + encodeURIComponent(nama));
            }
                if (j && j.success){
                    sjDbg('load_groups:ok', j);
                    // j.groups is an ordered array: [{group_code, teams:[]}, ...]
                    let groupsArr = j.groups || [];
                    if (!Array.isArray(groupsArr) && groupsArr && typeof groupsArr === 'object') {
                        groupsArr = Object.keys(groupsArr).map(function(code){ return { group_code: code, teams: groupsArr[code] || [] }; });
                    }
                    groupsArr.sort((a,b) => String(a.group_code || '').localeCompare(String(b.group_code || ''), undefined, { sensitivity: 'base' }));
                    if (groupsArr.length === 0) { groupsEl.innerHTML = '<p class="text-muted">No groups found.</p>'; manualTableBody.innerHTML = '<tr><td colspan="8" class="text-muted">No matches.</td></tr>'; return; }
                    // build mapping for quick lookup and maintain order
                    currentGroups = {}; currentGroupsOrder = [];
                    let html = '';
                    manualGroup.innerHTML = '';
                    groupsArr.forEach(g => {
                        const code = g.group_code;
                        currentGroupsOrder.push(code);
                        currentGroups[code] = g.teams || [];
                        html += '<h6>Group ' + groupLabel(code) + ':</h6><ul>';
                        (g.teams || []).forEach(t => { html += '<li>' + (t.nama_pasukan || ('Team ' + t.id)) + '</li>'; });
                        html += '</ul>';
                        const opt = document.createElement('option'); opt.value = code; opt.textContent = groupLabel(code); manualGroup.appendChild(opt);
                    });
                    groupsEl.innerHTML = html;
                    // populate manual team selects for first group
                    _populateTeamsForGroup(currentGroupsOrder[0]);
            }
            // now load matches
            const lmj = await sjFetchJson('?action=list_matches&event_id=' + encodeURIComponent(eventId) + '&nama_round=' + encodeURIComponent(nama));
            if (lmj && lmj.success){
                sjDbg('list_matches:ok', lmj);
                const matches = lmj.matches || {};
                _renderMatchTable(matches);
                // If any matches exist, disable auto generation
                const hasAny = Object.keys(matches).some(k => (matches[k]||[]).length>0);
                genBtn.disabled = hasAny; // server will also protect
                if (hasAny) resultEl.innerHTML = '<div class="alert alert-info">Matches already exist for this round. Automatic generation is disabled.</div>';
            }
        }catch(e){ console.error(e); sjDbg('loadGroupsAndMatches:error', e); groupsEl.innerHTML = '<p class="text-danger">Unable to load groups.</p>'; }
    }

    rnd && rnd.addEventListener('change', loadGroupsAndMatches);

    function _populateTeamsForGroup(code, targetA = manualA, targetB = manualB){
        if (!targetA || !targetB) return;
        targetA.innerHTML = '';
        targetB.innerHTML = '';
        const arr = currentGroups[code] || [];
        arr.forEach(t => { const o = document.createElement('option'); o.value = t.id; o.textContent = t.nama_pasukan; targetA.appendChild(o); });
        arr.forEach(t => { const o = document.createElement('option'); o.value = t.id; o.textContent = t.nama_pasukan; targetB.appendChild(o); });
    }

    manualGroup && manualGroup.addEventListener('change', function(){ _populateTeamsForGroup(this.value); });

    // modal-specific: when modal opens, populate modal round/group/team selects
    document.getElementById('modalTambahPerlawanan') && document.getElementById('modalTambahPerlawanan').addEventListener('show.bs.modal', function(){
        if (modalMode !== 'edit') {
            modalMode = 'add';
            editPayload = null;
            if (modalMatchId) modalMatchId.value = '';
            if (modalTitle) modalTitle.textContent = 'Add Match';
            if (manualSave) manualSave.textContent = 'Save Match';
        }
        // populate modal rounds
        modalRound.innerHTML = '';
        if (window.loadedEventData && Array.isArray(window.loadedEventData.rounds)){
            window.loadedEventData.rounds.forEach(r => { const opt = document.createElement('option'); opt.value = r; opt.textContent = r; modalRound.appendChild(opt); });
        }
        // default select current page round if any
        if (rnd && rnd.value) modalRound.value = rnd.value;
        // trigger change to load groups
        const ev = new Event('change'); modalRound.dispatchEvent(ev);
        // load venues for modal
        if (modalVenue) {
            modalVenue.innerHTML = '<option value="">-- Select Venue (optional) --</option>';
            fetch('?action=load_venues&event_id=' + encodeURIComponent(evt.value || ''))
                .then(r=>r.json())
                .then(j=>{
                    if (j && j.success) {
                        let firstRecommended = '';
                        (j.venues||[]).forEach(v=>{
                            const o = document.createElement('option');
                            o.value = v.id; o.textContent = v.display;
                            modalVenue.appendChild(o);
                            if (!firstRecommended && Number(v.is_recommended || 0) === 1) firstRecommended = String(v.id);
                        });
                        if (firstRecommended) {
                            modalVenue.value = firstRecommended;
                            if (modalVenueDetail) modalVenueDetail.disabled = false;
                        }
                    }
                }).catch(()=>{});
        }
        // load venue detail suggestions by selected sukan(event)
        if (modalVenueDetailList) {
            modalVenueDetailList.innerHTML = '';
            fetch('?action=load_venue_details&event_id=' + encodeURIComponent(evt.value || ''))
                .then(r=>r.json())
                .then(j=>{
                    if (j && j.success) {
                        (j.details || []).forEach(d => {
                            const o = document.createElement('option');
                            o.value = d;
                            modalVenueDetailList.appendChild(o);
                        });
                    }
                }).catch(()=>{});
        }
        _suggestNextMatchNo(modalRound.value || '', modalGroup.value || '');
    });

    modalRound && modalRound.addEventListener('change', async function(){
        const rname = this.value || '';
        modalGroup.innerHTML = '';
        if (!rname) return;
        // Try to use preloaded data first
        if (window.loadedEventData && window.loadedEventData.groups_by_round && window.loadedEventData.groups_by_round[rname]){
            let groupsArr = window.loadedEventData.groups_by_round[rname];
            if (!Array.isArray(groupsArr) && typeof groupsArr === 'object') {
                groupsArr = Object.keys(groupsArr).map(function(code){ return { group_code: code, teams: groupsArr[code] || [] }; });
            }
            groupsArr.sort((a,b) => String(a.group_code || '').localeCompare(String(b.group_code || ''), undefined, { sensitivity: 'base' }));
            groupsArr.forEach(g => { const o = document.createElement('option'); o.value = g.group_code; o.textContent = groupLabel(g.group_code); modalGroup.appendChild(o); });
            // auto populate teams for first group
            if (modalGroup.options.length) {
                modalGroup.value = modalGroup.options[0].value;
                // populate using the preloaded teams
                const first = groupsArr[0];
                if (first && Array.isArray(first.teams)) {
                    // update modal team selects directly
                    modalA.innerHTML = '';
                    modalB.innerHTML = '';
                    first.teams.forEach(t => { const oa = document.createElement('option'); oa.value = t.id; oa.textContent = t.nama_pasukan; modalA.appendChild(oa); const ob = document.createElement('option'); ob.value = t.id; ob.textContent = t.nama_pasukan; modalB.appendChild(ob); });
                }
                _suggestNextMatchNo(rname, modalGroup.value);
            }
            return;
        }
        // Fallback: fetch groups for this round from server
        if (!evt.value) return; // need event selected
        try {
            const j = await sjFetchJson('?action=load_groups&event_id=' + encodeURIComponent(evt.value) + '&nama_round=' + encodeURIComponent(rname));
            if (j && j.success) {
                const groupsArr = j.groups || [];
                groupsArr.sort((a,b) => String(a.group_code || '').localeCompare(String(b.group_code || ''), undefined, { sensitivity: 'base' }));
                groupsArr.forEach(g => { const o = document.createElement('option'); o.value = g.group_code; o.textContent = groupLabel(g.group_code); modalGroup.appendChild(o); });
                if (modalGroup.options.length) {
                    modalGroup.value = modalGroup.options[0].value;
                    const first = groupsArr[0];
                    if (first && Array.isArray(first.teams)) {
                        modalA.innerHTML = '';
                        modalB.innerHTML = '';
                        first.teams.forEach(t => { const oa = document.createElement('option'); oa.value = t.id; oa.textContent = t.nama_pasukan; modalA.appendChild(oa); const ob = document.createElement('option'); ob.value = t.id; ob.textContent = t.nama_pasukan; modalB.appendChild(ob); });
                    }
                    _suggestNextMatchNo(rname, modalGroup.value);
                }
            }
        } catch (e) { console.error('Failed to load groups for modal:', e); sjDbg('modalRound:load_groups:error', e); }
    });

    modalGroup && modalGroup.addEventListener('change', async function(){
        const code = this.value || '';
        if (!code) return;
        // If preloaded groups exist for current round, use them
        const rname = modalRound.value || '';
        if (window.loadedEventData && window.loadedEventData.groups_by_round && window.loadedEventData.groups_by_round[rname]){
            let groupsArr = window.loadedEventData.groups_by_round[rname];
            if (!Array.isArray(groupsArr) && typeof groupsArr === 'object') {
                groupsArr = Object.keys(groupsArr).map(function(codeKey){ return { group_code: codeKey, teams: groupsArr[codeKey] || [] }; });
            }
            const found = groupsArr.find(g => g.group_code === code);
            if (found) {
                // populate modal team selects from found.teams
                modalA.innerHTML = '';
                modalB.innerHTML = '';
                (found.teams || []).forEach(t => { const oa = document.createElement('option'); oa.value = t.id; oa.textContent = t.nama_pasukan; modalA.appendChild(oa); const ob = document.createElement('option'); ob.value = t.id; ob.textContent = t.nama_pasukan; modalB.appendChild(ob); });
                _suggestNextMatchNo(rname, code);
                return;
            }
        }
        // Otherwise fetch groups (which include teams) and populate
        if (!evt.value || !rname) return;
        try {
            const j = await sjFetchJson('?action=load_groups&event_id=' + encodeURIComponent(evt.value) + '&nama_round=' + encodeURIComponent(rname));
            if (j && j.success) {
                const groupsArr = j.groups || [];
                // update window.loadedEventData cache if present
                if (window.loadedEventData) {
                    window.loadedEventData.groups_by_round = window.loadedEventData.groups_by_round || {};
                    window.loadedEventData.groups_by_round[rname] = groupsArr;
                }
                const found = groupsArr.find(g => g.group_code === code);
                if (found) {
                    modalA.innerHTML = '';
                    modalB.innerHTML = '';
                    (found.teams || []).forEach(t => { const oa = document.createElement('option'); oa.value = t.id; oa.textContent = t.nama_pasukan; modalA.appendChild(oa); const ob = document.createElement('option'); ob.value = t.id; ob.textContent = t.nama_pasukan; modalB.appendChild(ob); });
                }
                _suggestNextMatchNo(rname, code);
            }
        } catch (e) { console.error('Failed to load teams for modal group:', e); sjDbg('modalGroup:load_groups:error', e); }
    });

        // enable/disable venue detail input based on venue selection
        modalVenue && modalVenue.addEventListener('change', function(){
            if (this.value) { modalVenueDetail.disabled = false; }
            else { modalVenueDetail.disabled = true; modalVenueDetail.value = ''; }
        });

    function _suggestNextMatchNo(roundName, groupCode){
        if (!evt.value) return;
        fetch('?action=get_next_match_no&event_id=' + encodeURIComponent(evt.value))
            .then(r=>r.json()).then(j=>{
                if (!j || !j.success) return;
                const next = parseInt(j.next_match_no || 1, 10) || 1;
                if (manualMatchNo) manualMatchNo.value = next;
                if (modalMatchNoHint) modalMatchNoHint.textContent = 'Suggested global match number: ' + next;
            }).catch(()=>{});
    }

    function _toDatetimeLocal(v){
        const s = (v || '').toString().trim();
        if (!s) return '';
        return s.replace(' ', 'T').slice(0, 16);
    }

    function _formatDateTimeDisplay(v){
        const s = (v || '').toString().trim();
        if (!s) return '';
        const m = s.replace('T', ' ').match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})/);
        if (!m) return s;
        const year = m[1], month = m[2], day = m[3];
        let hour24 = parseInt(m[4], 10);
        const minute = m[5];
        const ampm = hour24 >= 12 ? 'PM' : 'AM';
        let hour12 = hour24 % 12;
        if (hour12 === 0) hour12 = 12;
        const hh = String(hour12).padStart(2, '0');
        return day + '/' + month + '/' + year + ' ' + hh + ':' + minute + ' ' + ampm;
    }

    async function _prepareModalForEdit(payload){
        if (!payload) return;
        if (modalTitle) modalTitle.textContent = 'Edit Match';
        if (manualSave) manualSave.textContent = 'Update Match';
        if (modalMatchId) modalMatchId.value = String(payload.id || '');

        const roundName = payload.round_name || (rnd ? rnd.value : '');
        if (roundName) {
            modalRound.value = roundName;
            const ev = new Event('change');
            modalRound.dispatchEvent(ev);
            await new Promise(resolve => setTimeout(resolve, 150));
        }
        if (payload.group_code) {
            modalGroup.value = payload.group_code;
            const ev2 = new Event('change');
            modalGroup.dispatchEvent(ev2);
            await new Promise(resolve => setTimeout(resolve, 150));
        }
        if (payload.team_a_id) modalA.value = String(payload.team_a_id);
        if (payload.team_b_id) modalB.value = String(payload.team_b_id);
        if (manualMatchNo) manualMatchNo.value = String(payload.match_no || '');
        if (manualTarikh) manualTarikh.value = _toDatetimeLocal(payload.tarikh || '');
        if (modalVenue) modalVenue.value = payload.venue_id ? String(payload.venue_id) : '';
        if (modalVenueDetail) modalVenueDetail.value = (payload.venue_detail || '').toString();
        if (modalVenueDetail) modalVenueDetail.disabled = !(modalVenue && modalVenue.value);
    }

    function _renderMatchTable(matches){
        manualTableBody.innerHTML = '';
        currentMatchMap = {};
        const allGroups = Object.keys(matches || {});
        if (allGroups.length === 0) { manualTableBody.innerHTML = '<tr><td colspan="8" class="text-muted">No matches.</td></tr>'; return; }
        let locked = false;
        const flat = [];
        for (const code of allGroups){
            const arr = matches[code] || [];
            arr.forEach(m => flat.push({ code: code, m: m }));
        }
        flat.sort((x, y) => {
            const mx = parseInt((x.m && x.m.match_no) || 0, 10) || 0;
            const my = parseInt((y.m && y.m.match_no) || 0, 10) || 0;
            if (mx !== my) return mx - my;
            return String(x.code || '').localeCompare(String(y.code || ''));
        });
        flat.forEach(item => {
            const code = item.code;
            const m = item.m || {};
            const teamA = (m.teams && m.teams[0]) ? m.teams[0] : null;
            const teamB = (m.teams && m.teams[1]) ? m.teams[1] : null;
            const teamALabel = (m.team_a_label || (teamA ? teamA.nama_pasukan : '') || '').toString();
            const teamBLabel = (m.team_b_label || (teamB ? teamB.nama_pasukan : '') || '').toString();
            currentMatchMap[String(m.id)] = {
                id: m.id,
                round_name: currentRoundName,
                group_code: code,
                team_a_id: teamA ? teamA.id : '',
                team_b_id: teamB ? teamB.id : '',
                match_no: m.match_no || '',
                tarikh: m.tarikh || '',
                venue_id: m.venue_id || '',
                venue_detail: m.venue_detail || ''
            };
            const tr = document.createElement('tr');
            const venueName = (m.venue_name || '').toString().trim();
            const venueDetail = (m.venue_detail || '').toString().trim();
            const tarikhDisplay = _formatDateTimeDisplay(m.tarikh || '');
            let venueDisplay = venueName;
            if (venueName && venueDetail) venueDisplay = venueName + ' / ' + venueDetail;
            else if (!venueName && venueDetail) venueDisplay = venueDetail;
            const actionHtml = (m.status === 'scheduled')
                ? '<button class="btn btn-sm btn-outline-primary me-1 manual-edit" title="Edit" data-id="'+m.id+'"><i class="fa fa-edit"></i></button>'
                  + '<button class="btn btn-sm btn-outline-danger manual-delete" title="Delete" data-id="'+m.id+'"><i class="fa fa-trash"></i></button>'
                : '';
            tr.innerHTML = '<td>'+groupLabel(code)+'</td><td>'+ (m.match_no||'') +'</td><td>'+ teamALabel +'</td><td>'+ teamBLabel +'</td><td>'+ venueDisplay +'</td><td>'+ tarikhDisplay +'</td><td>'+m.status+'</td><td>'+actionHtml+'</td>';
            manualTableBody.appendChild(tr);
            if (m.status !== 'scheduled') locked = true;
        });
        // apply locking rules
        const disableActions = locked;
        document.querySelectorAll('.manual-edit').forEach(b=>b.disabled = disableActions);
        document.querySelectorAll('.manual-delete').forEach(b=>b.disabled = disableActions);
        addBtn.disabled = disableActions;
        document.querySelectorAll('.manual-edit').forEach(b=> b.addEventListener('click', async function(){
            const mid = String(this.getAttribute('data-id') || '');
            const payload = currentMatchMap[mid];
            if (!payload) return;
            modalMode = 'edit';
            editPayload = payload;
            modalMsg.innerHTML = '';
            const modalEl = document.getElementById('modalTambahPerlawanan');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
            setTimeout(() => { _prepareModalForEdit(payload); }, 180);
        }));
        // attach delete handlers
        document.querySelectorAll('.manual-delete').forEach(b=> b.addEventListener('click', async function(){
            if (!confirm('Delete this match?')) return; const mid = this.getAttribute('data-id');
            const fd = new FormData(); fd.append('action','delete_match'); fd.append('match_id', mid);
            sjDbg('delete_match:request', { match_id: mid });
            const j = await sjFetchJson('', { method: 'POST', body: fd });
            if (j && j.success){ loadGroupsAndMatches(); } else { alert((j.errors||['Failed']).join('\n')); }
        }));
    }

    // open modal handled by bootstrap data attributes; handle modal save
    manualSave && manualSave.addEventListener('click', async function(){
        modalMsg.innerHTML = '';
        const eventId = evt.value || '';
        const matchId = modalMatchId ? (modalMatchId.value || '') : '';
        const nama = modalRound.value || '';
        const group = modalGroup.value || '';
        const a = modalA.value || '';
        const b = modalB.value || '';
        const no = manualMatchNo.value || '';
        const tar = manualTarikh.value || '';
        const venueId = modalVenue ? modalVenue.value || '' : '';
        const venueDetail = modalVenueDetail ? modalVenueDetail.value || '' : '';
        if (!eventId || !nama || !a || !b) { modalMsg.innerHTML = 'Please complete all fields.'; return; }
        if (modalMode === 'edit' && !matchId) { modalMsg.innerHTML = 'Invalid match ID.'; return; }
        if (a === b) { modalMsg.innerHTML = 'Team A and Team B must be different.'; return; }
        // client-side duplicate check
        try {
            const lmj = await sjFetchJson('?action=list_matches&event_id=' + encodeURIComponent(eventId) + '&nama_round=' + encodeURIComponent(nama));
            if (lmj && lmj.success) {
                const arr = (lmj.matches && (lmj.matches[group] || (group === ALL_GROUP_CODE ? (lmj.matches['-'] || []) : []))) || [];
                const dupMatch = arr.find(m => {
                    if (!m.teams) return false;
                    const ids = m.teams.map(t=>String(t.id));
                    return (ids.includes(String(a)) && ids.includes(String(b)));
                });
                if (dupMatch && !(modalMode === 'edit' && String(dupMatch.id || '') === String(matchId))) { modalMsg.innerHTML = 'A match between these teams already exists in this round.'; return; }
            }
        } catch(e) { /* ignore, server will validate */ }

        try{
            const saveLabel = (modalMode === 'edit' ? 'Update Match' : 'Save Match');
            const fd = new FormData();
            fd.append('action', modalMode === 'edit' ? 'update_manual_match' : 'add_manual_match');
            if (modalMode === 'edit') fd.append('match_id', matchId);
            fd.append('event_id', eventId); fd.append('nama_round', nama);
            fd.append('group_code', group || ''); fd.append('team_a', a); fd.append('team_b', b);
            if (no) fd.append('match_no', no); if (tar) fd.append('tarikh', tar);
            if (venueId) fd.append('venue_id', venueId);
            if (venueDetail) fd.append('venue_detail', venueDetail);
            sjDbg('save_match:request', {
                mode: modalMode, match_id: matchId, event_id: eventId, nama_round: nama, group_code: group || '',
                team_a: a, team_b: b, match_no: no, tarikh: tar, venue_id: venueId, venue_detail: venueDetail
            });
            manualSave.disabled = true; manualSave.textContent = 'Saving...';
            const j = await sjFetchJson('', { method: 'POST', body: fd });
            manualSave.disabled = false; manualSave.textContent = saveLabel;
            sjDbg('save_match:response', j);
            if (j && j.success){
                // close modal
                const modalEl = document.getElementById('modalTambahPerlawanan');
                const modal = bootstrap.Modal.getInstance(modalEl);
                modal && modal.hide();
                // show success message and refresh list
                resultEl.innerHTML = '<div class="alert alert-success">' + (modalMode === 'edit' ? 'Match updated successfully' : 'Match added successfully') + '</div>';
                modalMode = 'add';
                editPayload = null;
                if (modalMatchId) modalMatchId.value = '';
                loadGroupsAndMatches();
            } else {
                modalMsg.innerHTML = (j.errors || ['Unable to save']).join('<br>');
            }
        }catch(e){
            sjDbg('save_match:error', e);
            const saveLabel = (modalMode === 'edit' ? 'Update Match' : 'Save Match');
            modalMsg.innerHTML = 'Network error.';
            manualSave.disabled = false;
            manualSave.textContent = saveLabel;
        }
    });

    genBtn && genBtn.addEventListener('click', async function(){
        const eventId = evt.value || '';
        const nama = rnd.value || '';
        if (!eventId || !nama) return;
        if (!confirm('Generate the match schedule for this round?')) return;
        genBtn.disabled = true; genBtn.textContent = 'Menjana...'; resultEl.innerHTML = '';
        try{
            const fd = new FormData(); fd.append('action','generate_schedule'); fd.append('event_id', eventId); fd.append('nama_round', nama);
            sjDbg('generate_schedule:request', { event_id: eventId, nama_round: nama });
            const j = await sjFetchJson('', { method: 'POST', body: fd });
            sjDbg('generate_schedule:response', j);
            if (j && j.success){
                const matches = j.matches || {};
                let html = '<div class="alert alert-success">Schedule generated successfully.</div>';
                for (const code of Object.keys(matches)){
                    html += '<h6>Group ' + groupLabel(code) + '</h6><ul>';
                    matches[code].forEach(m => { html += '<li>Match ' + m.match_no + '</li>'; });
                    html += '</ul>';
                }
                resultEl.innerHTML = html;
                genBtn.disabled = true; genBtn.textContent = 'Generate Match Schedule';
                // refresh table
                loadGroupsAndMatches();
            } else {
                const msg = (j.errors || ['Unable to generate the schedule']).join('<br>');
                resultEl.innerHTML = '<div class="alert alert-danger">' + msg + '</div>';
                genBtn.disabled = false; genBtn.textContent = 'Generate Match Schedule';
            }
        }catch(e){ console.error(e); sjDbg('generate_schedule:error', e); resultEl.innerHTML = '<div class="alert alert-danger">Network error.</div>'; genBtn.disabled = false; genBtn.textContent = 'Generate Match Schedule'; }
    });

})();
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
