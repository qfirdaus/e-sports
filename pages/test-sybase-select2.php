<?php
/**
 * Test page: Sybase staff Select2 lookup
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

Session::start();
$auth = getAuth();
$auth->requireAuth();

$ajax = trim((string)($_GET['ajax'] ?? ''));
if ($ajax === 'staff_lookup') {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'results' => [], 'error' => null, 'elapsed_ms' => null];
    $q = strtoupper(trim((string)($_GET['q'] ?? $_GET['term'] ?? '')));
    $limit = (int)($_GET['limit'] ?? 100);
    if ($limit <= 0 || $limit > 500) $limit = 100;

    $t0 = microtime(true);
    try {
        $sybasePdo = getSybasePdoConnection('default');

        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql = "SELECT TOP {$limit}
                        CONVERT(VARCHAR(50), ISNULL(nopekerja, '')) AS nopekerja,
                        CONVERT(VARCHAR(200), ISNULL(gelar_nama, '')) AS gelar_nama,
                        CONVERT(VARCHAR(200), ISNULL(email, '')) AS email,
                        CONVERT(VARCHAR(50), ISNULL(handphone, '')) AS handphone,
                        CONVERT(VARCHAR(50), ISNULL(telefon_surat, '')) AS telefon_surat,
                        CONVERT(VARCHAR(50), ISNULL(telefon_pej, '')) AS telefon_pej,
                        CONVERT(VARCHAR(200), ISNULL(jawatansemasa, '')) AS jawatansemasa,
                        CONVERT(VARCHAR(200), ISNULL(jabatansemasa, '')) AS jabatansemasa
                    FROM v630staf_service_skim_all
                    WHERE UPPER(CONVERT(VARCHAR(50), ISNULL(nopekerja, ''))) LIKE ?
                       OR UPPER(CONVERT(VARCHAR(200), ISNULL(gelar_nama, ''))) LIKE ?
                       OR UPPER(CONVERT(VARCHAR(200), ISNULL(email, ''))) LIKE ?
                    ORDER BY gelar_nama";
            $stmt = $sybasePdo->prepare($sql);
            $okExec = $stmt->execute([$like, $like, $like]);
        } else {
            $sql = "SELECT TOP {$limit}
                        CONVERT(VARCHAR(50), ISNULL(nopekerja, '')) AS nopekerja,
                        CONVERT(VARCHAR(200), ISNULL(gelar_nama, '')) AS gelar_nama,
                        CONVERT(VARCHAR(200), ISNULL(email, '')) AS email,
                        CONVERT(VARCHAR(50), ISNULL(handphone, '')) AS handphone,
                        CONVERT(VARCHAR(50), ISNULL(telefon_surat, '')) AS telefon_surat,
                        CONVERT(VARCHAR(50), ISNULL(telefon_pej, '')) AS telefon_pej,
                        CONVERT(VARCHAR(200), ISNULL(jawatansemasa, '')) AS jawatansemasa,
                        CONVERT(VARCHAR(200), ISNULL(jabatansemasa, '')) AS jabatansemasa
                    FROM v630staf_service_skim_all
                    ORDER BY gelar_nama";
            $stmt = $sybasePdo->query($sql);
            $okExec = ($stmt !== false);
        }

        if (!$okExec || !$stmt) {
            throw new Exception('Unable to load staff data.');
        }

        $rows = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $nopekerja = trim((string)($r['nopekerja'] ?? ''));
            $gelarNama = trim((string)($r['gelar_nama'] ?? ''));
            $email = trim((string)($r['email'] ?? ''));
            $handphone = trim((string)($r['handphone'] ?? ''));
            $telefonSurat = trim((string)($r['telefon_surat'] ?? ''));
            $telefonPej = trim((string)($r['telefon_pej'] ?? ''));
            $phone = $handphone !== '' ? $handphone : ($telefonSurat !== '' ? $telefonSurat : $telefonPej);
            $jawatan = trim((string)($r['jawatansemasa'] ?? ''));
            $jabatan = trim((string)($r['jabatansemasa'] ?? ''));

            if ($nopekerja === '' || $gelarNama === '') continue;

            $parts = [];
            $parts[] = $gelarNama;
            $parts[] = '(' . $nopekerja . ')';
            if ($jawatan !== '') $parts[] = '- ' . $jawatan;
            if ($jabatan !== '') $parts[] = '| ' . $jabatan;
            if ($email !== '') $parts[] = '| ' . $email;

            $rows[] = [
                'id' => $nopekerja,
                'text' => implode(' ', $parts),
                'nopekerja' => $nopekerja,
                'gelar_nama' => $gelarNama,
                'email' => $email,
                'phone' => $phone,
                'jawatan' => $jawatan,
                'jabatansemasa' => $jabatan
            ];
        }

        $out['ok'] = true;
        $out['results'] = $rows;
    } catch (Exception $e) {
        $out['ok'] = false;
        $out['error'] = $e->getMessage();
    }

    $out['elapsed_ms'] = (int)((microtime(true) - $t0) * 1000);
    echo json_encode($out);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sybase Staff Select2 Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .card { border: 0; box-shadow: 0 4px 16px rgba(0,0,0,0.06); }
        .select2-container { width: 100% !important; }
        pre { background: #111827; color: #e5e7eb; padding: 0.75rem; border-radius: 0.5rem; }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="card">
        <div class="card-body">
            <h4 class="mb-1">Select2 Dropdown Test (Sybase Staff)</h4>
            <p class="text-muted mb-3">Test page for checking data from the view <code>v630staf_service_skim_all</code>.</p>

            <div id="statusBox" class="alert alert-secondary py-2">Ready</div>

            <div class="mb-3">
                <label class="form-label">Select Staff</label>
                <select id="staffSelect" class="form-select"></select>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Name</label>
                    <input id="fNama" class="form-control" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Staff Number</label>
                    <input id="fNoPekerja" class="form-control" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input id="fEmail" class="form-control" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone Number</label>
                    <input id="fPhone" class="form-control" readonly>
                </div>
            </div>

            <div class="mt-3">
                <label class="form-label">MySQL Payload (committee_members)</label>
                <pre id="payloadBox">{}</pre>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function(){
    var $sel = $('#staffSelect');
    var $status = $('#statusBox');

    function setStatus(msg, type){
        type = type || 'secondary';
        $status.removeClass('alert-secondary alert-success alert-danger alert-warning').addClass('alert-' + type).text(msg);
    }

    function setPayload(data){
        $('#payloadBox').text(JSON.stringify(data, null, 2));
    }

    $sel.select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Search by name / staff number / email...',
        allowClear: true,
        minimumInputLength: 0,
        ajax: {
            delay: 250,
            url: window.location.pathname + '?ajax=staff_lookup',
            dataType: 'json',
            data: function(params){ return { q: params.term || '', limit: 100 }; },
            processResults: function(data){
                if (!data || !data.ok) {
                    setStatus((data && data.error) ? data.error : 'Unable to load staff data.', 'danger');
                    return { results: [] };
                }
                setStatus('Loaded successfully ' + (data.results ? data.results.length : 0) + ' records. Time: ' + (data.elapsed_ms || 0) + 'ms', 'success');
                return { results: data.results || [] };
            }
        }
    });

    $sel.on('select2:select', function(ev){
        var d = ev && ev.params && ev.params.data ? ev.params.data : null;
        if (!d) return;

        $('#fNama').val(d.gelar_nama || '');
        $('#fNoPekerja').val(d.nopekerja || d.id || '');
        $('#fEmail').val(d.email || '');
        $('#fPhone').val(d.phone || '');

        setPayload({
            member_ref_type: 'STAF',
            member_ref_id: d.nopekerja || d.id || '',
            member_name: d.gelar_nama || '',
            member_email: d.email || '',
            member_phone: d.phone || ''
        });
    });

    $sel.on('select2:clear', function(){
        $('#fNama,#fNoPekerja,#fEmail,#fPhone').val('');
        setPayload({});
        setStatus('Selection cleared.', 'warning');
    });

    setStatus('Ready. Open the dropdown or type to search.', 'secondary');
})();
</script>
</body>
</html>
