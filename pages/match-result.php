<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/rbac.php';

Session::start();
$auth = getAuth();
$auth->requireAuth();

$rbac = getRBAC();
$rbac->requirePageAccess('pages/match-result.php');

$page_title = 'Key In Result';

function mr_has_column(PDO $db, string $table, string $column): bool {
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
        $ok = ((int)$stmt->fetchColumn() > 0);
        $cache[$key] = $ok;
        return $ok;
    } catch (Exception $e) {
        $cache[$key] = false;
        return false;
    }
}

function mr_participant_column(PDO $db): string {
    if (mr_has_column($db, 'table_match_participant', 'participant_id')) {
        return 'participant_id';
    }
    if (mr_has_column($db, 'table_match_participant', 'team_id')) {
        return 'team_id';
    }
    if (mr_has_column($db, 'table_match_participant', 'pasukan_id')) {
        return 'pasukan_id';
    }
    return 'participant_id';
}

function generateStanding(int $roundId, PDO $db): void {
    // Placeholder only (requested). Full standing logic can be implemented later.
    error_log('[match-result.php] generateStanding placeholder called for round_id=' . $roundId);
}

$db = getDB();
$matchId = isset($_GET['match_id']) ? (int)$_GET['match_id'] : (isset($_POST['match_id']) ? (int)$_POST['match_id'] : 0);
$errors = [];
$successMessage = '';

if ($matchId <= 0) {
    $errors[] = 'Invalid match ID.';
}

$participantCol = mr_participant_column($db);

$match = null;
$participants = [];
$scores = [];

if (empty($errors)) {
    try {
        $matchStmt = $db->prepare("
            SELECT m.*, r.nama_round
            FROM table_match m
            JOIN table_round r ON r.id = m.round_id
            WHERE m.id = ?
              AND m.deleted_at IS NULL
            LIMIT 1
        ");
        $matchStmt->execute([$matchId]);
        $match = $matchStmt->fetch(PDO::FETCH_ASSOC);
        if (!$match) {
            $errors[] = 'Matches not found.';
        } else {
            $partStmt = $db->prepare("
                SELECT mp.id, mp.{$participantCol} AS participant_id, p.nama_pasukan
                FROM table_match_participant mp
                JOIN table_pasukan p ON p.id = mp.{$participantCol}
                WHERE mp.match_id = ?
                  AND mp.deleted_at IS NULL
                ORDER BY mp.id ASC
            ");
            $partStmt->execute([$matchId]);
            $participants = $partStmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($participants) < 2) {
                $errors[] = 'This match does not have two valid teams.';
            } else {
                $scoreStmt = $db->prepare("
                    SELECT score
                    FROM table_match_result
                    WHERE match_participant_id = ?
                    LIMIT 1
                ");
                foreach ($participants as $pt) {
                    $pid = (int)$pt['id'];
                    $scoreStmt->execute([$pid]);
                    $existing = $scoreStmt->fetchColumn();
                    $scores[$pid] = ($existing !== false && $existing !== null) ? (string)$existing : '';
                }
            }
        }
    } catch (Exception $e) {
        $errors[] = 'Unable to load match data.';
        error_log('[match-result.php:load] ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $teamAParticipantId = (int)($participants[0]['id'] ?? 0);
    $teamBParticipantId = (int)($participants[1]['id'] ?? 0);

    $scoreA = isset($_POST['score_' . $teamAParticipantId]) ? trim((string)$_POST['score_' . $teamAParticipantId]) : '';
    $scoreB = isset($_POST['score_' . $teamBParticipantId]) ? trim((string)$_POST['score_' . $teamBParticipantId]) : '';

    if ($scoreA === '' || $scoreB === '') {
        $errors[] = 'Please enter the scores for Team A and Team B.';
    } elseif (!is_numeric($scoreA) || !is_numeric($scoreB)) {
        $errors[] = 'The score must be a valid number.';
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            $existsStmt = $db->prepare("
                SELECT id
                FROM table_match_result
                WHERE match_participant_id = ?
                LIMIT 1
            ");
            $updateStmt = $db->prepare("
                UPDATE table_match_result
                SET score = ?
                WHERE match_participant_id = ?
            ");
            $insertStmt = $db->prepare("
                INSERT INTO table_match_result (match_participant_id, score)
                VALUES (?, ?)
            ");

            $saveRows = [
                ['match_participant_id' => $teamAParticipantId, 'score' => $scoreA],
                ['match_participant_id' => $teamBParticipantId, 'score' => $scoreB],
            ];

            foreach ($saveRows as $row) {
                $mpid = (int)$row['match_participant_id'];
                $scr = (string)$row['score'];
                $existsStmt->execute([$mpid]);
                $exists = (int)$existsStmt->fetchColumn();
                if ($exists > 0) {
                    $updateStmt->execute([$scr, $mpid]);
                } else {
                    $insertStmt->execute([$mpid, $scr]);
                }
            }

            $statusStmt = $db->prepare("
                UPDATE table_match
                SET status = 'completed'
                WHERE id = ?
            ");
            $statusStmt->execute([$matchId]);

            $roundId = (int)$match['round_id'];
            $checkStmt = $db->prepare("
                SELECT COUNT(*)
                FROM table_match
                WHERE round_id = ?
                  AND status != 'completed'
                  AND deleted_at IS NULL
            ");
            $checkStmt->execute([$roundId]);
            $notCompletedCount = (int)$checkStmt->fetchColumn();
            if ($notCompletedCount === 0) {
                generateStanding($roundId, $db);
            }

            $db->commit();
            header('Location: ' . url('pages/matches.php') . '?round_id=' . $roundId . '&saved=1');
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $errors[] = 'Unable to save the result.';
            error_log('[match-result.php:save] ' . $e->getMessage());
        }
    }
}

ob_start();
?>
<div class="w-100 px-3">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-body d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h4 class="mb-1">Key In Result</h4>
                        <p class="text-muted mb-0">Update match results and status.</p>
                    </div>
                    <a href="<?php echo url('pages/matches.php'); ?>" class="btn btn-outline-secondary">Back</a>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $err): ?>
                    <li><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($match && count($participants) >= 2): ?>
        <div class="card shadow-sm">
            <div class="card-header">
                <strong>Match Details</strong>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label text-muted mb-1">Round Name</label>
                        <div class="fw-semibold"><?php echo htmlspecialchars((string)$match['nama_round'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label text-muted mb-1">Match No</label>
                        <div class="fw-semibold"><?php echo htmlspecialchars((string)$match['match_no'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted mb-1">Match</label>
                        <div class="fw-semibold">
                            <?php echo htmlspecialchars((string)$participants[0]['nama_pasukan'], ENT_QUOTES, 'UTF-8'); ?>
                            <span class="mx-1">vs</span>
                            <?php echo htmlspecialchars((string)$participants[1]['nama_pasukan'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </div>
                </div>

                <form method="post">
                    <input type="hidden" name="match_id" value="<?php echo (int)$matchId; ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">
                                Team A Score: <?php echo htmlspecialchars((string)$participants[0]['nama_pasukan'], ENT_QUOTES, 'UTF-8'); ?>
                            </label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                class="form-control"
                                name="score_<?php echo (int)$participants[0]['id']; ?>"
                                value="<?php echo htmlspecialchars((string)($scores[(int)$participants[0]['id']] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                required
                            >
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">
                                Team B Score: <?php echo htmlspecialchars((string)$participants[1]['nama_pasukan'], ENT_QUOTES, 'UTF-8'); ?>
                            </label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                class="form-control"
                                name="score_<?php echo (int)$participants[1]['id']; ?>"
                                value="<?php echo htmlspecialchars((string)($scores[(int)$participants[1]['id']] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                required
                            >
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">Save Result</button>
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
