<?php
/**
 * Participation Certificates
 * Access: ADMIN, CONTINGENT, VIEWER
 *
 * This page reuses the managers/coaches AJAX endpoint from pages/ringkasan.php
 * to avoid duplicating SQL/business logic. It also queries athletes directly
 * for athlete certificates. Access controlled via RBAC same as other pages.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/rbac.php';

Session::start();
$auth = getAuth();
$auth->requireAuth();
$rbac = getRBAC();
$rbac->requirePageAccess('pages/sijil-user.php');

$kontinjen_display = '';
$page_title = 'Participation Certificates';

// load list of universities (same approach as ringkasan)
// Populate list of universities for the select (reuse ringkasan logic)
$unis = [];
try {
    $db = getDB();
    $sqlUnis = "SELECT kod_universiti, nama_pendek, nama_universiti FROM table_ref_universiti WHERE status = 1 ORDER BY COALESCE(NULLIF(nama_pendek,''), nama_universiti)";
    $stUn = $db->query($sqlUnis);
    $rowsUn = $stUn->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rowsUn as $u) {
        $kodu = trim((string)($u['kod_universiti'] ?? ''));
        $short = trim((string)($u['nama_pendek'] ?? ''));
        $full = trim((string)($u['nama_universiti'] ?? ''));
        $display = $short !== '' ? $short : ($full !== '' ? $full : $kodu);
        if ($kodu === '') continue;
        $unis[] = ['kod_universiti' => $kodu, 'nama_universiti' => $display];
    }
} catch (Exception $e) {
    $unis = [];
}
// ensure $kod is defined (may be provided via GET when returning from form)
$kod = strtoupper(trim((string)($_GET['kod'] ?? '')));

// If the logged-in user is a CONTINGENT, restrict view to their kontinjen only
$is_contingent_user = false;
try {
    if ($auth->hasRole('CONTINGENT')) {
        $is_contingent_user = true;
        $kontinjen_id = Session::get('kontinjen_id');
        if (!empty($kontinjen_id)) {
            try {
                $db = getDB();
                $stK = $db->prepare('SELECT UPPER(COALESCE(kod_universiti, "")) AS kod FROM table_kontinjen WHERE id = :id LIMIT 1');
                $stK->execute([':id' => $kontinjen_id]);
                $krow = $stK->fetch(PDO::FETCH_ASSOC);
                if ($krow && !empty($krow['kod'])) {
                    $kod = strtoupper(trim((string)$krow['kod']));
                    // resolve display name for the kontinjen if possible
                    try {
                        $stR = $db->prepare('SELECT COALESCE(NULLIF(nama_pendek, ""), nama_universiti, kod_universiti) AS display FROM table_ref_universiti WHERE kod_universiti = :kod LIMIT 1');
                        $stR->execute([':kod' => $kod]);
                        $r2 = $stR->fetch(PDO::FETCH_ASSOC);
                        if ($r2 && !empty($r2['display'])) $kontinjen_display = (string)$r2['display'];
                    } catch (Exception $e) { /* ignore */ }
                }
            } catch (Exception $e) {
                // leave $kod as-is on error
            }
        }
    }
} catch (Exception $e) {
    $is_contingent_user = false;
}

// If we have a kontinjen kod, show it in the page title as requested: "Participation Certificates [KOD]"
if (!empty($kod)) {
    $page_title = 'Participation Certificates ' . strtoupper($kod);
}

// ensure template image URL variable exists to avoid PHP notices
    if (!isset($img_url_versioned) || !$img_url_versioned) {
    $relPath = '/assets/img/sijil/sijil_atlet.jpeg';
    $fullPath = realpath(__DIR__ . '/..') . $relPath;
    $ver = @file_exists($fullPath) ? @filemtime($fullPath) : time();
    $img_url_versioned = $relPath . '?v=' . $ver;
}

$ajax = trim((string)($_GET['ajax'] ?? ''));
if ($ajax === 'athletes') {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'rows' => [], 'elapsed_ms' => null, 'error' => null, 'count' => 0];
    $k = strtoupper(trim((string)($_GET['kod'] ?? '')));
    if ($k === '') {
        echo json_encode($out);
        exit;
    }
    $t0 = microtime(true);
    try {
        $db = getDB();
        $sqlA = "SELECT pa.id AS id, pa.pasukan_id, p.sukan_id, pa.kategori_id,
                        TRIM(pa.nama) AS nama,
                        COALESCE(s.nama_sukan, '') AS sukan,
                        COALESCE(kt.nama_kategori, '') AS acara
            FROM table_pasukan_atlet pa
            JOIN table_pasukan p ON p.id = pa.pasukan_id
            LEFT JOIN table_sukan s ON s.id = p.sukan_id
            LEFT JOIN table_kategori kt ON kt.id = pa.kategori_id
            LEFT JOIN table_kontinjen k ON k.id = p.kontinjen_id
            WHERE UPPER(COALESCE(k.kod_universiti,'')) = :kod_val
              AND pa.deleted_at IS NULL
              AND p.deleted_at IS NULL
            ORDER BY s.nama_sukan, kt.nama_kategori, pa.nama";
        $stA = $db->prepare($sqlA);
        $stA->execute([':kod_val' => $k]);
        $rows = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!empty($rows)) {
            $athleteCtxExact = [];
            $teamCtxExact = [];
            $athleteCtxSukan = [];
            $teamCtxSukan = [];
            foreach ($rows as $r0) {
                $aid = (int)($r0['id'] ?? 0);
                $tid = (int)($r0['pasukan_id'] ?? 0);
                $sid = (int)($r0['sukan_id'] ?? 0);
                $kid = (int)($r0['kategori_id'] ?? 0);
                if ($sid <= 0) continue;
                if ($aid > 0) {
                    $athleteCtxExact[$sid . '|' . $kid . '|' . $aid] = true;
                    $athleteCtxSukan[$sid . '|' . $aid] = true;
                }
                if ($tid > 0) {
                    $teamCtxExact[$sid . '|' . $kid . '|' . $tid] = true;
                    $teamCtxSukan[$sid . '|' . $tid] = true;
                }
            }

            $rank = ['gold' => 3, 'silver' => 2, 'bronze' => 1];
            $athleteMedalExact = [];
            $teamMedalExact = [];
            $athleteMedalBySukan = [];
            $teamMedalBySukan = [];
            $pickBest = static function (string $curr, string $next) use ($rank): string {
                $c = $rank[$curr] ?? 0;
                $n = $rank[$next] ?? 0;
                return ($n > $c) ? $next : $curr;
            };
            $medalFromPos = static function ($pos): string {
                $p = (int)$pos;
                if ($p === 1) return 'gold';
                if ($p === 2) return 'silver';
                if ($p === 3) return 'bronze';
                return '';
            };
            $extractIds = static function ($v): array {
                if (is_array($v)) {
                    $out = [];
                    foreach ($v as $x) {
                        $n = (int)$x;
                        if ($n > 0) $out[] = $n;
                    }
                    return array_values(array_unique($out));
                }
                $s = trim((string)$v);
                if ($s === '') return [];
                if (preg_match_all('/\d+/', $s, $m)) {
                    $out = [];
                    foreach (($m[0] ?? []) as $x) {
                        $n = (int)$x;
                        if ($n > 0) $out[] = $n;
                    }
                    return array_values(array_unique($out));
                }
                return [];
            };

            $resSql = "
                SELECT sukan_id, kategori_id, standings
                FROM table_results
                WHERE deleted_at IS NULL
                  AND status = 'completed'
                  AND standings IS NOT NULL
                  AND TRIM(CAST(standings AS CHAR)) <> ''
            ";
            $resSt = $db->query($resSql);
            $resRows = $resSt ? ($resSt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            foreach ($resRows as $rr) {
                $resSukanId = (int)($rr['sukan_id'] ?? 0);
                $resKategoriId = (int)($rr['kategori_id'] ?? 0);
                if ($resSukanId <= 0) continue;
                $decoded = json_decode((string)($rr['standings'] ?? ''), true);
                if (!is_array($decoded)) continue;
                foreach ($decoded as $item) {
                    if (!is_array($item)) continue;
                    $medal = $medalFromPos($item['position'] ?? null);
                    if ($medal === '') continue;
                    $pidList = $extractIds($item['participant_id'] ?? ($item['pasukan_id'] ?? ''));
                    foreach ($pidList as $pid) {
                        $aKeyExact = $resSukanId . '|' . $resKategoriId . '|' . $pid;
                        $tKeyExact = $resSukanId . '|' . $resKategoriId . '|' . $pid;
                        $aKeySukan = $resSukanId . '|' . $pid;
                        $tKeySukan = $resSukanId . '|' . $pid;
                        if (isset($athleteCtxExact[$aKeyExact]) || ($resKategoriId <= 0 && isset($athleteCtxSukan[$aKeySukan]))) {
                            if ($resKategoriId > 0) {
                                $prev = $athleteMedalExact[$aKeyExact] ?? '';
                                $athleteMedalExact[$aKeyExact] = $pickBest($prev, $medal);
                            } else {
                                $prev = $athleteMedalBySukan[$aKeySukan] ?? '';
                                $athleteMedalBySukan[$aKeySukan] = $pickBest($prev, $medal);
                            }
                        }
                        if (isset($teamCtxExact[$tKeyExact]) || ($resKategoriId <= 0 && isset($teamCtxSukan[$tKeySukan]))) {
                            if ($resKategoriId > 0) {
                                $prev = $teamMedalExact[$tKeyExact] ?? '';
                                $teamMedalExact[$tKeyExact] = $pickBest($prev, $medal);
                            } else {
                                $prev = $teamMedalBySukan[$tKeySukan] ?? '';
                                $teamMedalBySukan[$tKeySukan] = $pickBest($prev, $medal);
                            }
                        }
                    }
                }
            }

            foreach ($rows as &$r1) {
                $aid = (int)($r1['id'] ?? 0);
                $tid = (int)($r1['pasukan_id'] ?? 0);
                $sid = (int)($r1['sukan_id'] ?? 0);
                $kid = (int)($r1['kategori_id'] ?? 0);
                $aKeyExact = $sid . '|' . $kid . '|' . $aid;
                $tKeyExact = $sid . '|' . $kid . '|' . $tid;
                $aKeySukan = $sid . '|' . $aid;
                $tKeySukan = $sid . '|' . $tid;
                $mAth = $athleteMedalExact[$aKeyExact] ?? ($athleteMedalBySukan[$aKeySukan] ?? '');
                $mTeam = $teamMedalExact[$tKeyExact] ?? ($teamMedalBySukan[$tKeySukan] ?? '');
                $r1['medal_type'] = $mAth !== '' ? $mAth : $mTeam;
            }
            unset($r1);
        }

        $out['ok'] = true;
        $out['rows'] = $rows;
        $out['count'] = count($rows);
    } catch (Exception $e) {
        $out['ok'] = false;
        $out['error'] = $e->getMessage();
    }
    $out['elapsed_ms'] = (int)( (microtime(true) - $t0) * 1000 );
    echo json_encode($out);
    exit;
}

if ($ajax === 'penyelaras') {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'rows' => [], 'elapsed_ms' => null, 'error' => null, 'count' => 0];
    $k = strtoupper(trim((string)($_GET['kod'] ?? '')));
    if ($k === '') {
        echo json_encode($out);
        exit;
    }
    $t0 = microtime(true);
    try {
        $db = getDB();
        // Use normalized UNION of table_kontinjen and users, dedupe by cleaned key_name
        $sql = <<<'SQL'
SELECT
    MIN(id) AS id,
    MAX(nama) AS nama,
    MAX(emel) AS emel,
    MAX(telefon) AS telefon
FROM (
    SELECT
        id,
        UPPER(
            REGEXP_REPLACE(TRIM(nama), '[^A-Z0-9 ]', '')
        ) AS key_name,
        TRIM(nama) AS nama,
        TRIM(emel) AS emel,
        REGEXP_REPLACE(TRIM(telefon), '[^0-9]', '') AS telefon
    FROM (
        -- table_kontinjen
        SELECT
            id,
            nama_pegawai_untuk_dihubungi AS nama,
            emel,
            no_telefon AS telefon
        FROM table_kontinjen
        WHERE UPPER(COALESCE(kod_universiti,'')) = ?
          AND deleted_at IS NULL

        UNION ALL

        -- users (join kontingen)
        SELECT
            u.id,
            u.full_name AS nama,
            u.email AS emel,
            u.phone AS telefon
        FROM users u
        JOIN table_kontinjen k ON k.id = u.kontinjen_id
        WHERE UPPER(COALESCE(k.kod_universiti,'')) = ?
          AND u.deleted_at IS NULL
          AND k.deleted_at IS NULL
    ) x
) y
GROUP BY key_name
ORDER BY nama
SQL;
        $st = $db->prepare($sql);
        $st->execute([$k, $k]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // fallback to simple table_kontinjen query if no rows returned
        if (empty($rows)) {
            try {
                $sql2 = "SELECT id, TRIM(nama_pegawai_untuk_dihubungi) AS nama, TRIM(emel) AS emel, TRIM(no_telefon) AS telefon FROM table_kontinjen WHERE UPPER(COALESCE(kod_universiti,'')) = ? AND deleted_at IS NULL ORDER BY nama_pegawai_untuk_dihubungi";
                $st2 = $db->prepare($sql2);
                $st2->execute([$k]);
                $rows2 = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!empty($rows2)) $rows = $rows2;
            } catch (Exception $e2) {
                // keep $rows as empty
            }
        }
        $out['ok'] = true;
        $out['rows'] = $rows;
        $out['count'] = count($rows);
    } catch (Exception $e) {
        $out['ok'] = false;
        $out['error'] = $e->getMessage();
    }
    $out['elapsed_ms'] = (int)( (microtime(true) - $t0) * 1000 );
    echo json_encode($out);
    exit;
}

// Provide a local AJAX endpoint for managers/coaches to avoid cross-page HTTP
// requests (which can return HTML Access Denied pages when RBAC blocks other
// pages). This uses the same DB helper above to return JSON.
if ($ajax === 'managers') {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'rows' => [], 'elapsed_ms' => null, 'error' => null, 'count' => 0];
    $k = strtoupper(trim((string)($_GET['kod'] ?? '')));
    if ($k === '') {
        echo json_encode($out);
        exit;
    }
    $t0 = microtime(true);
    try {
        $rows = fetch_managers_from_ringkasan($k);
        $out['ok'] = true;
        $out['rows'] = $rows;
        $out['count'] = count($rows);
    } catch (Exception $e) {
        $out['ok'] = false;
        $out['error'] = $e->getMessage();
    }
    $out['elapsed_ms'] = (int)( (microtime(true) - $t0) * 1000 );
    echo json_encode($out);
    exit;
}

// Handle printing all athletes for a kontinjen (multi-page HTML or combined PDF)
$printAll = trim((string)($_GET['print_all'] ?? ''));
if ($printAll !== '') {
    $kodAll = strtoupper(trim((string)($_GET['kod'] ?? '')));
    if ($kodAll === '') {
        http_response_code(400);
        echo "Contingent code is required.";
        exit;
    }
    $type = strtolower(trim((string)($_GET['type'] ?? 'athletes')));
    try {
        if ($type === 'athletes') {
            $db = getDB();
            $sqlAll = "SELECT pa.id AS id, TRIM(pa.nama) AS nama, COALESCE(s.nama_sukan,'') AS sukan, COALESCE(kt.nama_kategori,'') AS acara
                FROM table_pasukan_atlet pa
                JOIN table_pasukan p ON p.id = pa.pasukan_id
                LEFT JOIN table_sukan s ON s.id = p.sukan_id
                LEFT JOIN table_kategori kt ON kt.id = pa.kategori_id
                LEFT JOIN table_kontinjen k ON k.id = p.kontinjen_id
                WHERE UPPER(COALESCE(k.kod_universiti,'')) = :kod_val
                  AND pa.deleted_at IS NULL
                  AND p.deleted_at IS NULL
                ORDER BY s.nama_sukan, kt.nama_kategori, pa.nama";
            $stAll = $db->prepare($sqlAll);
            $stAll->execute([':kod_val' => $kodAll]);
            $rowsAll = $stAll->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            // build rows from managers endpoint
            $rowsAll = [];
            $mgrs = fetch_managers_from_ringkasan($kodAll);
            foreach ($mgrs as $m) {
                $acara = trim((string)($m['acara'] ?? ''));
                if ($type === 'pengurus') {
                    if (!empty($m['pengurus'])) {
                        $parts = explode(' ||| ', $m['pengurus']);
                        foreach ($parts as $p) {
                            $p = trim($p);
                            if ($p !== '') {
                                $nama = $p;
                                $jawatan = '';
                                if (strpos($p, '@@JAWATAN@@') !== false) {
                                    $segNama = explode('@@JAWATAN@@', $p, 2);
                                    $nama = trim((string)($segNama[0] ?? ''));
                                    $rest1 = (string)($segNama[1] ?? '');
                                    $segTel = explode('@@TEL@@', $rest1, 2);
                                    $jawatan = trim((string)($segTel[0] ?? ''));
                                }
                                $rowsAll[] = ['nama' => $nama, 'jawatan' => $jawatan, 'sukan' => '', 'acara' => $acara];
                            }
                        }
                    }
                } else if ($type === 'jurulatih') {
                    if (!empty($m['jurulatih'])) {
                        $parts = explode(' ||| ', $m['jurulatih']);
                        foreach ($parts as $p) {
                            $p = trim($p);
                            if ($p !== '') {
                                $nama = $p;
                                $jawatan = '';
                                if (strpos($p, '@@JAWATAN@@') !== false) {
                                    $segNama = explode('@@JAWATAN@@', $p, 2);
                                    $nama = trim((string)($segNama[0] ?? ''));
                                    $rest1 = (string)($segNama[1] ?? '');
                                    $segTel = explode('@@TEL@@', $rest1, 2);
                                    $jawatan = trim((string)($segTel[0] ?? ''));
                                }
                                $rowsAll[] = ['nama' => $nama, 'jawatan' => $jawatan, 'sukan' => '', 'acara' => $acara];
                            }
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        $rowsAll = [];
    }

    if (empty($rowsAll)) {
        http_response_code(404);
        echo "No athletes found for this contingent.";
        exit;
    }

    // Build combined HTML with one .page per athlete
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // choose template based on type
        if ($type === 'pengurus') {
        $img_rel = '/assets/img/sijil/sijil_pengurus.jpeg';
    } else if ($type === 'jurulatih') {
        $img_rel = '/assets/img/sijil/sijil_jurulatih.jpeg';
    } else {
        // athletes -> use new athlete template
        $img_rel = '/assets/img/sijil/sijil_atlet.jpeg';
    }
    // Build absolute URL including BASE_URL so it works in subfolder deployments
    $absTemplateUrl = $scheme . '://' . $host . url(ltrim($img_rel, '/'));

    $multiHtml = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Participation Certificates - All</title>' .
        '<style>@page{size:210mm 297mm;margin:0}@media print{html,body{width:210mm;margin:0!important;padding:0!important}.page{width:210mm!important;height:297mm!important;margin:0!important;padding:0!important;border:0!important;box-sizing:border-box;break-inside:avoid;page-break-inside:avoid}.bg-img{object-fit:cover!important;left:-0.5mm!important;top:-0.5mm!important;width:211mm!important;height:298mm!important;max-width:none!important}}html,body{height:100%;margin:0;padding:0}body{background:#fff;font-family:Arial,Helvetica,sans-serif}.page{position:relative;width:210mm;height:297mm;overflow:hidden}.bg-img{position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;z-index:0}.cert-name{position:absolute;left:50%;top:38%;transform:translate(-50%,-50%);width:78%;text-align:center;font-weight:700;color:#000;line-height:1.05;z-index:1;font-size:20px}.cert-sport{position:absolute;left:50%;top:48%;transform:translateX(-50%);width:78%;text-align:center;color:#000;font-weight:700;z-index:1;font-size:20px}.page,.bg-img{-webkit-print-color-adjust:exact;print-color-adjust:exact}</style></head><body>';

    foreach ($rowsAll as $ra) {
        $rname = trim((string)($ra['nama'] ?? ''));
        // strip phone/email in parentheses or trailing emails for cleaner name
        $rname = preg_replace('/\s*\([^)]*\)/', '', $rname);
        $rname = preg_replace('/\s+\S+@\S+$/', '', $rname);
        $rname = trim($rname);
        $rsukan = trim((string)($ra['sukan'] ?? ''));
        $racara = trim((string)($ra['acara'] ?? ''));
        // only show sport/event for athletes; pengurus/jurulatih show name only
        if ($type === 'athletes') {
            $sukan_combo = $rsukan;
            if ($racara !== '') {
                $sukan_combo = $sukan_combo !== '' ? $sukan_combo . ' (' . $racara . ')' : $racara;
            }
            // uppercase sport/event for printed certificates
            $sukan_combo = mb_strtoupper(english_label($sukan_combo), 'UTF-8');
        } else {
            // For non-athletes, show role label in the same position
            if ($type === 'pengurus') {
                $sukan_combo = trim((string)($ra['jawatan'] ?? ''));
                if ($sukan_combo === '') $sukan_combo = 'PENGURUS';
                $sukan_combo = mb_strtoupper(english_label($sukan_combo), 'UTF-8');
            } else if ($type === 'jurulatih') {
                $sukan_combo = trim((string)($ra['jawatan'] ?? ''));
                if ($sukan_combo === '') $sukan_combo = 'JURULATIH';
                $sukan_combo = mb_strtoupper(english_label($sukan_combo), 'UTF-8');
            } else if ($type === 'penyelaras') {
                $sukan_combo = mb_strtoupper(english_label('KETUA KONTINJEN'), 'UTF-8');
            } else {
                $sukan_combo = '';
            }
        }
        $multiHtml .= '<div class="page">' .
            '<img class="bg-img" src="' . htmlspecialchars($absTemplateUrl, ENT_QUOTES, 'UTF-8') . '" alt="background">' .
            '<div class="cert-name">' . htmlspecialchars($rname, ENT_QUOTES, 'UTF-8') . '</div>' .
            '<div class="cert-sport">' . htmlspecialchars(english_sport_label(english_label($sukan_combo)), ENT_QUOTES, 'UTF-8') . '</div>' .
        '</div>';
    }

    $multiHtml .= '<script> (function(){ if(window.top===window.self){ setTimeout(function(){ window.print(); },180); } })();</script></body></html>';

    // If download requested, attempt wkhtmltopdf generation
    $downloadAll = trim((string)($_GET['download'] ?? $_GET['dl'] ?? ''));
    if ($downloadAll === '1' || strtolower($downloadAll) === 'true') {
        $tmpDir = sys_get_temp_dir();
        $tmpHtml = tempnam($tmpDir, 'sijil_all_') . '.html';
        $tmpPdf = tempnam($tmpDir, 'sijil_all_') . '.pdf';
        file_put_contents($tmpHtml, $multiHtml);

        $wk = 'wkhtmltopdf';
        $whichCmd = (stripos(PHP_OS, 'WIN') === 0) ? 'where' : 'command -v';
        $binary = null;
        $rawPath = @shell_exec($whichCmd . ' ' . $wk);
        $pathCheck = is_null($rawPath) ? '' : trim($rawPath);
        if ($pathCheck) $binary = trim(explode("\n", $pathCheck)[0]);
        if (!$binary) $binary = $wk;

        $cmd = escapeshellcmd($binary) . ' --page-size A4 --margin-top 0 --margin-bottom 0 --margin-left 0 --margin-right 0 --enable-local-file-access ' . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
        $output = @shell_exec($cmd);

        if (file_exists($tmpPdf) && filesize($tmpPdf) > 0) {
            $dlname = 'sijil_semua_' . $kodAll . '_' . date('Ymd_His') . '.pdf';
            $dlNameEnc = rawurlencode($dlname);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $dlname . '"; filename*=UTF-8\'\'' . $dlNameEnc);
            header('Content-Length: ' . filesize($tmpPdf));
            readfile($tmpPdf);
            @unlink($tmpHtml);
            @unlink($tmpPdf);
            exit;
        } else {
            @unlink($tmpHtml);
            @unlink($tmpPdf);
            // fallback to HTML output when PDF generation fails
        }
    }

    echo $multiHtml;
    exit;
}

// If a single print request is made (from the list), render one full-page A4 certificate
$printId = trim((string)($_GET['print_id'] ?? ''));
$download = trim((string)($_GET['download'] ?? $_GET['dl'] ?? ''));
if ($printId !== '') {
    // ensure numeric id to avoid injection (adjust if your IDs are UUIDs)
    $pid = is_numeric($printId) ? (int)$printId : 0;
    if ($pid <= 0) {
        http_response_code(400);
        echo "Invalid ID";
        exit;
    }
    try {
        $db = getDB();
        $sqlSingle = "SELECT TRIM(pa.nama) AS nama, pa.id AS id, COALESCE(s.nama_sukan,'') AS sukan, COALESCE(kt.nama_kategori,'') AS acara
            FROM table_pasukan_atlet pa
            JOIN table_pasukan p ON p.id = pa.pasukan_id
            LEFT JOIN table_sukan s ON s.id = p.sukan_id
            LEFT JOIN table_kategori kt ON kt.id = pa.kategori_id
            WHERE pa.id = :id AND pa.deleted_at IS NULL AND p.deleted_at IS NULL
            LIMIT 1";
        $st = $db->prepare($sqlSingle);
        $st->execute([':id' => $pid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $row = false;
    }

    if (!$row) {
        http_response_code(404);
        echo "Records not found.";
        exit;
    }

    $name = trim((string)($row['nama'] ?? ''));
    $sukan = trim((string)($row['sukan'] ?? ''));
    $acara = trim((string)($row['acara'] ?? ''));
    $sukan_combo = $sukan;
    if ($acara !== '') {
        $sukan_combo = $sukan_combo !== '' ? $sukan_combo . ' (' . $acara . ')' : $acara;
    }
    // uppercase sport/event for printed certificate
    $sukan_combo = mb_strtoupper(english_label($sukan_combo), 'UTF-8');

    // Prepare HTML for certificate. For PDF generation we need absolute URLs.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Use the athlete template explicitly to avoid mismatches when $img_url_versioned
    // may point elsewhere. Build a versioned absolute URL for the athlete background.
    $athRel = '/assets/img/sijil/sijil_atlet.jpeg';
    $athFull = realpath(__DIR__ . '/..') . $athRel;
    $athVer = @file_exists($athFull) ? @filemtime($athFull) : time();
    // include BASE_URL prefix so it resolves correctly in production subfolders
    $absTemplateUrl = $scheme . '://' . $host . url('assets/img/sijil/sijil_atlet.jpeg') . '?v=' . (int)$athVer;

    $certHtml = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Participation Certificates</title>' .
        '<style>@page{size:210mm 297mm;margin:0}@media print{html,body{width:210mm;margin:0!important;padding:0!important}.page{width:210mm!important;height:297mm!important;margin:0!important;padding:0!important;border:0!important;box-sizing:border-box;break-inside:avoid;page-break-inside:avoid}.bg-img{object-fit:cover!important;left:-0.5mm!important;top:-0.5mm!important;width:211mm!important;height:298mm!important;max-width:none!important}}html,body{height:100%;margin:0;padding:0}body{background:#fff;font-family:Arial,Helvetica,sans-serif}.page{position:relative;width:210mm;height:297mm;overflow:hidden}.bg-img{position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;z-index:0}.cert-name{position:absolute;left:50%;top:38%;transform:translate(-50%,-50%);width:78%;text-align:center;font-weight:700;color:#000;line-height:1.05;z-index:1;font-size:20px}.cert-sport{position:absolute;left:50%;top:48%;transform:translateX(-50%);width:78%;text-align:center;color:#000;font-weight:700;z-index:1;font-size:20px}.page,.bg-img{-webkit-print-color-adjust:exact;print-color-adjust:exact}</style></head><body>' .
        '<div class="page">' .
            '<img class="bg-img" src="' . htmlspecialchars($absTemplateUrl, ENT_QUOTES, 'UTF-8') . '" alt="background">' .
            '<div class="cert-name">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</div>' .
            '<div class="cert-sport">' . htmlspecialchars(english_sport_label(english_label($sukan_combo)), ENT_QUOTES, 'UTF-8') . '</div>' .
        '</div>' .
        '<script> (function(){ var bg=document.querySelector(".bg-img"); function p(){ try{ if(window.top===window.self){ window.print(); } }catch(e){} } if(bg){ if(bg.complete) setTimeout(p,120); else { bg.addEventListener("load", p); bg.addEventListener("error", p); } } else setTimeout(p,200); })(); </script></body></html>';

    // If user requested download=1, try to generate PDF server-side using wkhtmltopdf
    if ($download === '1' || strtolower($download) === 'true') {
        // create temp files
        $tmpDir = sys_get_temp_dir();
        $tmpHtml = tempnam($tmpDir, 'sijil_') . '.html';
        $tmpPdf = tempnam($tmpDir, 'sijil_') . '.pdf';
        file_put_contents($tmpHtml, $certHtml);

        // command
        $wk = 'wkhtmltopdf';
        // try to find wkhtmltopdf full path on Windows or Unix
        $whichCmd = (stripos(PHP_OS, 'WIN') === 0) ? 'where' : 'command -v';
        $binary = null;
        $rawPath = @shell_exec($whichCmd . ' ' . $wk);
        $pathCheck = is_null($rawPath) ? '' : trim($rawPath);
        if ($pathCheck) $binary = trim(explode("\n", $pathCheck)[0]);
        if (!$binary) $binary = $wk; // rely on PATH

        $cmd = escapeshellcmd($binary) . ' --page-size A4 --margin-top 0 --margin-bottom 0 --margin-left 0 --margin-right 0 --enable-local-file-access ' . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
        $output = null;
        $ret = null;
        $output = shell_exec($cmd);

        if (file_exists($tmpPdf) && filesize($tmpPdf) > 0) {
            // create a safe filename using recipient name
            $raw = trim((string)$name);
            $slug = '';
            if ($raw !== '') {
                $trans = @iconv('UTF-8', 'ASCII//TRANSLIT', $raw);
                $trans = $trans !== false ? $trans : $raw;
                $slug = preg_replace('/[^A-Za-z0-9 _-]/', '', $trans);
                $slug = preg_replace('/[\s\t\r\n]+/', '_', $slug);
                $slug = preg_replace('/_+/', '_', $slug);
                $slug = trim($slug, '_');
                if ($slug === '') $slug = 'sijil';
                $slug = substr($slug, 0, 60);
            } else {
                $slug = 'sijil';
            }
            $downloadName = 'sijil_' . $pid . '_' . $slug . '.pdf';
            $downloadNameUtf = rawurlencode($downloadName);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . $downloadNameUtf);
            header('Content-Length: ' . filesize($tmpPdf));
            readfile($tmpPdf);
            @unlink($tmpHtml);
            @unlink($tmpPdf);
            exit;
        } else {
            // cleanup and fall back to HTML page if PDF generation failed
            @unlink($tmpHtml);
            @unlink($tmpPdf);
            // optionally log $output for debugging
        }
    }
    // If PDF not requested or generation failed, output the HTML certificate
    echo $certHtml;
    exit;
}

// Helper: try to fetch managers/coaches JSON from ringkasan AJAX endpoint
function fetch_managers_from_ringkasan($kod) {
    // Instead of making an HTTP request (which may be blocked by RBAC/session),
    // query the database directly using the same SQL as pages/ringkasan.php ajax=managers.
    try {
        $db = getDB();
        $sql = "
            SELECT
                COALESCE(s.nama_sukan, '') AS acara,
                COALESCE(r.nama_pendek, r.nama_universiti, k.kod_universiti) AS kontinjen,
                TRIM(
                    COALESCE(
                        GROUP_CONCAT(
                            DISTINCT CONCAT(
                                COALESCE(NULLIF(TRIM(pp.nama), ''), ''),
                                ' @@JAWATAN@@ ', COALESCE(pp.jawatan, ''),
                                ' @@TEL@@ ', COALESCE(pp.no_telefon, ''),
                                ' @@EMEL@@ ', COALESCE(pp.emel, '')
                            )
                            SEPARATOR ' ||| '
                        ),
                        ''
                    )
                ) AS pengurus,
                TRIM(
                    COALESCE(
                        GROUP_CONCAT(
                            DISTINCT CONCAT(
                                COALESCE(NULLIF(TRIM(j.nama), ''), ''),
                                ' @@JAWATAN@@ ', COALESCE(j.jawatan, ''),
                                ' @@TEL@@ ', COALESCE(j.no_telefon, ''),
                                ' @@EMEL@@ ', COALESCE(j.emel, '')
                            )
                            SEPARATOR ' ||| '
                        ),
                        ''
                    )
                ) AS jurulatih
            FROM table_pasukan p
            LEFT JOIN table_kontinjen k ON k.id = p.kontinjen_id
            LEFT JOIN table_ref_universiti r ON r.kod_universiti = k.kod_universiti AND r.status = 1
            LEFT JOIN table_sukan s ON s.id = p.sukan_id
            LEFT JOIN table_pasukan_pengurus pp ON pp.pasukan_id = p.id AND pp.deleted_at IS NULL AND NULLIF(TRIM(pp.nama), '') IS NOT NULL
            LEFT JOIN table_pasukan_jurulatih j ON j.pasukan_id = p.id AND j.deleted_at IS NULL AND NULLIF(TRIM(j.nama), '') IS NOT NULL
            WHERE (:kod_empty = '' OR UPPER(COALESCE(k.kod_universiti, '')) = :kod_val)
              AND p.deleted_at IS NULL
            GROUP BY p.id, s.nama_sukan, k.kod_universiti, r.nama_pendek
            ORDER BY s.nama_sukan, p.id
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([':kod_empty' => $kod, ':kod_val' => $kod]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $rows;
    } catch (Exception $e) {
        return [];
    }
}

// Do not load data until a kontinjen is selected to avoid heavy initial queries
$managersRows = [];
$athletes = [];
if ($kod !== '') {
    // Fetch managers & coaches via ringkasan AJAX (reuse existing logic)
    try {
        $managersRows = fetch_managers_from_ringkasan($kod);
    } catch (Exception $e) {
        $managersRows = [];
    }

    // Do not fetch athletes here to keep page load fast.
    $athletes = [];
}

ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/certificates.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/certificates.css'); ?>">
<div class="container-fluid px-3 certificate-view certificate-user-view">
    <div class="row mb-3">
            <div class="col-12">
            <div class="certificate-eyebrow">SAM 2026 &middot; PARTICIPATION CERTIFICATES</div>
            <h2 class="mb-1"><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="text-muted mb-0">View and print certificates for contingent leaders, managers, coaches and athletes.</p>
        </div>
    </div>

    <div class="card mb-3 certificate-user-filter">
        <div class="card-body d-flex gap-3 align-items-end">
            <div>
                <label for="selectKont" class="certificate-filter-label">Select Contingent</label>
                <form method="get" id="frmKont">
                    <div class="d-flex align-items-center">
                        <?php if ($is_contingent_user): ?>
                            <select id="selectKont" name="_disabled_kod" class="form-select form-select-sm" disabled style="min-width:360px;max-width:60%">
                                <?php foreach ($unis as $u): ?>
                                    <?php if (strtoupper($u['kod_universiti']) === $kod): ?>
                                        <option value="<?php echo htmlspecialchars(strtoupper($u['kod_universiti']), ENT_QUOTES, 'UTF-8'); ?>" selected><?php echo htmlspecialchars($u['nama_universiti'], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="kod" value="<?php echo htmlspecialchars($kod, ENT_QUOTES, 'UTF-8'); ?>" />
                        <?php else: ?>
                            <select id="selectKont" name="kod" class="form-select form-select-sm" style="min-width:360px;max-width:60%">
                                <option value="">-- All Contingents --</option>
                                <?php foreach ($unis as $u): ?>
                                    <option value="<?php echo htmlspecialchars(strtoupper($u['kod_universiti']), ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($kod !== '' && strtoupper($u['kod_universiti']) === $kod) ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['nama_universiti'], ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <button type="button" id="btnLoadList" class="btn btn-sm btn-secondary ms-2" style="min-width:120px">Show Data</button>
                        <span id="loadStatus" class="ms-2 text-muted"></span>
                        <div id="tableLoader" class="ms-auto align-self-center" style="display:none;">
                            <div class="spinner-border text-primary" role="status" style="width:1.6rem;height:1.6rem;vertical-align:middle;">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

                    <?php if ($is_contingent_user && $kod !== ''): ?>
                    <script>
                        // Auto-load data for contingent users so they see their kontinjen immediately
                        document.addEventListener('DOMContentLoaded', function(){
                            try {
                                var btn = document.getElementById('btnLoadList');
                                if (btn) {
                                    // small timeout allow other scripts to bind
                                    setTimeout(function(){ btn.click(); }, 250);
                                }
                            } catch(e){}
                        });
                    </script>
                    <?php endif; ?>

    <div class="row">
            <div class="col-12">
                <div id="tabsWrap" style="display:none;">
                        <div class="certificate-list-heading"><h3>Certificate Recipients</h3><p>Select a category to view recipients and print their certificates.</p></div>
                        <ul class="nav nav-tabs" id="sijilTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="tab-penyelaras" data-bs-toggle="tab" data-bs-target="#pane-penyelaras" type="button" role="tab">Coordinator / Contingent Leader <span id="countPenyelaras" class="badge bg-secondary ms-1">0</span></button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-pengurus" data-bs-toggle="tab" data-bs-target="#pane-pengurus" type="button" role="tab">Sports Manager <span id="countPengurus" class="badge bg-secondary ms-1">0</span></button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-jurulatih" data-bs-toggle="tab" data-bs-target="#pane-jurulatih" type="button" role="tab">Sports Coach <span id="countJurulatih" class="badge bg-secondary ms-1">0</span></button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-atlet" data-bs-toggle="tab" data-bs-target="#pane-atlet" type="button" role="tab">Athlete <span id="countAtlet" class="badge bg-secondary ms-1">0</span></button>
                            </li>
                        </ul>
                        <div class="tab-content border border-top-0 p-3" id="sijilTabContent">
                            <div class="tab-pane fade show active" id="pane-penyelaras" role="tabpanel">
                                <div class="d-flex mb-2">
                                    <div class="me-auto"></div>
                                    <input type="search" id="searchPenyelaras" aria-label="Search recipients" class="form-control form-control-sm me-2" placeholder="Search..." style="max-width:220px;">
                                    <button type="button" id="printAllPenyelaras" class="btn btn-sm btn-primary">Print All</button>
                                </div>
                                <div class="table-responsive"><table class="table table-sm table-hover"><thead class="table-light"><tr><th style="width:5%" class="text-center">No</th><th style="width:50%">Name</th><th style="width:20%">Email</th><th style="width:15%" class="text-center">Phone Number</th><th style="width:10%" class="text-center">Actions</th></tr></thead><tbody id="penyelarasBody"></tbody></table></div>
                                <div class="d-flex justify-content-end align-items-center mt-2">
                                    <button type="button" id="penyelarasPrev" class="btn btn-sm btn-outline-secondary me-2">Prev</button>
                                    <span id="penyelarasPageInfo" class="me-2">Page 1/1</span>
                                    <button type="button" id="penyelarasNext" class="btn btn-sm btn-outline-secondary">Next</button>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="pane-pengurus" role="tabpanel">
                                <div class="d-flex mb-2">
                                    <div class="me-auto"></div>
                                    <input type="search" id="searchPengurus" aria-label="Search recipients" class="form-control form-control-sm me-2" placeholder="Search..." style="max-width:220px;">
                                    <button type="button" id="printAllPengurus" class="btn btn-sm btn-primary">Print All</button>
                                </div>
                                <div class="table-responsive"><table class="table table-sm table-hover"><thead class="table-light"><tr><th style="width:5%" class="text-center">No</th><th style="width:55%">Manager Name</th><th style="width:20%">Position</th><th style="width:10%">Phone Number</th><th style="width:10%" class="text-center">Actions</th></tr></thead><tbody id="pengurusBody"></tbody></table></div>
                                <div class="d-flex justify-content-end align-items-center mt-2">
                                    <button type="button" id="pengurusPrev" class="btn btn-sm btn-outline-secondary me-2">Prev</button>
                                    <span id="pengurusPageInfo" class="me-2">Page 1/1</span>
                                    <button type="button" id="pengurusNext" class="btn btn-sm btn-outline-secondary">Next</button>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="pane-jurulatih" role="tabpanel">
                                <div class="d-flex mb-2">
                                    <div class="me-auto"></div>
                                    <input type="search" id="searchJurulatih" aria-label="Search recipients" class="form-control form-control-sm me-2" placeholder="Search..." style="max-width:220px;">
                                    <button type="button" id="printAllJurulatih" class="btn btn-sm btn-primary">Print All</button>
                                </div>
                                <div class="table-responsive"><table class="table table-sm table-hover"><thead class="table-light"><tr><th style="width:5%" class="text-center">No</th><th style="width:55%">Coach Name</th><th style="width:20%">Position</th><th style="width:10%">Phone Number</th><th style="width:10%" class="text-center">Actions</th></tr></thead><tbody id="jurulatihBody"></tbody></table></div>
                                <div class="d-flex justify-content-end align-items-center mt-2">
                                    <button type="button" id="jurulatihPrev" class="btn btn-sm btn-outline-secondary me-2">Prev</button>
                                    <span id="jurulatihPageInfo" class="me-2">Page 1/1</span>
                                    <button type="button" id="jurulatihNext" class="btn btn-sm btn-outline-secondary">Next</button>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="pane-atlet" role="tabpanel">
                                <div class="d-flex mb-2">
                                    <div class="me-auto"></div>
                                    <input type="search" id="searchAtlet" aria-label="Search recipients" class="form-control form-control-sm me-2" placeholder="Search..." style="max-width:220px;">
                                    <button type="button" id="printAllAtlet" class="btn btn-sm btn-primary">Print All</button>
                                </div>
                                <div class="table-responsive"><table class="table table-sm table-hover align-middle"><thead class="table-light"><tr><th style="width:5%" class="text-center">No</th><th style="width:50%">Athlete Name</th><th style="width:25%">Sport / Event</th><th style="width:10%" class="text-center">Medals</th><th style="width:10%" class="text-center">Actions</th></tr></thead><tbody id="athleteBody"></tbody></table></div>
                                <div class="d-flex justify-content-end align-items-center mt-2">
                                    <button type="button" id="athletePrev" class="btn btn-sm btn-outline-secondary me-2">Prev</button>
                                    <span id="athletePageInfo" class="me-2">Page 1/1</span>
                                    <button type="button" id="athleteNext" class="btn btn-sm btn-outline-secondary">Next</button>
                                </div>
                            </div>
                        </div>
                    </div>
                <script>
                    (function(){
                                var btn = document.getElementById('btnLoadList');
                                var status = document.getElementById('loadStatus');
                                var wrap = document.getElementById('tabsWrap');
                                var athleteBody = document.getElementById('athleteBody');
                                var pengurusBody = document.getElementById('pengurusBody');
                                var jurulatihBody = document.getElementById('jurulatihBody');
                                var penyelarasBody = document.getElementById('penyelarasBody');
                                var loader = document.getElementById('tableLoader');
                                var printAllPengurus = document.getElementById('printAllPengurus');
                                var printAllJurulatih = document.getElementById('printAllJurulatih');
                                var printAllAtlet = document.getElementById('printAllAtlet');
                                var printAllPenyelaras = document.getElementById('printAllPenyelaras');
                                var searchPenyelaras = document.getElementById('searchPenyelaras');
                                var searchPengurus = document.getElementById('searchPengurus');
                                var searchJurulatih = document.getElementById('searchJurulatih');
                                var searchAtlet = document.getElementById('searchAtlet');
                                var countPengurus = document.getElementById('countPengurus');
                                var countJurulatih = document.getElementById('countJurulatih');
                                var countAtlet = document.getElementById('countAtlet');
                                var countPenyelaras = document.getElementById('countPenyelaras');
                                var lastRows = { athletes: [], pengurus: [], jurulatih: [], penyelaras: [] };

                        function showLoader(){ try{ if(loader) loader.style.display = 'inline-block'; }catch(e){} }
                        function hideLoader(){ try{ if(loader) loader.style.display = 'none'; }catch(e){} }
                        function printDirect(pid){
                            try{
                                showLoader();
                                var iframe = document.createElement('iframe');
                                iframe.style.display = 'none';
                                iframe.src = window.location.pathname + '?print_id=' + encodeURIComponent(pid);
                                iframe.onload = function(){
                                    try { iframe.contentWindow.focus(); iframe.contentWindow.print(); } catch(e) { console.error('printDirect error', e); alert('Unable to start printing.'); }
                                    setTimeout(function(){ try{ document.body.removeChild(iframe); }catch(e){}; hideLoader(); }, 1500);
                                };
                                document.body.appendChild(iframe);
                            }catch(e){ console.error(e); hideLoader(); alert('Unable to start printing.'); }
                        }

                                function buildCertHtml(name, sukanCombined, templateOverride){
        sukanCombined = englishSportLabel(englishLabel(sukanCombined));
                                var templateUrl = templateOverride || <?php echo json_encode($img_url_versioned); ?>;
                                var html = '<!doctype html><html><head><meta charset="utf-8"><title>Participation Certificates</title>' +
                                    '<style>@page{size:210mm 297mm;margin:0}@media print{html,body{width:210mm;margin:0!important;padding:0!important}.page{width:210mm!important;height:297mm!important;margin:0!important;padding:0!important;border:0!important;box-sizing:border-box;break-inside:avoid;page-break-inside:avoid}.bg-img{object-fit:cover!important;left:-0.5mm!important;top:-0.5mm!important;width:211mm!important;height:298mm!important;max-width:none!important}}html,body{height:100%;margin:0;padding:0}body{background:#fff;font-family:Arial,Helvetica,sans-serif}.page{position:relative;width:210mm;height:297mm;overflow:hidden}.bg-img{position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;z-index:0}.cert-name{position:absolute;left:50%;top:38%;transform:translate(-50%,-50%);width:78%;text-align:center;font-weight:700;color:#000;line-height:1.05;z-index:1;font-size:20px}.cert-sport{position:absolute;left:50%;top:48%;transform:translateX(-50%);width:78%;text-align:center;color:#000;font-weight:700;z-index:1;font-size:20px}.page,.bg-img{-webkit-print-color-adjust:exact;print-color-adjust:exact}</style></head><body>' +
                                    '<div class="page">' +
                                        '<img class="bg-img" src="'+templateUrl+'" alt="background">' +
                                        '<div class="cert-name">'+(name||'')+'</div>' +
                                        '<div class="cert-sport">' + ((sukanCombined||'').toString().toUpperCase()) + '</div>' +
                                    '</div>' +
                                    '<script> (function(){ if(window.top===window.self){ setTimeout(function(){ window.print(); },120); } })();<\/script></body></html>';
                                return html;
                            }

                            function buildMedalCertHtml(name, medalText, sukanText, medalType){
                                var medalTemplates = {
                                    gold: <?php echo json_encode(url('assets/img/sijil/sijil_emas.jpeg')); ?>,
                                    silver: <?php echo json_encode(url('assets/img/sijil/sijil_perak.jpeg')); ?>,
                                    bronze: <?php echo json_encode(url('assets/img/sijil/sijil_gangsa.jpeg')); ?>
                                };
                                var templateUrl = medalTemplates[medalType] || medalTemplates.gold;
                                var html = '<!doctype html><html><head><meta charset="utf-8"><title>Achievement Certificate</title>' +
                                    '<style>@page{size:210mm 297mm;margin:0}@media print{html,body{width:210mm;margin:0!important;padding:0!important}.page{width:210mm!important;height:297mm!important;margin:0!important;padding:0!important;border:0!important;box-sizing:border-box;break-inside:avoid;page-break-inside:avoid}.bg-img{object-fit:cover!important;left:-0.5mm!important;top:-0.5mm!important;width:211mm!important;height:298mm!important;max-width:none!important}}html,body{height:100%;margin:0;padding:0}body{background:#fff;font-family:Arial,Helvetica,sans-serif}.page{position:relative;width:210mm;height:297mm;overflow:hidden}.bg-img{position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;z-index:0}.medal-name{position:absolute;left:51.8%;top:38%;transform:translate(-50%,-50%);width:78%;text-align:center;font-weight:700;color:#000;line-height:1.15;z-index:1;font-size:20px}.medal-type{position:absolute;left:51.8%;top:45.5%;transform:translate(-50%,-50%);width:78%;text-align:center;font-weight:700;color:#000;line-height:1.15;z-index:1;font-size:20px}.medal-sport{position:absolute;left:51.8%;top:52.5%;transform:translate(-50%,-50%);width:78%;text-align:center;font-weight:700;color:#000;line-height:1.15;z-index:1;font-size:20px}.page,.bg-img{-webkit-print-color-adjust:exact;print-color-adjust:exact}</style></head><body>' +
                                    '<div class="page">' +
                                        '<img class="bg-img" src="'+templateUrl+'" alt="background">' +
                                        '<div class="medal-name">'+escHtml((name||'').toString().trim())+'</div>' +
                                        '<div class="medal-type">'+escHtml((medalText||'').toString().trim().toUpperCase())+'</div>' +
                                        '<div class="medal-sport">'+escHtml((sukanText||'').toString().trim().toUpperCase())+'</div>' +
                                    '</div>' +
                                    '<script> (function(){ if(window.top===window.self){ setTimeout(function(){ window.print(); },120); } })();<\/script></body></html>';
                                return html;
                            }

                            function printDirectHtml(html){
                                try{
                                    showLoader();
                                    var iframe = document.createElement('iframe');
                                    iframe.style.display = 'none';
                                    document.body.appendChild(iframe);
                                    var doc = iframe.contentWindow.document;
                                    doc.open(); doc.write(html); doc.close();
                                    iframe.onload = function(){ try{ iframe.contentWindow.focus(); iframe.contentWindow.print(); }catch(e){ console.error(e); } setTimeout(function(){ try{ document.body.removeChild(iframe); }catch(e){}; hideLoader(); },1500); };
                                }catch(e){ console.error(e); hideLoader(); }
                            }

                        // Pagination and rendering helpers
                        var PAGE_SIZE = 10;
                        var currentPenyelarasPage = 1, currentPengurusPage = 1, currentJurulatihPage = 1, currentAthletePage = 1;

                        // Render cell HTML with empty-value badge
                        function escHtml(s){ return (s===null||s===undefined)?'':String(s).replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
                        function cellHtml(val, center){
                            var v = (val===null||val===undefined)?'' : String(val).trim();
                            var cls = center ? 'text-center' : 'text-truncate';
                            if (v === '') return '<td class="'+cls+'"><span class="no-data-badge">None</span></td>'; 
                            return '<td class="'+cls+'" title="'+escHtml(v)+'">'+escHtml(v)+'</td>';
                        }
                        function appendNoDataRow(tbodyEl, colCount){
                            if (!tbodyEl) return;
                            var tr = document.createElement('tr');
                            var html = '';
                            for (var i = 0; i < colCount; i++) {
                                html += '<td class="text-center"><span class="no-data-badge">None</span></td>';
                            }
                            tr.innerHTML = html;
                            tbodyEl.appendChild(tr);
                        }

                        function getSelectedKod(){ var sel = document.getElementById('selectKont'); return sel && sel.value ? sel.value : ''; }
                        function matchSearch(texts, keyword){
                            var q = (keyword || '').toString().trim().toLowerCase();
                            if (!q) return true;
                            for (var i = 0; i < texts.length; i++) {
                                var t = (texts[i] || '').toString().toLowerCase();
                                if (t.indexOf(q) !== -1) return true;
                            }
                            return false;
                        }

                        function renderPengurusPage(){
                            var rawList = lastRows.pengurus || [];
                            var q = searchPengurus ? searchPengurus.value : '';
                            var list = rawList.filter(function(p){
                                return matchSearch([p.nama, p.jawatan, p.tel], q);
                            });
                            var total = list.length;
                            var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
                            if (currentPengurusPage > pages) currentPengurusPage = pages;
                            var start = (currentPengurusPage - 1) * PAGE_SIZE;
                            var slice = list.slice(start, start + PAGE_SIZE);
                            pengurusBody.innerHTML = '';
                            if (!slice.length) {
                                appendNoDataRow(pengurusBody, 5);
                            }
                            slice.forEach(function(p, idx){
                                var nIdx = start + idx + 1;
                                var tr = document.createElement('tr');
                                var telSafe = (p.tel || '').replace(/</g,'&lt;');
                                tr.innerHTML = '<td class="text-center">'+nIdx+'</td>' +
                                    (function(){ return cellHtml(p.nama, false); })() +
                                    (function(){ return cellHtml(englishLabel(p.jawatan || ''), false); })() +
                                    (function(){ return cellHtml(p.tel || '', false); })() +
                                    '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-primary do-print-pengurus">Print</button></td>';
                                pengurusBody.appendChild(tr);
                                tr.querySelector('.do-print-pengurus').addEventListener('click', function(){
                                    var roleText = (p.jawatan || '').toString().trim();
                                    if (!roleText) roleText = 'PENGURUS';
                                    roleText = englishLabel(roleText).toUpperCase();
                                    printDirectHtml(buildCertHtml(p.nama, roleText, <?php echo json_encode(url('assets/img/sijil/sijil_pengurus.jpeg')); ?>));
                                });
                            });
                            try{ document.getElementById('pengurusPageInfo').textContent = 'Page ' + currentPengurusPage + '/' + pages; }catch(e){}
                            try{ document.getElementById('pengurusPrev').disabled = currentPengurusPage <= 1; }catch(e){}
                            try{ document.getElementById('pengurusNext').disabled = currentPengurusPage >= pages; }catch(e){}
                            if (countPengurus) countPengurus.textContent = total;
                            if (printAllPengurus) printAllPengurus.disabled = total === 0;
                        }

                        function renderJurulatihPage(){
                            var rawList = lastRows.jurulatih || [];
                            var q = searchJurulatih ? searchJurulatih.value : '';
                            var list = rawList.filter(function(p){
                                return matchSearch([p.nama, p.jawatan, p.tel], q);
                            });
                            var total = list.length;
                            var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
                            if (currentJurulatihPage > pages) currentJurulatihPage = pages;
                            var start = (currentJurulatihPage - 1) * PAGE_SIZE;
                            var slice = list.slice(start, start + PAGE_SIZE);
                            jurulatihBody.innerHTML = '';
                            if (!slice.length) {
                                appendNoDataRow(jurulatihBody, 5);
                            }
                            slice.forEach(function(p, idx){
                                var nIdx = start + idx + 1;
                                var tr = document.createElement('tr');
                                var telSafe = (p.tel || '').replace(/</g,'&lt;');
                                tr.innerHTML = '<td class="text-center">'+nIdx+'</td>' +
                                    (function(){ return cellHtml(p.nama, false); })() +
                                    (function(){ return cellHtml(englishLabel(p.jawatan || ''), false); })() +
                                    (function(){ return cellHtml(p.tel || '', false); })() +
                                    '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-primary do-print-jurulatih">Print</button></td>';
                                jurulatihBody.appendChild(tr);
                                tr.querySelector('.do-print-jurulatih').addEventListener('click', function(){
                                    var roleText = (p.jawatan || '').toString().trim();
                                    if (!roleText) roleText = 'JURULATIH';
                                    roleText = englishLabel(roleText).toUpperCase();
                                    printDirectHtml(buildCertHtml(p.nama, roleText, <?php echo json_encode(url('assets/img/sijil/sijil_jurulatih.jpeg')); ?>));
                                });
                            });
                            try{ document.getElementById('jurulatihPageInfo').textContent = 'Page ' + currentJurulatihPage + '/' + pages; }catch(e){}
                            try{ document.getElementById('jurulatihPrev').disabled = currentJurulatihPage <= 1; }catch(e){}
                            try{ document.getElementById('jurulatihNext').disabled = currentJurulatihPage >= pages; }catch(e){}
                            if (countJurulatih) countJurulatih.textContent = total;
                            if (printAllJurulatih) printAllJurulatih.disabled = total === 0;
                        }

                        function renderAthletePage(){
                            var rawList = lastRows.athletes || [];
                            var q = searchAtlet ? searchAtlet.value : '';
                            var list = rawList.filter(function(r){
                                return matchSearch([r.nama, r.sukan, r.acara], q);
                            });
                            var total = list.length;
                            var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
                            if (currentAthletePage > pages) currentAthletePage = pages;
                            var start = (currentAthletePage - 1) * PAGE_SIZE;
                            var slice = list.slice(start, start + PAGE_SIZE);
                            athleteBody.innerHTML = '';
                            if (!slice.length) {
                                appendNoDataRow(athleteBody, 5);
                            }
                            slice.forEach(function(r, idx){
                                var pid = r.id || '';
                                var name = (r.nama || '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                var sukan = (r.sukan || '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                var acara = (r.acara || '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                var medalType = String(r.medal_type || '').toLowerCase();
                                var medalLabel = '';
                                if (medalType === 'gold') medalLabel = 'Gold';
                                else if (medalType === 'silver') medalLabel = 'Silver';
                                else if (medalType === 'bronze') medalLabel = 'Bronze';
                                var rawSukan = (r.sukan || '').toString().trim();
                                var rawAcara = (r.acara || '').toString().trim();
                                var info = sukan;
                                if (acara !== '') info = info !== '' ? (info + ' (' + acara + ')') : acara;
                                var nIdx = start + idx + 1;
                                var tr = document.createElement('tr');
                                if (medalType === 'gold' || medalType === 'silver' || medalType === 'bronze') {
                                    tr.classList.add('medal-' + medalType);
                                }
                                var medalPrintHtml = '';
                                if (medalLabel) {
                                    medalPrintHtml = ' <button type="button" class="btn btn-sm btn-outline-primary icon-action-btn do-print-medal" title="Print Certificate Medals" aria-label="Print Certificate Medals"><span class="icon-glyph">🖨️</span></button>';
                                }
                                tr.innerHTML = '<td class="text-center">'+nIdx+'</td>'+
                                    (function(){ return cellHtml(name, false); })() +
                                    (function(){ return cellHtml(info, false); })() +
                                    '<td class="text-center">'+(medalLabel ? ('<span>'+medalLabel+'</span>' + medalPrintHtml) : '<span class="no-data-badge">None</span>')+'</td>' +
                                    '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-primary do-print">Print</button></td>';
                                athleteBody.appendChild(tr);
                                var btnEl = tr.querySelector('.do-print');
                                if (btnEl) {
                                    btnEl.addEventListener('click', function(e){ e.preventDefault(); printDirect(pid); });
                                }
                                var medalBtnEl = tr.querySelector('.do-print-medal');
                                if (medalBtnEl) {
                                    medalBtnEl.addEventListener('click', function(e){
                                        e.preventDefault();
                                        var medalText = 'PINGAT ' + medalLabel;
                                        var sukanKategori = rawSukan;
                                        if (rawAcara !== '') sukanKategori = sukanKategori ? (sukanKategori + ' (' + rawAcara + ')') : rawAcara;
                                        printDirectHtml(buildMedalCertHtml(r.nama || '', medalText, sukanKategori, medalType));
                                    });
                                }
                            });
                            try{ document.getElementById('athletePageInfo').textContent = 'Page ' + currentAthletePage + '/' + pages; }catch(e){}
                            try{ document.getElementById('athletePrev').disabled = currentAthletePage <= 1; }catch(e){}
                            try{ document.getElementById('athleteNext').disabled = currentAthletePage >= pages; }catch(e){}
                            if (countAtlet) countAtlet.textContent = total;
                            if (printAllAtlet) printAllAtlet.disabled = total === 0;
                        }

                        function renderPenyelarasPage(){
                            var rawList = lastRows.penyelaras || [];
                            var q = searchPenyelaras ? searchPenyelaras.value : '';
                            var list = rawList.filter(function(r){
                                return matchSearch([r.nama, r.emel, r.telefon], q);
                            });
                            var total = list.length;
                            var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
                            if (currentPenyelarasPage > pages) currentPenyelarasPage = pages;
                            var start = (currentPenyelarasPage - 1) * PAGE_SIZE;
                            var slice = list.slice(start, start + PAGE_SIZE);
                            penyelarasBody.innerHTML = '';
                            if (!slice.length) {
                                appendNoDataRow(penyelarasBody, 5);
                            }
                            slice.forEach(function(r, idx){
                                var nIdx = start + idx + 1;
                                var tr = document.createElement('tr');
                                var nameSafe = (r.nama || '').replace(/</g,'&lt;');
                                var emailSafe = (r.emel || '').replace(/</g,'&lt;');
                                var telSafe = (r.telefon || '').replace(/</g,'&lt;');
                                tr.innerHTML = '<td class="text-center">'+nIdx+'</td>'+
                                    (function(){ return cellHtml(nameSafe, false); })() +
                                    (function(){ return cellHtml(emailSafe, false); })() +
                                    (function(){ return cellHtml(telSafe, true); })() +
                                    '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-primary do-print-penyelaras">Print</button></td>';
                                penyelarasBody.appendChild(tr);
                                var btn = tr.querySelector('.do-print-penyelaras');
                                if (btn) btn.addEventListener('click', function(){ printDirectHtml(buildCertHtml(r.nama || '', englishLabel('KETUA KONTINJEN'), <?php echo json_encode(url('assets/img/sijil/sijil_penyelaras.jpeg')); ?>)); });
                            });
                            try{ document.getElementById('penyelarasPageInfo').textContent = 'Page ' + currentPenyelarasPage + '/' + pages; }catch(e){}
                            try{ document.getElementById('penyelarasPrev').disabled = currentPenyelarasPage <= 1; }catch(e){}
                            try{ document.getElementById('penyelarasNext').disabled = currentPenyelarasPage >= pages; }catch(e){}
                            if (countPenyelaras) countPenyelaras.textContent = total;
                            if (printAllPenyelaras) printAllPenyelaras.disabled = total === 0;
                        }

                        // wire prev/next buttons
                        try{
                            document.getElementById('penyelarasPrev').addEventListener('click', function(){ if (currentPenyelarasPage>1){ currentPenyelarasPage--; renderPenyelarasPage(); } });
                            document.getElementById('penyelarasNext').addEventListener('click', function(){ currentPenyelarasPage++; renderPenyelarasPage(); });
                            document.getElementById('pengurusPrev').addEventListener('click', function(){ if (currentPengurusPage>1){ currentPengurusPage--; renderPengurusPage(); } });
                            document.getElementById('pengurusNext').addEventListener('click', function(){ currentPengurusPage++; renderPengurusPage(); });
                            document.getElementById('jurulatihPrev').addEventListener('click', function(){ if (currentJurulatihPage>1){ currentJurulatihPage--; renderJurulatihPage(); } });
                            document.getElementById('jurulatihNext').addEventListener('click', function(){ currentJurulatihPage++; renderJurulatihPage(); });
                            document.getElementById('athletePrev').addEventListener('click', function(){ if (currentAthletePage>1){ currentAthletePage--; renderAthletePage(); } });
                            document.getElementById('athleteNext').addEventListener('click', function(){ currentAthletePage++; renderAthletePage(); });
                        }catch(e){}

                        try{
                            if (searchPenyelaras) searchPenyelaras.addEventListener('input', function(){ currentPenyelarasPage = 1; renderPenyelarasPage(); });
                            if (searchPengurus) searchPengurus.addEventListener('input', function(){ currentPengurusPage = 1; renderPengurusPage(); });
                            if (searchJurulatih) searchJurulatih.addEventListener('input', function(){ currentJurulatihPage = 1; renderJurulatihPage(); });
                            if (searchAtlet) searchAtlet.addEventListener('input', function(){ currentAthletePage = 1; renderAthletePage(); });
                        }catch(e){}

                        // wire print all buttons to client-side print (build multi-page HTML and print via hidden iframe)
                        try{
                            var tmplPengurus = <?php echo json_encode(url('assets/img/sijil/sijil_pengurus.jpeg')); ?>;
                            var tmplJurulatih = <?php echo json_encode(url('assets/img/sijil/sijil_jurulatih.jpeg')); ?>;
                            var tmplPenyelaras = <?php echo json_encode(url('assets/img/sijil/sijil_penyelaras.jpeg')); ?>;
                            var tmplAtlet = <?php
                                $athPath = __DIR__ . '/../assets/img/sijil/sijil_atlet.jpeg';
                                $ver = @file_exists($athPath) ? @filemtime($athPath) : time();
                                echo json_encode(url('assets/img/sijil/sijil_atlet.jpeg') . '?v=' . (int)$ver);
                            ?>;

                            function buildMultiHtml(rows, type){
                                var img = type === 'pengurus' ? tmplPengurus : (type === 'jurulatih' ? tmplJurulatih : (type === 'penyelaras' ? tmplPenyelaras : tmplAtlet));
                                var html = '<!doctype html><html><head><meta charset="utf-8"><title>Print All Certificates</title>' +
                                    '<style>@page{size:210mm 297mm;margin:0}@media print{html,body{width:210mm;margin:0!important;padding:0!important}.page{width:210mm!important;height:297mm!important;margin:0!important;padding:0!important;border:0!important;box-sizing:border-box;break-inside:avoid;page-break-inside:avoid}.bg-img{object-fit:cover!important;left:-0.5mm!important;top:-0.5mm!important;width:211mm!important;height:298mm!important;max-width:none!important}}html,body{height:100%;margin:0;padding:0}body{background:#fff;font-family:Arial,Helvetica,sans-serif}.page{position:relative;width:210mm;height:297mm;overflow:hidden}.bg-img{position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;z-index:0}.cert-name{position:absolute;left:50%;top:38%;transform:translate(-50%,-50%);width:78%;text-align:center;font-weight:700;color:#000;line-height:1.05;z-index:1;font-size:20px}.cert-sport{position:absolute;left:50%;top:48%;transform:translateX(-50%);width:78%;text-align:center;color:#000;font-weight:700;z-index:1;font-size:20px}.page,.bg-img{-webkit-print-color-adjust:exact;print-color-adjust:exact}</style></head><body>';
                                rows.forEach(function(r){
                                    var raw = (r.nama || '').toString();
                                    // strip phone/email
                                    var clean = raw.replace(/\s*\([^)]*\)/g,'').replace(/\s+\S+@\S+$/,'').trim();
                                    var name = clean || raw;
                                    var sukan_combo = '';
                                    if (type === 'athletes'){
                                        var s = (r.sukan||'').toString();
                                        var a = (r.acara||'').toString();
                                        sukan_combo = s;
                                        if (a !== '') sukan_combo = sukan_combo !== '' ? (sukan_combo + ' (' + a + ')') : a;
                                        // uppercase for athletes
                                        sukan_combo = (sukan_combo||'').toString().toUpperCase();
                                    } else {
                                        // role label for other types
                                        if (type === 'pengurus') sukan_combo = ((r.jawatan || '').toString().trim() || 'PENGURUS');
                                        else if (type === 'jurulatih') sukan_combo = ((r.jawatan || '').toString().trim() || 'JURULATIH');
                                        else if (type === 'penyelaras') sukan_combo = 'KETUA KONTINJEN';
                                        else sukan_combo = '';
                                    }
                                    html += '<div class="page">' +
                                        '<img class="bg-img" src="'+img+'" alt="bg">' +
                                        '<div class="cert-name">'+(name.replace(/</g,'&lt;'))+'</div>' +
                                        '<div class="cert-sport">'+((sukan_combo||'').toString().toUpperCase().replace(/</g,'&lt;'))+'</div>' +
                                    '</div>';
                                });
                                html += '<script> (function(){ if(window.top===window.self){ setTimeout(function(){ window.print(); },120); } })();<\/script></body></html>';
                                return html;
                            }

                            if (printAllPengurus) printAllPengurus.addEventListener('click', function(){
                                if (!lastRows.pengurus || lastRows.pengurus.length===0){ alert('No managers to print.'); return; }
                                var html = buildMultiHtml(lastRows.pengurus, 'pengurus');
                                printDirectHtml(html);
                            });

                            if (printAllJurulatih) printAllJurulatih.addEventListener('click', function(){
                                if (!lastRows.jurulatih || lastRows.jurulatih.length===0){ alert('No coaches to print.'); return; }
                                var html = buildMultiHtml(lastRows.jurulatih, 'jurulatih');
                                printDirectHtml(html);
                            });

                            if (printAllAtlet) printAllAtlet.addEventListener('click', function(){
                                if (!lastRows.athletes || lastRows.athletes.length===0){ alert('No athletes to print.'); return; }
                                var html = buildMultiHtml(lastRows.athletes, 'athletes');
                                printDirectHtml(html);
                            });
                            if (printAllPenyelaras) printAllPenyelaras.addEventListener('click', function(){
                                if (!lastRows.penyelaras || lastRows.penyelaras.length===0){ alert('No coordinators to print.'); return; }
                                var html = buildMultiHtml(lastRows.penyelaras, 'penyelaras');
                                printDirectHtml(html);
                            });
                        }catch(e){}
                        btn.addEventListener('click', function(){
                            var kod = (document.getElementById('selectKont') && document.getElementById('selectKont').value) ? document.getElementById('selectKont').value : '';
                            if (!kod) { status.textContent = 'Please select a contingent first.'; return; }
                            var url = window.location.pathname + '?ajax=athletes&kod=' + encodeURIComponent(kod);
                            status.textContent = '';
                            btn.disabled = true;
                            showLoader();
                            console.log('Fetching athletes from', url);
                            // fetch athletes and managers concurrently
                            var urlManagers = window.location.pathname + '?ajax=managers&kod=' + encodeURIComponent(kod);
                            fetch(url, { credentials: 'same-origin' })
                                .then(function(r){
                                    if (!r.ok) throw new Error('HTTP ' + r.status);
                                    return r.text();
                                })
                                .then(function(text){
                                    try {
                                        var j = JSON.parse(text);
                                        console.log('Athletes JSON response', j);
                                        var athletesJson = j && j.ok ? (j.rows || []) : [];
                                        // fetch managers and penyelaras concurrently
                                        var urlPenyelaras = window.location.pathname + '?ajax=penyelaras&kod=' + encodeURIComponent(kod);
                                        return Promise.all([
                                            fetch(urlManagers, { credentials: 'same-origin' }).then(function(r2){ if(!r2.ok) throw new Error('HTTP ' + r2.status); return r2.text(); }),
                                            fetch(urlPenyelaras, { credentials: 'same-origin' }).then(function(r3){ if(!r3.ok) throw new Error('HTTP ' + r3.status); return r3.text(); })
                                        ]).then(function(texts){
                                            try {
                                                var mj = JSON.parse(texts[0]);
                                                var mgrRows = mj && mj.ok ? (mj.rows || []) : [];
                                            } catch(err2) {
                                                console.error('Managers endpoint returned non-JSON:', texts[0]);
                                                throw new Error('Managers endpoint returned non-JSON');
                                            }
                                            try {
                                                var pj = JSON.parse(texts[1]);
                                                var penyRows = pj && pj.ok ? (pj.rows || []) : [];
                                            } catch(err3) {
                                                console.error('Coordinator endpoint returned non-JSON:', texts[1]);
                                                throw new Error('Coordinator endpoint returned non-JSON');
                                            }
                                            return { athletes: athletesJson, managers: mgrRows, penyelaras: penyRows };
                                        });
                                    } catch(err) {
                                        console.error('Athletes endpoint returned non-JSON:', text);
                                        throw new Error('Athletes endpoint returned non-JSON');
                                    }
                                }).then(function(all){
                                    // process managers into pengurus and jurulatih
                                    // Build deduplicated pengurus/jurulatih lists (dedupe by name only)
                                    var pengurusMap = Object.create(null);
                                    var jurulatihMap = Object.create(null);
                                    (all.managers || []).forEach(function(r){
                                        // each r may contain pengurus and jurulatih strings like: "Name (0123456789) email@example.com"
                                        if (r.pengurus) {
                                            r.pengurus.split(' ||| ').forEach(function(praw){
                                                var p = (praw||'').trim(); if(!p) return;
                                                var nama = '', jawatan = '', tel = '';
                                                if (p.indexOf('@@JAWATAN@@') !== -1) {
                                                    var sNama = p.split('@@JAWATAN@@');
                                                    nama = (sNama[0] || '').trim();
                                                    var rest1 = (sNama[1] || '');
                                                    var sTel = rest1.split('@@TEL@@');
                                                    jawatan = (sTel[0] || '').trim();
                                                    var rest2 = (sTel[1] || '');
                                                    var sEmel = rest2.split('@@EMEL@@');
                                                    tel = (sEmel[0] || '').trim();
                                                } else {
                                                    var m = p.match(/\(([^)]*)\)/);
                                                    tel = m ? m[1].trim() : '';
                                                    nama = p.replace(/\s*\([^)]*\)/g,'').replace(/\s+\S+@\S+$/,'').trim();
                                                }
                                                if (!nama) return;
                                                if (p.replace(/\s+/g,'').toUpperCase() === '@@JAWATAN@@@@TEL@@@@EMEL@@') return;
                                                var key = (nama || p).toLowerCase();
                                                if (!pengurusMap[key]) pengurusMap[key] = { nama: nama || p, jawatan: jawatan || '', tel: tel };
                                                else {
                                                    if (!pengurusMap[key].tel && tel) pengurusMap[key].tel = tel;
                                                    if (!pengurusMap[key].jawatan && jawatan) pengurusMap[key].jawatan = jawatan;
                                                }
                                            });
                                        }
                                        if (r.jurulatih) {
                                            r.jurulatih.split(' ||| ').forEach(function(jraw){
                                                var j = (jraw||'').trim(); if(!j) return;
                                                var namaj = '', jawatanj = '', jtTel = '';
                                                if (j.indexOf('@@JAWATAN@@') !== -1) {
                                                    var sjNama = j.split('@@JAWATAN@@');
                                                    namaj = (sjNama[0] || '').trim();
                                                    var jRest1 = (sjNama[1] || '');
                                                    var sjTel = jRest1.split('@@TEL@@');
                                                    jawatanj = (sjTel[0] || '').trim();
                                                    var jRest2 = (sjTel[1] || '');
                                                    var sjEmel = jRest2.split('@@EMEL@@');
                                                    jtTel = (sjEmel[0] || '').trim();
                                                } else {
                                                    var mj = j.match(/\(([^)]*)\)/);
                                                    jtTel = mj ? mj[1].trim() : '';
                                                    namaj = j.replace(/\s*\([^)]*\)/g,'').replace(/\s+\S+@\S+$/,'').trim();
                                                }
                                                if (!namaj) return;
                                                if (j.replace(/\s+/g,'').toUpperCase() === '@@JAWATAN@@@@TEL@@@@EMEL@@') return;
                                                var keyj = (namaj || j).toLowerCase();
                                                if (!jurulatihMap[keyj]) jurulatihMap[keyj] = { nama: namaj || j, jawatan: jawatanj || '', tel: jtTel };
                                                else {
                                                    if (!jurulatihMap[keyj].tel && jtTel) jurulatihMap[keyj].tel = jtTel;
                                                    if (!jurulatihMap[keyj].jawatan && jawatanj) jurulatihMap[keyj].jawatan = jawatanj;
                                                }
                                            });
                                        }
                                    });
                                    var pengurusList = Object.keys(pengurusMap).map(function(k){ return pengurusMap[k]; });
                                    var jurulatihList = Object.keys(jurulatihMap).map(function(k){ return jurulatihMap[k]; });

                                    // store lists and render paged views
                                    lastRows.pengurus = pengurusList;
                                    lastRows.jurulatih = jurulatihList;
                                    lastRows.athletes = (all.athletes || []);
                                    lastRows.penyelaras = (all.penyelaras || []);
                                    currentPenyelarasPage = 1; currentPengurusPage = 1; currentJurulatihPage = 1; currentAthletePage = 1;
                                    renderPenyelarasPage();
                                    renderPengurusPage();
                                    renderJurulatihPage();
                                    renderAthletePage();
                                    wrap.style.display = '';
                                    hideLoader();
                                    btn.disabled = false;
                                }).catch(function(e){ console.error('Fetch error', e); status.textContent = 'Unable to fetch data.'; btn.disabled = false; hideLoader(); alert('Unable to fetch athlete/manager data: '+ (e && e.message ? e.message : 'Unknown')); });
                        });

                        
                    })();
                </script>
        </div>
    </div>

</div>

<style>
.no-data-badge{
    display:inline-block;
    padding:0.18rem 0.5rem;
    border-radius:0.35rem;
    background:#fdecea;
    color:#842029;
    border:1px solid #f5c2c7;
    font-size:0.8rem;
    font-weight:600;
    line-height:1.2;
}
#athleteBody tr.medal-gold > td { background-color: #fff4cf !important; }
#athleteBody tr.medal-silver > td { background-color: #edf1f5 !important; }
#athleteBody tr.medal-bronze > td { background-color: #f6e3d3 !important; }
.icon-action-btn{
    width:32px;
    height:32px;
    padding:0;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}
.icon-glyph{
    line-height:1;
    font-size:14px;
}
</style>

<style>
@media print {
    body { background: #fff; color: #000; }
    .container-fluid { padding: 0; }
    .card { box-shadow: none; border: none; }
    .btn { display: none !important; }
    .row-cols-1 .col { page-break-inside: avoid; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function(){
    var templateUrl = <?php echo json_encode($img_url_versioned); ?>;
    function buildCertHtml(name, sukanCombined, templateOverride){
        sukanCombined = englishSportLabel(englishLabel(sukanCombined));
        var tUrl = templateOverride || templateUrl;
            var html = '<!doctype html><html><head><meta charset="utf-8"><title>Participation Certificates</title>' +
            '<style>html,body{height:100%;margin:0;padding:0}body{font-family:Arial,Helvetica,sans-serif}.page{position:relative;width:210mm;height:297mm;overflow:hidden}.bg-img{position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;z-index:0}.cert-name{position:absolute;left:50%;top:38%;transform:translate(-50%,-50%);font-size:20px;font-weight:700;color:#000;text-align:center;padding:0 30px;line-height:1.05;z-index:1}.cert-sport{position:absolute;left:50%;top:48%;transform:translateX(-50%);font-size:20px;font-weight:700;color:#000;text-align:center;padding:0 30px;z-index:1}@media print{.bg-img{display:block}}</style></head><body>'+
            '<div class="page">'+
                '<img class="bg-img" src="'+tUrl+'" alt="bg">'+
                '<div class="cert-name">'+(name||'')+'</div>'+
                '<div class="cert-sport">' + ((sukanCombined||'').toString().toUpperCase()) + '</div>'+
            '</div></body></html>';
        return html;
    }

    window.printCertificate = function(name, sukanCombined){
        try{
            var w = window.open('about:blank','_blank');
            if(!w) { alert('Please allow pop-ups to print certificates.'); return; }
            var doc = w.document;
            doc.open();
            doc.write(buildCertHtml(name, sukanCombined));
            doc.close();
            setTimeout(function(){ try { w.focus(); w.print(); } catch(e) { console.error(e); } }, 700);
        }catch(e){ console.error(e); alert('Unable to open the print window.'); }
    };

    document.querySelectorAll('.btn-print-cert').forEach(function(btn){
        btn.addEventListener('click', function(e){
            var name = this.getAttribute('data-name') || '';
            var sukanCombined = this.getAttribute('data-sukan') || '';
            printCertificate(name, sukanCombined);
        });
    });
});
</script>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
