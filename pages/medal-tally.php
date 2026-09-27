<?php
/**
 * Medal Tally (Olympic/SEA Games style ranking)
 * Ranking priority: Gold DESC, Silver DESC, Bronze DESC, then alphabetic for display only.
 * Ties share the same rank number.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';

$page_title = 'Medal Tally';

$tally = [];
$totals = ['emas' => 0, 'perak' => 0, 'gangsa' => 0];

try {
    $db = getDB();

    // Build medal counts per contingent; include all active contingents even if zero medals.
    // Only count standings position 1/2/3 from completed results.
    $sql = "
        SELECT
            base.kod_universiti,
            base.nama_pendek,
            COALESCE(mc.emas, 0)   AS emas,
            COALESCE(mc.perak, 0)  AS perak,
            COALESCE(mc.gangsa, 0) AS gangsa
        FROM (
            SELECT DISTINCT
                k.kod_universiti,
                COALESCE(r.nama_pendek, k.kod_universiti) AS nama_pendek
            FROM table_kontinjen k
            JOIN table_ref_universiti r ON r.kod_universiti = k.kod_universiti
            WHERE k.deleted_at IS NULL AND k.status = 1 AND r.status = 1
        ) base
        LEFT JOIN (
            SELECT
                k.kod_universiti,
                SUM(CASE WHEN jt.position = 1 THEN 1 ELSE 0 END) AS emas,
                SUM(CASE WHEN jt.position = 2 THEN 1 ELSE 0 END) AS perak,
                SUM(CASE WHEN jt.position = 3 THEN 1 ELSE 0 END) AS gangsa
            FROM table_results tr
            JOIN JSON_TABLE(tr.standings, '$[*]' COLUMNS(
                position INT PATH '$.position',
                participant_id VARCHAR(255) PATH '$.participant_id'
            )) jt ON jt.position IN (1,2,3)
            JOIN table_pasukan p ON p.id = jt.participant_id AND p.deleted_at IS NULL
            JOIN table_kontinjen k ON k.id = p.kontinjen_id AND k.deleted_at IS NULL AND k.status = 1
            JOIN table_ref_universiti r ON r.kod_universiti = k.kod_universiti AND r.status = 1
            WHERE tr.deleted_at IS NULL AND tr.status = 'completed'
            GROUP BY k.kod_universiti
        ) mc ON mc.kod_universiti = base.kod_universiti
        ORDER BY emas DESC, perak DESC, gangsa DESC, base.nama_pendek ASC
    ";

    $stmt = $db->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    // Apply shared-rank logic (ties share the same rank)
    $ranked = [];
    $position = 0; // sequential ranking (no repeats even if tied)
    foreach ($rows as $row) {
        $position++;
        $row['rank'] = $position;
        $row['jumlah'] = (int)$row['emas'] + (int)$row['perak'] + (int)$row['gangsa'];
        $ranked[] = $row;
        $totals['emas'] += (int)$row['emas'];
        $totals['perak'] += (int)$row['perak'];
        $totals['gangsa'] += (int)$row['gangsa'];
    }
    $tally = $ranked;
} catch (Exception $e) {
    error_log('[medal-tally] error: ' . $e->getMessage());
    $tally = [];
}

ob_start();
?>
<div class="w-100 px-3">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="mb-0">Medal Tally</h2>
            <p class="text-muted">Official standings ranked by Gold → Silver → Bronze</p>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card text-center shadow-sm">
                <div class="card-body">
                    <i class="cil cil-star text-warning" style="font-size: 3rem;"></i>
                    <h3 class="mt-3 mb-0"><?php echo number_format($totals['emas']); ?></h3>
                    <p class="text-muted mb-0">Total Gold</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center shadow-sm">
                <div class="card-body">
                    <i class="cil cil-star text-secondary" style="font-size: 3rem;"></i>
                    <h3 class="mt-3 mb-0"><?php echo number_format($totals['perak']); ?></h3>
                    <p class="text-muted mb-0">Total Silver</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center shadow-sm">
                <div class="card-body">
                    <i class="cil cil-star text-danger" style="font-size: 3rem;"></i>
                    <h3 class="mt-3 mb-0"><?php echo number_format($totals['gangsa']); ?></h3>
                    <p class="text-muted mb-0">Total Bronze</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card mb-4 shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Medals by Contingent</strong>
                    <small class="text-muted">Ranking: Gold → Silver → Bronze (ties share a rank)</small>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" style="width:10%;">Position</th>
                                    <th scope="col">Contingent</th>
                                    <th scope="col" class="text-center" style="width:15%;">
                                        <i class="cil cil-star text-warning"></i> Gold
                                    </th>
                                    <th scope="col" class="text-center" style="width:15%;">
                                        <i class="cil cil-star text-secondary"></i> Silver
                                    </th>
                                    <th scope="col" class="text-center" style="width:15%;">
                                        <i class="cil cil-star text-danger"></i> Bronze
                                    </th>
                                    <th scope="col" class="text-center" style="width:15%;"><strong>Total</strong></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($tally)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">
                                            <i class="cil cil-star" style="font-size: 2rem;"></i>
                                            <p class="mt-2 mb-0">No medal data</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($tally as $row): ?>
                                        <tr data-kod="<?php echo htmlspecialchars($row['kod_universiti'], ENT_QUOTES, 'UTF-8'); ?>" data-kontinjen="<?php echo htmlspecialchars($row['nama_pendek'] ?? $row['kod_universiti'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>">
                                            <td class="fw-bold"><?php echo (int)$row['rank']; ?></td>
                                            <td><?php echo htmlspecialchars($row['nama_pendek'] ?? $row['kod_universiti'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="text-center text-warning fw-semibold">
                                                <button type="button" class="btn btn-link p-0 text-warning medal-detail-btn" data-medal="emas"><?php echo (int)$row['emas']; ?></button>
                                            </td>
                                            <td class="text-center text-secondary fw-semibold">
                                                <button type="button" class="btn btn-link p-0 text-secondary medal-detail-btn" data-medal="perak"><?php echo (int)$row['perak']; ?></button>
                                            </td>
                                            <td class="text-center text-danger fw-semibold">
                                                <button type="button" class="btn btn-link p-0 text-danger medal-detail-btn" data-medal="gangsa"><?php echo (int)$row['gangsa']; ?></button>
                                            </td>
                                            <td class="text-center fw-semibold"><?php echo (int)$row['jumlah']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<style>
.modal-top .modal-dialog { margin-top: 60px; margin-bottom: 20px; }
body.modal-open { overflow: hidden; padding-right: 0 !important; }
</style>
<div class="modal fade modal-top" id="medalDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Medal Recipients</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="fw-semibold" id="medalDetailTitle"></div>
                    <div class="badge bg-light text-dark" id="medalDetailMedal"></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>Contingent</th>
                                <th>Sport</th>
                                <th>Event</th>
                            </tr>
                        </thead>
                        <tbody id="medalDetailBody">
                            <tr><td colspan="5" class="text-center text-muted">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <div class="text-muted small" id="medalDetailSummary"></div>
                    <div class="d-flex align-items-center gap-2">
                        <select class="form-select form-select-sm" id="medalDetailPageSize" style="width:80px;">
                            <option value="5">5</option>
                            <option value="10" selected>10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                        <nav aria-label="Medal pagination">
                            <ul class="pagination pagination-sm mb-0" id="medalDetailPager"></ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function(){
    function whenJQ(cb){ if (window.jQuery){ cb(window.jQuery); } else { setTimeout(function(){ whenJQ(cb); }, 50); } }
    whenJQ(function($){
        var medalRowsCache = [];
        var pageSize = 10;
        var currentPage = 1;

        function renderPager(total){
            var totalPages = Math.max(1, Math.ceil(total / pageSize));
            if (currentPage > totalPages) currentPage = totalPages;
            var $pager = $('#medalDetailPager');
            $pager.empty();
            var add = function(label, page, disabled, active){
                var li = $('<li class="page-item">');
                if (disabled) li.addClass('disabled');
                if (active) li.addClass('active');
                var a = $('<a class="page-link" href="#">').text(label).data('page', page);
                li.append(a); $pager.append(li);
            };
            add('«', currentPage-1, currentPage===1, false);
            var start = Math.max(1, currentPage-2), end = Math.min(totalPages, start+4);
            for (var p=start; p<=end; p++){ add(p, p, false, p===currentPage); }
            add('»', currentPage+1, currentPage===totalPages, false);
            var startRow = total === 0 ? 0 : (currentPage-1)*pageSize + 1;
            var endRow = Math.min(total, currentPage*pageSize);
            $('#medalDetailSummary').text(total === 0 ? 'No data' : ('Showing '+startRow+' - '+endRow+' of '+total));
        }

        function renderDetailTable(){
            var $body = $('#medalDetailBody');
            if (!medalRowsCache.length){
                $body.html('<tr><td colspan="5" class="text-center text-muted">No recipient records.</td></tr>');
                renderPager(0); return;
            }
            var start = (currentPage-1)*pageSize;
            var end = start + pageSize;
            var pageRows = medalRowsCache.slice(start, end);
            var html = '';
            pageRows.forEach(function(r, idx){
                html += '<tr>'+
                    '<td>'+(start+idx+1)+'</td>'+
                    '<td>'+(r.nama_pasukan || '-')+'</td>'+
                    '<td>'+(r.nama_kontinjen || r.kod_universiti || '-')+'</td>'+
                    '<td>'+(r.nama_sukan || '-')+'</td>'+
                    '<td>'+(r.nama_kategori || '-')+'</td>'+
                '</tr>';
            });
            $body.html(html);
            renderPager(medalRowsCache.length);
        }

        $('#medalDetailPager').on('click', 'a.page-link', function(e){
            e.preventDefault();
            var p = $(this).data('page');
            if (!p) return;
            currentPage = parseInt(p,10) || 1;
            renderDetailTable();
        });

        $('#medalDetailPageSize').on('change', function(){
            pageSize = parseInt($(this).val(),10) || 10;
            currentPage = 1;
            renderDetailTable();
        });

        $(document).on('click', '.medal-detail-btn', function(){
            var $tr = $(this).closest('tr');
            var kod = $tr.data('kod');
            var name = $tr.data('kontinjen') || kod;
            var medal = $(this).data('medal');
            if (!kod || !medal) return;
            $('#medalDetailTitle').text(name);
            $('#medalDetailMedal').text(medal.toUpperCase());
            $('#medalDetailBody').html('<tr><td colspan="5" class="text-center text-muted">Loading...</td></tr>');
            $('#medalDetailSummary').text('');
            medalRowsCache = []; currentPage = 1;
            $('#medalDetailModal').modal('show');
            $.ajax({
                url: '<?php echo url('ajax/medal_recipients.php'); ?>',
                data: { kod_universiti: kod, medal: medal },
                dataType: 'json'
            }).done(function(res){
                if (!res || res.status !== 'ok') {
                    $('#medalDetailBody').html('<tr><td colspan="5" class="text-center text-danger">Unable to load data</td></tr>');
                    $('#medalDetailSummary').text('Unable to load data');
                    return;
                }
                medalRowsCache = res.data || [];
                renderDetailTable();
            }).fail(function(){
                $('#medalDetailBody').html('<tr><td colspan="5" class="text-center text-danger">Unable to load data</td></tr>');
                $('#medalDetailSummary').text('Unable to load data');
            });
        });
    });
})();
</script>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>
