<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/rbac.php';

Session::start();
$auth = getAuth();
$auth->requireAuth();

$rbac = getRBAC();
$rbac->requirePageAccess('pages/knockout-rule-editor.php');

$page_title = 'Knockout Rule Editor';

function kre_has_column(PDO $db, string $table, string $column): bool
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

function kre_normalize_seed_token(string $token): string
{
    $t = strtoupper(trim($token));
    $t = str_replace(['-', '.', '  '], ['_', '', ' '], $t);
    $t = preg_replace('/\s+/', ' ', $t);
    $t = str_replace('NAIB JOHAN', 'RUNNER_UP', $t);
    $t = str_replace('JOHAN', 'WINNER', $t);
    $t = str_replace(' ', '_', $t);
    return $t;
}

function kre_is_valid_seed_token(string $token): bool
{
    return (bool)preg_match('/^(WINNER|RUNNER_UP)_[A-Z0-9]+$/', $token);
}

function kre_decode_rule(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function kre_extract_seed_slots(array $rule): array
{
    $out = [];
    if (!isset($rule['seed_slots']) || !is_array($rule['seed_slots'])) return $out;
    foreach ($rule['seed_slots'] as $row) {
        if (!is_array($row)) continue;
        $home = trim((string)($row['home'] ?? ''));
        $away = trim((string)($row['away'] ?? ''));
        if ($home === '' && $away === '') continue;
        $out[] = ['home' => $home, 'away' => $away];
    }
    return $out;
}

function kre_extract_advance_rows(array $rule): array
{
    $rows = [];
    if (!isset($rule['advance_map']) || !is_array($rule['advance_map'])) return $rows;
    foreach ($rule['advance_map'] as $sourceNo => $targets) {
        $src = (int)$sourceNo;
        if ($src <= 0) continue;
        if (is_array($targets) && array_is_list($targets)) {
            foreach ($targets as $t) {
                if (!is_array($t)) continue;
                $rows[] = [
                    'source' => $src,
                    'target' => (int)($t['match_no'] ?? 0),
                    'slot' => strtolower(trim((string)($t['slot'] ?? 'away'))),
                    'outcome' => strtolower(trim((string)($t['outcome'] ?? 'winner'))),
                ];
            }
        } elseif (is_array($targets)) {
            $rows[] = [
                'source' => $src,
                'target' => (int)($targets['match_no'] ?? 0),
                'slot' => strtolower(trim((string)($targets['slot'] ?? 'away'))),
                'outcome' => strtolower(trim((string)($targets['outcome'] ?? 'winner'))),
            ];
        } else {
            $rows[] = [
                'source' => $src,
                'target' => (int)$targets,
                'slot' => 'away',
                'outcome' => 'winner',
            ];
        }
    }
    return $rows;
}

function kre_collect_seed_slots_from_post(array $post, array &$errors): array
{
    $homes = isset($post['seed_home']) && is_array($post['seed_home']) ? $post['seed_home'] : [];
    $aways = isset($post['seed_away']) && is_array($post['seed_away']) ? $post['seed_away'] : [];
    $n = max(count($homes), count($aways));

    $slots = [];
    $usedTokens = [];
    for ($i = 0; $i < $n; $i++) {
        $homeRaw = isset($homes[$i]) ? trim((string)$homes[$i]) : '';
        $awayRaw = isset($aways[$i]) ? trim((string)$aways[$i]) : '';
        if ($homeRaw === '' && $awayRaw === '') continue;
        if ($homeRaw === '' || $awayRaw === '') {
            $errors[] = 'Seed slot row #' . ($i + 1) . ' must have Home and Away teams.';
            continue;
        }
        $home = kre_normalize_seed_token($homeRaw);
        $away = kre_normalize_seed_token($awayRaw);
        if (!kre_is_valid_seed_token($home) || !kre_is_valid_seed_token($away)) {
            $errors[] = 'Seed slot row #' . ($i + 1) . ' has an invalid token. Use the WINNER_A / RUNNER_UP_B format.';
            continue;
        }
        if ($home === $away) {
            $errors[] = 'Seed slot row #' . ($i + 1) . ' Home and Away teams must be different.';
            continue;
        }
        if (isset($usedTokens[$home]) || isset($usedTokens[$away])) {
            $errors[] = 'Seed slot row #' . ($i + 1) . ' contains duplicate team tokens.';
            continue;
        }
        $usedTokens[$home] = true;
        $usedTokens[$away] = true;
        $slots[] = ['home' => $home, 'away' => $away];
    }
    return $slots;
}

function kre_collect_advance_map_from_post(array $post, array &$errors): array
{
    $srcList = isset($post['adv_source']) && is_array($post['adv_source']) ? $post['adv_source'] : [];
    $dstList = isset($post['adv_target']) && is_array($post['adv_target']) ? $post['adv_target'] : [];
    $slotList = isset($post['adv_slot']) && is_array($post['adv_slot']) ? $post['adv_slot'] : [];
    $outcomeList = isset($post['adv_outcome']) && is_array($post['adv_outcome']) ? $post['adv_outcome'] : [];

    $n = max(count($srcList), count($dstList), count($slotList), count($outcomeList));
    $map = [];
    for ($i = 0; $i < $n; $i++) {
        $srcRaw = isset($srcList[$i]) ? trim((string)$srcList[$i]) : '';
        $dstRaw = isset($dstList[$i]) ? trim((string)$dstList[$i]) : '';
        $slot = strtolower(trim((string)($slotList[$i] ?? '')));
        $outcome = strtolower(trim((string)($outcomeList[$i] ?? '')));

        if ($srcRaw === '' && $dstRaw === '' && $slot === '' && $outcome === '') continue;

        $src = (int)$srcRaw;
        $dst = (int)$dstRaw;
        if ($src <= 0 || $dst <= 0) {
            $errors[] = 'Advance map row #' . ($i + 1) . ' requires Source and Target match_no values greater than zero.';
            continue;
        }
        if ($slot !== 'home' && $slot !== 'away') {
            $errors[] = 'Advance map row #' . ($i + 1) . ' slot must be home or away.';
            continue;
        }
        if ($outcome !== 'winner' && $outcome !== 'loser') {
            $errors[] = 'Advance map row #' . ($i + 1) . ' outcome must be winner or loser.';
            continue;
        }
        $map[(string)$src][] = [
            'match_no' => $dst,
            'slot' => $slot,
            'outcome' => $outcome,
        ];
    }
    return $map;
}

$db = getDB();
$errors = [];
$successMsg = '';
$roundId = isset($_GET['round_id']) ? (int)$_GET['round_id'] : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $roundId = isset($_POST['round_id']) ? (int)$_POST['round_id'] : 0;
}

$round = null;
$seedRows = [];
$advanceRows = [];
$ruleDecoded = [];
$knockoutRoundOptions = [];
$roundContextMap = [];
$roundSportName = '-';
$roundCategoryName = '-';

try {
    $hasRoundGroupCode = kre_has_column($db, 'table_round', 'group_code');
    $hasRoundOrder = kre_has_column($db, 'table_round', 'round_order');
    $hasRoundEventId = kre_has_column($db, 'table_round', 'event_id');
    $hasRoundSukanId = kre_has_column($db, 'table_round', 'sukan_id');
    $hasRoundKategoriId = kre_has_column($db, 'table_round', 'kategori_id');
    $hasEventSukanId = kre_has_column($db, 'table_event', 'sukan_id');
    $hasEventKategoriId = kre_has_column($db, 'table_event', 'kategori_id');
    $hasSukanName = kre_has_column($db, 'table_sukan', 'nama_sukan');
    $hasKategoriName = kre_has_column($db, 'table_kategori', 'nama_kategori');

    $effectiveSukanExpr = 'NULL';
    if ($hasRoundEventId && $hasEventSukanId && $hasRoundSukanId) {
        $effectiveSukanExpr = 'COALESCE(e.sukan_id, r.sukan_id)';
    } elseif ($hasRoundEventId && $hasEventSukanId) {
        $effectiveSukanExpr = 'e.sukan_id';
    } elseif ($hasRoundSukanId) {
        $effectiveSukanExpr = 'r.sukan_id';
    }

    $effectiveKategoriExpr = 'NULL';
    if ($hasRoundEventId && $hasEventKategoriId && $hasRoundKategoriId) {
        $effectiveKategoriExpr = 'COALESCE(e.kategori_id, r.kategori_id)';
    } elseif ($hasRoundEventId && $hasEventKategoriId) {
        $effectiveKategoriExpr = 'e.kategori_id';
    } elseif ($hasRoundKategoriId) {
        $effectiveKategoriExpr = 'r.kategori_id';
    }

    $optCols = ['r.id', 'r.nama_round', 'r.status', 'r.round_type'];
    if ($hasRoundGroupCode) $optCols[] = 'r.group_code';
    if ($hasRoundOrder) $optCols[] = 'r.round_order';
    $optCols[] = $effectiveSukanExpr . ' AS sukan_id_effective';
    $optCols[] = $effectiveKategoriExpr . ' AS kategori_id_effective';
    $optCols[] = ($hasSukanName ? "COALESCE(s.nama_sukan, '')" : "''") . ' AS nama_sukan';
    $optCols[] = ($hasKategoriName ? "COALESCE(k.nama_kategori, '')" : "''") . ' AS nama_kategori';

    $optSql = 'SELECT ' . implode(', ', $optCols) . " FROM table_round r";
    if ($hasRoundEventId && ($hasEventSukanId || $hasEventKategoriId)) {
        $optSql .= ' LEFT JOIN table_event e ON e.id = r.event_id';
        if (kre_has_column($db, 'table_event', 'deleted_at')) $optSql .= ' AND e.deleted_at IS NULL';
    }
    if ($hasSukanName) {
        $optSql .= " LEFT JOIN table_sukan s ON s.id = {$effectiveSukanExpr}";
        if (kre_has_column($db, 'table_sukan', 'deleted_at')) $optSql .= ' AND s.deleted_at IS NULL';
    }
    if ($hasKategoriName) {
        $optSql .= " LEFT JOIN table_kategori k ON k.id = {$effectiveKategoriExpr}";
        if (kre_has_column($db, 'table_kategori', 'deleted_at')) $optSql .= ' AND k.deleted_at IS NULL';
    }
    $optSql .= " WHERE r.round_type = 'knockout'";
    if (kre_has_column($db, 'table_round', 'deleted_at')) $optSql .= ' AND r.deleted_at IS NULL';
    $optSql .= ' ORDER BY COALESCE(r.round_order, 0) DESC, r.id DESC LIMIT 100';
    $optSt = $db->query($optSql);
    $knockoutRoundOptions = $optSt ? ($optSt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    foreach ($knockoutRoundOptions as $optRow) {
        $oid = (int)($optRow['id'] ?? 0);
        if ($oid <= 0) continue;
        $roundContextMap[$oid] = [
            'nama_sukan' => trim((string)($optRow['nama_sukan'] ?? '')),
            'nama_kategori' => trim((string)($optRow['nama_kategori'] ?? '')),
        ];
    }
} catch (Throwable $e) {
    $knockoutRoundOptions = [];
    $roundContextMap = [];
}

if ($roundId > 0) {
    $cols = ['id', 'nama_round', 'round_type', 'status', 'qualification_rule'];
    if (kre_has_column($db, 'table_round', 'event_id')) $cols[] = 'event_id';
    if (kre_has_column($db, 'table_round', 'sukan_id')) $cols[] = 'sukan_id';
    if (kre_has_column($db, 'table_round', 'deleted_at')) {
        $sql = 'SELECT ' . implode(', ', $cols) . ' FROM table_round WHERE id = :id AND deleted_at IS NULL LIMIT 1';
    } else {
        $sql = 'SELECT ' . implode(', ', $cols) . ' FROM table_round WHERE id = :id LIMIT 1';
    }
    $st = $db->prepare($sql);
    $st->execute([':id' => $roundId]);
    $round = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$round) {
        $errors[] = 'Rounds not found.';
    } elseif (strtolower(trim((string)($round['round_type'] ?? ''))) !== 'knockout') {
        $errors[] = 'This is not a knockout round.';
    } else {
        $ctx = $roundContextMap[(int)$round['id']] ?? null;
        if (is_array($ctx)) {
            $roundSportName = trim((string)($ctx['nama_sukan'] ?? '')) ?: '-';
            $roundCategoryName = trim((string)($ctx['nama_kategori'] ?? '')) ?: '-';
        }
        $ruleDecoded = kre_decode_rule((string)($round['qualification_rule'] ?? ''));
        $seedRows = kre_extract_seed_slots($ruleDecoded);
        $advanceRows = kre_extract_advance_rows($ruleDecoded);
    }
} else {
    $errors[] = 'Select a knockout round to start editing its rules.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $round && empty($errors)) {
    $seedRows = [];
    $advanceRows = [];

    $seedSlots = kre_collect_seed_slots_from_post($_POST, $errors);
    $advanceMap = kre_collect_advance_map_from_post($_POST, $errors);

    foreach ($seedSlots as $slot) {
        $seedRows[] = ['home' => (string)$slot['home'], 'away' => (string)$slot['away']];
    }
    foreach ($advanceMap as $source => $targets) {
        foreach ($targets as $target) {
            $advanceRows[] = [
                'source' => (int)$source,
                'target' => (int)($target['match_no'] ?? 0),
                'slot' => (string)($target['slot'] ?? 'away'),
                'outcome' => (string)($target['outcome'] ?? 'winner'),
            ];
        }
    }

    if (empty($errors)) {
        $ruleDecoded = is_array($ruleDecoded) ? $ruleDecoded : [];
        $ruleDecoded['seed_slots'] = $seedSlots;
        $ruleDecoded['advance_map'] = $advanceMap;
        $encoded = json_encode($ruleDecoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $errors[] = 'Unable to encode qualification_rule.';
        } else {
            $upd = $db->prepare('UPDATE table_round SET qualification_rule = :rule WHERE id = :id');
            $upd->execute([
                ':rule' => $encoded,
                ':id' => (int)$round['id'],
            ]);
            $successMsg = 'Knockout rules saved successfully.';
            $round['qualification_rule'] = $encoded;
        }
    }
}

if (empty($seedRows)) $seedRows = [['home' => '', 'away' => '']];
if (empty($advanceRows)) $advanceRows = [['source' => '', 'target' => '', 'slot' => 'away', 'outcome' => 'winner']];

ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/knockout-editor.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/knockout-editor.css'); ?>">
<div class="w-100 px-3 knockout-editor">
    <div class="row mb-3">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                        <div>
                            <div class="knockout-eyebrow">SAM 2026 &middot; KNOCKOUT RULES</div><h4 class="mb-1">Knockout Rule Editor</h4>
                            <p class="text-muted mb-0">Update <code>seed_slots</code> and <code>advance_map</code> for knockout rounds.</p>
                        </div>
                        <div>
                            <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars(url('pages/round-standing.php') . ($roundId > 0 ? '?round_id=' . (int)$roundId : ''), ENT_QUOTES, 'UTF-8'); ?>">
                                Back to Round Standings
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="<?php echo htmlspecialchars(url('pages/knockout-rule-editor.php'), ENT_QUOTES, 'UTF-8'); ?>" class="d-flex align-items-center gap-2 flex-wrap">
                <label class="knockout-filter-label" for="roundPicker">Select Round Knockout</label><select id="roundPicker" name="round_id" class="form-select" style="max-width:900px; min-width:560px;">
                    <option value="">-- Select Knockout Round --</option>
                    <?php foreach ($knockoutRoundOptions as $opt): ?>
                        <?php
                        $oid = (int)($opt['id'] ?? 0);
                        $oname = trim((string)($opt['nama_round'] ?? 'Knockout'));
                        $ogc = trim((string)($opt['group_code'] ?? ''));
                        $ost = trim((string)($opt['status'] ?? ''));
                        $osport = trim((string)($opt['nama_sukan'] ?? ''));
                        $ocat = trim((string)($opt['nama_kategori'] ?? ''));
                        $label = '#' . $oid . ' - ' . $oname;
                        if ($ogc !== '') $label .= ' [' . $ogc . ']';
                        if ($osport !== '' || $ocat !== '') $label .= ' | ' . ($osport !== '' ? $osport : '-') . ' / ' . ($ocat !== '' ? $ocat : '-');
                        if ($ost !== '') $label .= ' (' . $ost . ')';
                        ?>
                        <option value="<?php echo $oid; ?>" <?php echo ($roundId === $oid) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline-primary">Open</button>
            </form>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <div class="fw-semibold mb-1">Please check your input:</div>
            <ul class="mb-0">
                <?php foreach ($errors as $e): ?>
                    <li><?php echo htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($successMsg !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($round): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="small text-muted">Round ID</div>
                        <div class="fw-semibold"><?php echo (int)$round['id']; ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted">Round Name</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars((string)($round['nama_round'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="col-md-2">
                        <div class="small text-muted">Sport</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($roundSportName, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="col-md-2">
                        <div class="small text-muted">Category</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($roundCategoryName, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="col-md-1">
                        <div class="small text-muted">Type</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars((string)($round['round_type'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="col-md-1">
                        <div class="small text-muted">Status</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars((string)($round['status'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST" action="<?php echo htmlspecialchars(url('pages/knockout-rule-editor.php') . '?round_id=' . (int)$round['id'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="round_id" value="<?php echo (int)$round['id']; ?>">

            <div class="card shadow-sm mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Seed Slots</strong>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addSeedRowBtn">Add Row</button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" id="seedTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:45%;">Home Token</th>
                                    <th style="width:45%;">Away Token</th>
                                    <th style="width:10%;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($seedRows as $row): ?>
                                    <tr>
                                        <td><input type="text" class="form-control form-control-sm" name="seed_home[]" value="<?php echo htmlspecialchars((string)($row['home'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="WINNER_A"></td>
                                        <td><input type="text" class="form-control form-control-sm" name="seed_away[]" value="<?php echo htmlspecialchars((string)($row['away'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="RUNNER_UP_B"></td>
                                        <td><button type="button" class="btn btn-sm btn-outline-danger seed-remove">Delete</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Advance Map</strong>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addAdvanceRowBtn">Add Row</button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" id="advanceTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:20%;">Source Match No</th>
                                    <th style="width:20%;">Target Match No</th>
                                    <th style="width:20%;">Slot</th>
                                    <th style="width:20%;">Outcome</th>
                                    <th style="width:20%;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($advanceRows as $row): ?>
                                    <tr>
                                        <td><input type="number" min="1" class="form-control form-control-sm" name="adv_source[]" value="<?php echo htmlspecialchars((string)($row['source'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="16"></td>
                                        <td><input type="number" min="1" class="form-control form-control-sm" name="adv_target[]" value="<?php echo htmlspecialchars((string)($row['target'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="18"></td>
                                        <td>
                                            <select class="form-select form-select-sm" name="adv_slot[]">
                                                <option value="home" <?php echo ((string)($row['slot'] ?? '') === 'home') ? 'selected' : ''; ?>>home</option>
                                                <option value="away" <?php echo ((string)($row['slot'] ?? 'away') === 'away') ? 'selected' : ''; ?>>away</option>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm" name="adv_outcome[]">
                                                <option value="winner" <?php echo ((string)($row['outcome'] ?? 'winner') === 'winner') ? 'selected' : ''; ?>>winner</option>
                                                <option value="loser" <?php echo ((string)($row['outcome'] ?? '') === 'loser') ? 'selected' : ''; ?>>loser</option>
                                            </select>
                                        </td>
                                        <td><button type="button" class="btn btn-sm btn-outline-danger advance-remove">Delete</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header"><strong>Preview Flow (Live)</strong></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="small text-muted mb-2">Seed Pairing</div>
                            <div id="seedPreview" class="small"></div>
                        </div>
                        <div class="col-md-6">
                            <div class="small text-muted mb-2">Advance Graph</div>
                            <div id="advancePreview" class="small"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save Rules</button>
                <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars(url('pages/bracket.php') . '?round_id=' . (int)$round['id'], ENT_QUOTES, 'UTF-8'); ?>">View Bracket</a>
            </div>
        </form>

        <div class="card shadow-sm mt-3">
            <div class="card-header"><strong>Current qualification_rule JSON</strong></div>
            <div class="card-body">
                <pre class="mb-0" style="white-space:pre-wrap;"><?php echo htmlspecialchars((string)($round['qualification_rule'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></pre>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var seedTable = document.getElementById('seedTable');
    var advanceTable = document.getElementById('advanceTable');
    var addSeedBtn = document.getElementById('addSeedRowBtn');
    var addAdvanceBtn = document.getElementById('addAdvanceRowBtn');
    var seedPreviewEl = document.getElementById('seedPreview');
    var advancePreviewEl = document.getElementById('advancePreview');

    function escHtml(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function normalizeSeedToken(token) {
        var t = String(token || '').trim().toUpperCase();
        t = t.replace(/[-.]/g, '_');
        t = t.replace(/\s+/g, '_');
        t = t.replace(/__+/g, '_');
        return t;
    }

    function isValidSeedToken(token) {
        return /^(WINNER|RUNNER_UP)_[A-Z0-9]+$/.test(String(token || ''));
    }

    function collectSeedRows() {
        if (!seedTable) return [];
        var rows = [];
        var trs = seedTable.querySelectorAll('tbody tr');
        trs.forEach(function (tr) {
            var homeEl = tr.querySelector('input[name="seed_home[]"]');
            var awayEl = tr.querySelector('input[name="seed_away[]"]');
            var home = (homeEl ? homeEl.value : '').trim();
            var away = (awayEl ? awayEl.value : '').trim();
            if (!home && !away) return;
            rows.push({ home: home, away: away });
        });
        return rows;
    }

    function collectAdvanceRows() {
        if (!advanceTable) return [];
        var rows = [];
        var trs = advanceTable.querySelectorAll('tbody tr');
        trs.forEach(function (tr) {
            var srcEl = tr.querySelector('input[name="adv_source[]"]');
            var dstEl = tr.querySelector('input[name="adv_target[]"]');
            var slotEl = tr.querySelector('select[name="adv_slot[]"]');
            var outcomeEl = tr.querySelector('select[name="adv_outcome[]"]');
            var src = (srcEl ? srcEl.value : '').trim();
            var dst = (dstEl ? dstEl.value : '').trim();
            var slot = (slotEl ? slotEl.value : '').trim();
            var outcome = (outcomeEl ? outcomeEl.value : '').trim();
            if (!src && !dst && !slot && !outcome) return;
            rows.push({ source: src, target: dst, slot: slot, outcome: outcome });
        });
        return rows;
    }

    function renderPreview() {
        var totalWarnings = 0;

        if (seedPreviewEl) {
            var seedRows = collectSeedRows();
            if (!seedRows.length) {
                seedPreviewEl.innerHTML = '<span class="text-muted">No seed slots.</span>';
            } else {
                var htmlSeed = '<ol class="mb-0">';
                var usedTokens = {};
                seedRows.forEach(function (r) {
                    var hRaw = r.home || '';
                    var aRaw = r.away || '';
                    var h = normalizeSeedToken(hRaw);
                    var a = normalizeSeedToken(aRaw);
                    var warns = [];
                    if (!h || !a) warns.push('home/away is empty');
                    if (h && !isValidSeedToken(h)) warns.push('invalid home format');
                    if (a && !isValidSeedToken(a)) warns.push('invalid away format');
                    if (h && a && h === a) warns.push('home=away');
                    if (h && usedTokens[h]) warns.push('home duplicate');
                    if (a && usedTokens[a]) warns.push('away duplicate');
                    if (h) usedTokens[h] = true;
                    if (a) usedTokens[a] = true;
                    if (warns.length) totalWarnings += warns.length;

                    htmlSeed += '<li><code>' + escHtml(h || hRaw || '(empty)') + '</code> <span class="text-muted">vs</span> <code>' + escHtml(a || aRaw || '(empty)') + '</code>';
                    if (warns.length) {
                        htmlSeed += ' <span class="badge bg-warning text-dark">Warning</span> <span class="text-warning">[' + escHtml(warns.join(', ')) + ']</span>';
                    }
                    htmlSeed += '</li>';
                });
                htmlSeed += '</ol>';
                seedPreviewEl.innerHTML = htmlSeed;
            }
        }

        if (advancePreviewEl) {
            var advRows = collectAdvanceRows();
            if (!advRows.length) {
                advancePreviewEl.innerHTML = '<span class="text-muted">No advance map.</span>';
            } else {
                var grouped = {};
                advRows.forEach(function (r) {
                    var key = String(r.source || '').trim();
                    if (!key) key = '?';
                    if (!grouped[key]) grouped[key] = [];
                    grouped[key].push(r);
                });
                var sources = Object.keys(grouped).sort(function (a, b) { return Number(a) - Number(b); });
                var html = '';
                sources.forEach(function (src) {
                    html += '<div class="mb-2"><div><span class="badge bg-secondary">M' + escHtml(src) + '</span></div>';
                    grouped[src].forEach(function (r) {
                        var target = String(r.target || '?');
                        var slot = String(r.slot || '?');
                        var outcome = String(r.outcome || '?');
                        var warns = [];
                        var srcNum = parseInt(String(r.source || ''), 10);
                        var dstNum = parseInt(String(r.target || ''), 10);
                        if (!(srcNum > 0)) warns.push('invalid source');
                        if (!(dstNum > 0)) warns.push('invalid target');
                        if (slot !== 'home' && slot !== 'away') warns.push('invalid slot');
                        if (outcome !== 'winner' && outcome !== 'loser') warns.push('invalid outcome');
                        if (warns.length) totalWarnings += warns.length;

                        html += '<div class="ps-2 mt-1">M' + escHtml(src)
                            + ' &rarr; M' + escHtml(target)
                            + ' <span class="text-muted">(' + escHtml(outcome) + ' / ' + escHtml(slot) + ')</span></div>';
                        if (warns.length) {
                            html += '<div class="ps-2"><span class="badge bg-warning text-dark">Warning</span> <span class="text-warning">[' + escHtml(warns.join(', ')) + ']</span></div>';
                        }
                    });
                    html += '</div>';
                });
                if (totalWarnings > 0) {
                    html = '<div class="alert alert-warning py-1 px-2 mb-2">There are ' + escHtml(totalWarnings) + ' warnings in the input preview.</div>' + html;
                } else {
                    html = '<div class="alert alert-success py-1 px-2 mb-2">Valid preview (no warnings).</div>' + html;
                }
                advancePreviewEl.innerHTML = html;
            }
        }
    }

    function removeRowOnClick(e, className, tableEl) {
        if (!tableEl || !e.target || !e.target.classList.contains(className)) return;
        var body = tableEl.querySelector('tbody');
        if (!body) return;
        var row = e.target.closest('tr');
        if (!row) return;
        if (body.querySelectorAll('tr').length <= 1) {
            var inputs = row.querySelectorAll('input');
            inputs.forEach(function (inp) { inp.value = ''; });
            var selects = row.querySelectorAll('select');
            selects.forEach(function (sel) { sel.selectedIndex = 0; });
            renderPreview();
            return;
        }
        row.remove();
        renderPreview();
    }

    if (addSeedBtn && seedTable) {
        addSeedBtn.addEventListener('click', function () {
            var body = seedTable.querySelector('tbody');
            if (!body) return;
            var tr = document.createElement('tr');
            tr.innerHTML = '<td><input type="text" class="form-control form-control-sm" name="seed_home[]" placeholder="WINNER_A"></td>'
                + '<td><input type="text" class="form-control form-control-sm" name="seed_away[]" placeholder="RUNNER_UP_B"></td>'
                + '<td><button type="button" class="btn btn-sm btn-outline-danger seed-remove">Delete</button></td>';
            body.appendChild(tr);
            renderPreview();
        });
        seedTable.addEventListener('click', function (e) { removeRowOnClick(e, 'seed-remove', seedTable); });
        seedTable.addEventListener('input', renderPreview);
        seedTable.addEventListener('change', renderPreview);
    }

    if (addAdvanceBtn && advanceTable) {
        addAdvanceBtn.addEventListener('click', function () {
            var body = advanceTable.querySelector('tbody');
            if (!body) return;
            var tr = document.createElement('tr');
            tr.innerHTML = '<td><input type="number" min="1" class="form-control form-control-sm" name="adv_source[]" placeholder="16"></td>'
                + '<td><input type="number" min="1" class="form-control form-control-sm" name="adv_target[]" placeholder="18"></td>'
                + '<td><select class="form-select form-select-sm" name="adv_slot[]"><option value="home">home</option><option value="away" selected>away</option></select></td>'
                + '<td><select class="form-select form-select-sm" name="adv_outcome[]"><option value="winner" selected>winner</option><option value="loser">loser</option></select></td>'
                + '<td><button type="button" class="btn btn-sm btn-outline-danger advance-remove">Delete</button></td>';
            body.appendChild(tr);
            renderPreview();
        });
        advanceTable.addEventListener('click', function (e) { removeRowOnClick(e, 'advance-remove', advanceTable); });
        advanceTable.addEventListener('input', renderPreview);
        advanceTable.addEventListener('change', renderPreview);
    }

    renderPreview();
})();
</script>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
