<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/rbac.php';

Session::start();
$auth = getAuth();
$auth->requireAuth();

$rbac = getRBAC();
$rbac->requirePageAccess('pages/edit-knockout-match.php');

$page_title = 'Edit Knockout Match';

function ekm_has_column(PDO $db, string $table, string $column): bool
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

function ekm_pick_col(PDO $db, string $table, array $candidates): ?string
{
    foreach ($candidates as $c) if (ekm_has_column($db, $table, $c)) return $c;
    return null;
}

function ekm_match_has_result(PDO $db, int $matchId): bool
{
    if (ekm_has_column($db, 'table_match_result', 'match_id')) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM table_match_result WHERE match_id = :mid');
        $stmt->execute([':mid' => $matchId]);
        return ((int)$stmt->fetchColumn() > 0);
    }
    $sql = "SELECT COUNT(*) FROM table_match_result mr INNER JOIN table_match_participant mp ON mp.id = mr.match_participant_id WHERE mp.match_id = :mid";
    if (ekm_has_column($db, 'table_match_participant', 'deleted_at')) $sql .= " AND mp.deleted_at IS NULL";
    $stmt = $db->prepare($sql);
    $stmt->execute([':mid' => $matchId]);
    return ((int)$stmt->fetchColumn() > 0);
}

$db = getDB();
$matchId = isset($_GET['match_id']) ? (int)$_GET['match_id'] : (isset($_POST['match_id']) ? (int)$_POST['match_id'] : 0);
$error = '';
$match = null;
$currentParticipants = [];
$qualifiedTeams = [];
$roundId = 0;

if ($matchId <= 0) {
    $error = 'Invalid match.';
}

if ($error === '') {
    $sql = "
        SELECT m.id, m.match_no, m.round_id, r.nama_round, r.round_type, r.round_order
        " . (ekm_has_column($db, 'table_round', 'event_id') ? ", r.event_id" : "") . "
        " . (ekm_has_column($db, 'table_round', 'sukan_id') ? ", r.sukan_id" : "") . "
        FROM table_match m
        INNER JOIN table_round r ON r.id = m.round_id
        WHERE m.id = :mid
    ";
    if (ekm_has_column($db, 'table_match', 'deleted_at')) $sql .= " AND m.deleted_at IS NULL";
    $sql .= " LIMIT 1";

    $stmt = $db->prepare($sql);
    $stmt->execute([':mid' => $matchId]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$match || strtolower((string)$match['round_type']) !== 'knockout') {
        $error = 'This is not a knockout match.';
    } else {
        $roundId = (int)$match['round_id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $homeId = isset($_POST['home_team_id']) ? (int)$_POST['home_team_id'] : 0;
    $awayId = isset($_POST['away_team_id']) ? (int)$_POST['away_team_id'] : 0;
    if ($homeId <= 0 || $awayId <= 0 || $homeId === $awayId) {
        $error = 'Please select two different, valid home and away teams.';
    } elseif (ekm_match_has_result($db, $matchId)) {
        $error = 'This match already has results. Editing is disabled.';
    } else {
        $mpCol = ekm_pick_col($db, 'table_match_participant', ['participant_id', 'team_id', 'pasukan_id']) ?: 'participant_id';
        try {
            $db->beginTransaction();

            if (ekm_has_column($db, 'table_match_participant', 'deleted_at')) {
                $del = $db->prepare('UPDATE table_match_participant SET deleted_at = NOW() WHERE match_id = :mid AND deleted_at IS NULL');
                $del->execute([':mid' => $matchId]);
            } else {
                $del = $db->prepare('DELETE FROM table_match_participant WHERE match_id = :mid');
                $del->execute([':mid' => $matchId]);
            }

            $cols = ['match_id', $mpCol];
            $vals = [':mid', ':pid'];
            if (ekm_has_column($db, 'table_match_participant', 'created_at')) {
                $cols[] = 'created_at';
                $vals[] = ':created_at';
            }
            $ins = $db->prepare('INSERT INTO table_match_participant (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')');

            $paramsA = [':mid' => $matchId, ':pid' => $homeId];
            $paramsB = [':mid' => $matchId, ':pid' => $awayId];
            if (ekm_has_column($db, 'table_match_participant', 'created_at')) {
                $now = date('Y-m-d H:i:s');
                $paramsA[':created_at'] = $now;
                $paramsB[':created_at'] = $now;
            }
            $ins->execute($paramsA);
            $ins->execute($paramsB);

            $db->commit();
            $q = http_build_query(['round_id' => $roundId, 'flash_type' => 'success', 'flash_msg' => 'Match updated successfully.']);
            header('Location: ' . url('pages/bracket.php') . '?' . $q);
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = 'Unable to update the match.';
            error_log('[edit-knockout-match] ' . $e->getMessage());
        }
    }
}

if ($error === '') {
    $mpCol = ekm_pick_col($db, 'table_match_participant', ['participant_id', 'team_id', 'pasukan_id']) ?: 'participant_id';
    $pSql = "SELECT mp.{$mpCol} AS pid, p.nama_pasukan FROM table_match_participant mp INNER JOIN table_pasukan p ON p.id = mp.{$mpCol} WHERE mp.match_id = :mid";
    if (ekm_has_column($db, 'table_match_participant', 'deleted_at')) $pSql .= " AND mp.deleted_at IS NULL";
    $pSql .= " ORDER BY mp.id ASC";
    $pStmt = $db->prepare($pSql);
    $pStmt->execute([':mid' => $matchId]);
    $currentParticipants = $pStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch qualified teams from previous league round.
    $prevOrder = ((int)$match['round_order']) - 1;
    if ($prevOrder > 0) {
        $lsql = "SELECT id, qualification_rule FROM table_round WHERE round_type = 'league' AND round_order = :ord";
        $params = [':ord' => $prevOrder];
        if (isset($match['event_id'])) {
            $lsql .= " AND event_id = :event_id";
            $params[':event_id'] = (int)$match['event_id'];
        } elseif (isset($match['sukan_id'])) {
            $lsql .= " AND sukan_id = :sukan_id";
            $params[':sukan_id'] = (int)$match['sukan_id'];
        }
        if (ekm_has_column($db, 'table_round', 'deleted_at')) $lsql .= " AND deleted_at IS NULL";
        $lsql .= " ORDER BY id ASC LIMIT 1";
        $lStmt = $db->prepare($lsql);
        $lStmt->execute($params);
        $league = $lStmt->fetch(PDO::FETCH_ASSOC);

        if ($league) {
            $rule = trim((string)$league['qualification_rule']);
            $decoded = $rule !== '' ? json_decode($rule, true) : null;
            $topN = (is_array($decoded) && isset($decoded['top_n'])) ? (int)$decoded['top_n'] : 0;

            $stCol = ekm_pick_col($db, 'table_standing', ['participant_id', 'team_id', 'pasukan_id']) ?: 'participant_id';
            $posCol = ekm_pick_col($db, 'table_standing', ['position', 'ranking']) ?: 'position';
            $qSql = "SELECT s.{$stCol} AS pid, p.nama_pasukan, s.{$posCol} AS pos
                     FROM table_standing s
                     INNER JOIN table_pasukan p ON p.id = s.{$stCol}
                     WHERE s.round_id = :rid";
            if ($topN > 0) $qSql .= " AND CAST(s.{$posCol} AS UNSIGNED) <= :top_n";
            $qSql .= " ORDER BY CAST(s.{$posCol} AS UNSIGNED) ASC, p.nama_pasukan ASC";
            $qStmt = $db->prepare($qSql);
            $qStmt->bindValue(':rid', (int)$league['id'], PDO::PARAM_INT);
            if ($topN > 0) $qStmt->bindValue(':top_n', $topN, PDO::PARAM_INT);
            $qStmt->execute();
            $qualifiedTeams = $qStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

$curHome = isset($currentParticipants[0]['pid']) ? (int)$currentParticipants[0]['pid'] : 0;
$curAway = isset($currentParticipants[1]['pid']) ? (int)$currentParticipants[1]['pid'] : 0;

ob_start();
?>
<div class="w-100 px-3">
    <div class="row mb-3">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <h4 class="mb-1">Edit Knockout Match</h4>
                    <p class="text-muted mb-0">Update the home and away teams for a knockout match.</p>
                </div>
            </div>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($error === '' && $match): ?>
    <div class="card shadow-sm">
        <div class="card-header">
            Match #<?php echo htmlspecialchars((string)$match['match_no'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="match_id" value="<?php echo (int)$matchId; ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Home Team</label>
                        <select name="home_team_id" class="form-select" required>
                            <option value="">-- Please Select --</option>
                            <?php foreach ($qualifiedTeams as $t): ?>
                                <?php $pid = (int)$t['pid']; ?>
                                <option value="<?php echo $pid; ?>" <?php echo $pid === $curHome ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string)$t['nama_pasukan'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Away Team</label>
                        <select name="away_team_id" class="form-select" required>
                            <option value="">-- Please Select --</option>
                            <?php foreach ($qualifiedTeams as $t): ?>
                                <?php $pid = (int)$t['pid']; ?>
                                <option value="<?php echo $pid; ?>" <?php echo $pid === $curAway ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string)$t['nama_pasukan'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mt-3 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Save</button>
                    <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars(url('pages/bracket.php') . '?round_id=' . (int)$roundId, ENT_QUOTES, 'UTF-8'); ?>">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>
