<?php
/**
 * University Management Page — load data from table_ref_universiti
 */
require_once __DIR__ . '/../config/config.php';
// Ensure database helper is available
require_once __DIR__ . '/../config/database.php';

$page_title = 'Universities';

// Fetch data from DB
$pdo = null;
$universities = [];
$counts = ['total' => 0, 'active' => 0, 'inactive' => 0];
try {
	$pdo = getDB();
	$stmt = $pdo->query("SELECT * FROM table_ref_universiti WHERE deleted_at IS NULL ORDER BY nama_universiti ASC");
	$universities = $stmt->fetchAll(PDO::FETCH_ASSOC);
	$counts['total'] = count($universities);
	foreach ($universities as $u) {
		if (isset($u['status']) && (int)$u['status'] === 1) $counts['active']++;
		else $counts['inactive']++;
	}
} catch (Exception $e) {
	// Log and continue with empty list
	error_log('[university.php] DB error: ' . $e->getMessage());
}

ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/contingent-management.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/contingent-management.css'); ?>">
<link rel="stylesheet" href="<?php echo asset('css/university.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/university.css'); ?>">
<div class="w-100 px-3 contingent-management university-view">
	<!-- Hero -->
	<div class="row mb-4 management-heading">
		<div class="col-12">
			<div class="card bg-light border-0 shadow-sm overflow-hidden">
				<div class="card-body py-4 d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
					<div>
						<div class="management-eyebrow">SAM 2026 &middot; UNIVERSITY DIRECTORY</div><h2 class="mb-1">University</h2>
						<p class="text-muted mb-0">Manage institution and university reference information</p>
					</div>

					<div class="d-flex align-items-center gap-3">
						<div class="management-stats">
							<div class="me-3 text-center">
								<div class="h5 mb-0"><?php echo (int)$counts['total']; ?></div>
								<div class="small text-muted">Total</div>
							</div>
							<div class="me-3 text-center">
								<div class="h5 mb-0"><?php echo (int)$counts['active']; ?></div>
								<div class="small text-muted">Active</div>
							</div>
						</div>

						<div class="btn-group">
							<button class="btn btn-outline-secondary" id="btnPrint">Print</button>
							<button class="btn btn-primary" id="btnAddUniversity">
								<i class="cil cil-plus me-1"></i> Add University
							</button>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- Universities List -->
	<div class="row">
		<div class="col-12">
			<div class="card mb-4 shadow-sm">
				<div class="card-header d-flex justify-content-between align-items-center">
					<div>
						<strong>University List</strong>
						<div class="small text-muted">Manage university references</div>
					</div>
				</div>
				<div class="card-body">
					<div class="table-responsive">
						<table class="table table-hover table-striped align-middle">
							<thead class="table-light">
								<tr>
									<th scope="col" style="width:60px;">#</th>
									<th scope="col">Code</th>
									<th scope="col">Full Name</th>
									<th scope="col">Type</th>
									<th scope="col">State</th>
									<th scope="col">Country</th>
									<th scope="col">Email</th>
									<th scope="col">Phone</th>
									<th scope="col" style="width:110px;">Status</th>
									<th scope="col" style="width:90px;">Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php if (empty($universities)): ?>
									<tr>
										<td colspan="10" class="text-center text-muted py-5">
											<i class="cil cil-info" style="font-size: 2rem;"></i>
											<p class="mt-2">No university records. Click "Add University" to get started.</p>
										</td>
									</tr>
								<?php else: ?>
									<?php foreach ($universities as $i => $u): ?>
										<tr>
											<td><?php echo $i + 1; ?></td>
											<td><?php echo htmlspecialchars($u['kod_universiti'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
											<td><?php echo htmlspecialchars($u['nama_universiti'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
											<td><?php echo htmlspecialchars(english_label($u['jenis_universiti'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
											<td><?php echo htmlspecialchars($u['negeri'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
											<td><?php echo htmlspecialchars($u['negara'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
											<td><?php echo htmlspecialchars($u['emel_rasmi'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
											<td><?php echo htmlspecialchars($u['no_tel'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
											<td>
												<?php if (isset($u['status']) && (int)$u['status'] === 1): ?>
													<span class="badge bg-success">Active</span>
												<?php else: ?>
													<span class="badge bg-secondary">Inactive</span>
												<?php endif; ?>
											</td>
											<td>
												<a class="btn btn-sm btn-outline-primary edit-university" title="Edit" href="#"
												   data-id="<?php echo (int)$u['id']; ?>"
												   data-kod="<?php echo htmlspecialchars($u['kod_universiti'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
												   data-nama="<?php echo htmlspecialchars($u['nama_universiti'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
												   data-jenis="<?php echo htmlspecialchars($u['jenis_universiti'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
												   data-negeri="<?php echo htmlspecialchars($u['negeri'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
												   data-negara="<?php echo htmlspecialchars($u['negara'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
												   data-emel="<?php echo htmlspecialchars($u['emel_rasmi'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
												   data-telefon="<?php echo htmlspecialchars($u['no_tel'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
												   data-status="<?php echo isset($u['status']) && (int)$u['status'] === 1 ? '1' : '0'; ?>"
												>
													<i class="fa fa-edit"></i>
												</a>
												<a class="btn btn-sm btn-outline-danger delete-university" title="Delete" href="#" data-id="<?php echo (int)$u['id']; ?>">
													<i class="fa fa-trash"></i>
												</a>
											</td>
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

<?php
$content = ob_get_clean();

// Capture modal and scripts into the content so they render inside layout (before footer)
ob_start();
?>
<!-- University Edit Modal -->
<div class="modal fade" id="universityModal" tabindex="-1" aria-labelledby="universityModalLabel" aria-hidden="true">
	<div class="modal-dialog modal-lg modal-dialog-centered">
		<div class="modal-content">
			<form id="universityForm" method="post" action="">
				<input type="hidden" name="id" id="u_id">
				<div class="modal-header">
					<h5 class="modal-title" id="universityModalLabel">Edit University</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<div class="row g-3">
						<div class="col-md-6">
							<label class="form-label">University Code</label>
							<input type="text" class="form-control" name="kod_universiti" id="u_kod" required>
						</div>
						<div class="col-md-6">
							<label class="form-label">Type</label>
							<select class="form-select" name="jenis_universiti" id="u_jenis">
								<option value="Awam">Public</option>
								<option value="Swasta">Private</option>
								<option value="Luar Negara">Overseas</option>
							</select>
						</div>
						<div class="col-md-12">
							<label class="form-label">Full Name</label>
							<input type="text" class="form-control" name="nama_universiti" id="u_nama" required>
						</div>
						<div class="col-md-6">
							<label class="form-label">State</label>
							<input type="text" class="form-control" name="negeri" id="u_negeri">
						</div>
						<div class="col-md-6">
							<label class="form-label">Country</label>
							<input type="text" class="form-control" name="negara" id="u_negara">
						</div>
						<div class="col-md-6">
							<label class="form-label">Official Email</label>
							<input type="email" class="form-control" name="emel_rasmi" id="u_emel">
						</div>
						<div class="col-md-6">
							<label class="form-label">Phone Number</label>
							<input type="text" class="form-control" name="no_tel" id="u_tel">
						</div>
						<div class="col-md-12">
							<label class="form-label">Status</label>
							<select class="form-select" name="status" id="u_status">
								<option value="1">Active</option>
								<option value="0">Inactive</option>
							</select>
						</div>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
					<button type="submit" class="btn btn-primary">Save Changes</button>
				</div>
			</form>
		</div>
	</div>
</div>

<script>
	(function(){
		function showEditModal(data){
			try{
				document.getElementById('u_id').value = data.id || '';
				document.getElementById('u_kod').value = data.kod || '';
				document.getElementById('u_nama').value = data.nama || '';
				document.getElementById('u_jenis').value = data.jenis || 'Awam';
				document.getElementById('u_negeri').value = data.negeri || '';
				document.getElementById('u_negara').value = data.negara || '';
				document.getElementById('u_emel').value = data.emel || '';
				document.getElementById('u_tel').value = data.telefon || '';
				document.getElementById('u_status').value = data.status || '0';

				// Show bootstrap modal
				var modalEl = document.getElementById('universityModal');
				if (window.bootstrap && modalEl) {
					var m = new bootstrap.Modal(modalEl);
					m.show();
				} else if (window.jQuery && jQuery(modalEl).modal) {
					jQuery(modalEl).modal('show');
				}
			}catch(e){ console && console.warn && console.warn(e); }
		}

		document.addEventListener('click', function(e){
			var t = e.target.closest && e.target.closest('.edit-university');
			if (t) {
				e.preventDefault();
				var data = {
					id: t.getAttribute('data-id'),
					kod: t.getAttribute('data-kod'),
					nama: t.getAttribute('data-nama'),
					jenis: t.getAttribute('data-jenis'),
					negeri: t.getAttribute('data-negeri'),
					negara: t.getAttribute('data-negara'),
					emel: t.getAttribute('data-emel'),
					telefon: t.getAttribute('data-telefon'),
					status: t.getAttribute('data-status')
				};
				showEditModal(data);
			}

			var del = e.target.closest && e.target.closest('.delete-university');
			if (del) {
				e.preventDefault();
				var id = del.getAttribute('data-id');
				if (!id) return;
				if (window.Swal) {
					Swal.fire({
						title: 'Delete this record?',
						text: 'The record will be deleted. This action cannot be undone',
						icon: 'warning',
						showCancelButton: true,
						confirmButtonText: 'Delete',
						cancelButtonText: 'Cancel'
					}).then(function(r){
						if (r.isConfirmed) {
							// call AJAX delete endpoint
							fetch('<?php echo url("ajax/university_delete.php"); ?>', {
								method: 'POST',
								credentials: 'same-origin',
								headers: { 'Accept': 'application/json' },
								body: new URLSearchParams({ id: id })
							}).then(function(res){ return res.json(); }).then(function(json){
								if (json && json.success) {
									Swal.fire({ text: json.message || 'Record deleted', icon: 'success' }).then(function(){ location.reload(); });
								} else {
									Swal.fire({ text: (json && json.message) || 'Operation not allowed', icon: 'info' });
								}
							}).catch(function(err){
								Swal.fire({ text: 'Server error. Please try again.', icon: 'error' });
							});
						}
					});
				} else {
					if (confirm('Delete this record?')) {
						// call AJAX delete endpoint (fallback when SweetAlert not available)
						fetch('<?php echo url("ajax/university_delete.php"); ?>', {
							method: 'POST',
							credentials: 'same-origin',
							headers: { 'Accept': 'application/json' },
							body: new URLSearchParams({ id: id })
						}).then(function(res){ return res.json(); }).then(function(json){
							if (json && json.success) {
								alert(json.message || 'Record deleted');
								location.reload();
							} else {
								alert((json && json.message) || 'Operation not allowed');
							}
						}).catch(function(){ alert('Server error. Please try again.'); });
					}
				}
			}
		});

		// Handle form submit via AJAX
		var form = document.getElementById('universityForm');
		if (form) {
			form.addEventListener('submit', function(ev){
				ev.preventDefault();
				var fd = new FormData(form);
				if (window.Swal) {
					Swal.showLoading();
				}
				fetch('<?php echo url("ajax/university_save.php"); ?>', {
					method: 'POST',
					credentials: 'same-origin',
					body: fd,
					headers: { 'Accept': 'application/json' }
				}).then(function(res){ return res.json(); }).then(function(json){
					if (window.Swal) Swal.close();
					if (json && json.success) {
						if (window.Swal) {
							Swal.fire({ text: json.message || 'Saved', icon: 'success' }).then(function(){ location.reload(); });
						} else {
							alert(json.message || 'Saved'); location.reload();
						}
					} else {
						if (window.Swal) Swal.fire({ text: (json && json.message) || 'Error', icon: 'error' });
						else alert((json && json.message) || 'Error');
					}
				}).catch(function(err){
					if (window.Swal) { Swal.close(); Swal.fire({ text: 'Connection error. Please try again.', icon: 'error' }); }
					else alert('Connection error. Please try again.');
				});
			});
		}

		// Hook up Add button to open blank modal
		var btnAdd = document.getElementById('btnAddUniversity');
		if (btnAdd) {
			btnAdd.addEventListener('click', function(e){
				e.preventDefault();
				showEditModal({});
			});
		}

		// Hook up Print button to print inline (no new window).
		// It injects a temporary print container containing only the title and table,
		// applies print-only styles, triggers print, then cleans up.
		var btnPrint = document.getElementById('btnPrint');
		if (btnPrint) {
			btnPrint.addEventListener('click', function(e){
				e.preventDefault();
				var table = document.querySelector('.table-responsive table');
				if (!table) { alert('No schedules to print'); return; }

				// Clone table and remove the last column (Actions)
				var tableClone = table.cloneNode(true);
				try {
					var ths = tableClone.querySelectorAll('thead th');
					if (ths.length > 0) ths[ths.length - 1].remove();
					var rows = tableClone.querySelectorAll('tbody tr');
					rows.forEach(function(r){
						var tds = r.querySelectorAll('td');
						if (tds.length > 0) tds[tds.length - 1].remove();
					});
				} catch (err) { console && console.warn && console.warn(err); }

				var printTitle = '<?php echo addslashes($page_title); ?>';
				var siteName = '<?php echo addslashes(SITE_NAME); ?>';

				// Create container
				var printContainer = document.createElement('div');
				printContainer.className = 'print-container';
				printContainer.style.display = 'none';

				var titleEl = document.createElement('h1');
				titleEl.textContent = siteName + ' — ' + printTitle;
				printContainer.appendChild(titleEl);
				printContainer.appendChild(tableClone);
				document.body.appendChild(printContainer);

				// Inject print styles that hide everything except .print-container during print
				var styleEl = document.createElement('style');
				styleEl.id = 'printStyles';
				styleEl.type = 'text/css';
				styleEl.appendChild(document.createTextNode('@media print { body * { visibility: hidden !important; } .print-container, .print-container * { visibility: visible !important; } .print-container { position: absolute; left:0; top:0; width:100%; } } .print-container h1{font-size:18pt;margin-bottom:8px;} .print-container table{width:100%;border-collapse:collapse;} .print-container th, .print-container td{border:1px solid #ddd;padding:6px;}'));
				document.head.appendChild(styleEl);

				// Show container then print
				printContainer.style.display = '';

				var cleanup = function(){
					try { if (styleEl && styleEl.parentNode) styleEl.parentNode.removeChild(styleEl); } catch(e){}
					try { if (printContainer && printContainer.parentNode) printContainer.parentNode.removeChild(printContainer); } catch(e){}
					window.removeEventListener('afterprint', cleanup);
				};

				window.addEventListener('afterprint', cleanup);
				// Fallback cleanup in case afterprint isn't supported
				setTimeout(function(){
					try { window.print(); } catch(e){ console && console.warn && console.warn(e); }
					setTimeout(cleanup, 1000);
				}, 50);
			});
		}
	})();
</script>
<?php
$modalHtml = ob_get_clean();
$content .= $modalHtml;

require_once __DIR__ . '/../includes/layout.php';
?>
