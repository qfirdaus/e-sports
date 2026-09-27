<?php if ($currentUser): ?>
<style>
#editProfileModal .modal-dialog {
    display:block; position:relative; top:0!important;
    margin:var(--profile-modal-top, 70px) auto 24px!important;
    padding:0 12px; width:100%; max-width:524px;
    min-height:0!important; transform:none!important;
}
#editProfileModal .modal-dialog::before { display:none; }
#editProfileModal .modal-content { width:100%; margin:0; }
</style>
<div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileTitle" aria-hidden="true">
<div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title" id="editProfileTitle">Edit Profile</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
<form id="editProfileForm"><div class="modal-body">
<input type="hidden" name="csrf" value="<?php echo htmlspecialchars(Session::get('profile_csrf'), ENT_QUOTES, 'UTF-8'); ?>">
<label for="profileFullName" class="form-label">Full Name</label>
<input class="form-control" id="profileFullName" name="full_name" maxlength="100" autocomplete="name" required value="<?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>">
<p class="small text-muted mt-2">Update the name displayed on your account. Participant and certificate records are managed separately.</p>
<div id="editProfileMessage" role="status" aria-live="polite" class="small mt-2"></div>
</div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div></form>
</div></div></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('editProfileForm');
    var modalEl = document.getElementById('editProfileModal');
    document.body.appendChild(modalEl);
    function positionProfileModal() {
        var header = document.querySelector('.header-section');
        var offset = header ? header.offsetHeight + 12 : 70;
        modalEl.style.setProperty('--profile-modal-top', offset + 'px');
    }
    window.addEventListener('resize', positionProfileModal);
    document.querySelectorAll('.trigger-edit-profile').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault(); document.getElementById('editProfileMessage').textContent = '';
            positionProfileModal();
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        });
    });
    form.addEventListener('submit', async function(e) {
        e.preventDefault(); var btn = form.querySelector('[type=submit]'); if (btn.disabled) return;
        var msg = document.getElementById('editProfileMessage'); btn.disabled = true; msg.textContent = 'Saving...';
        try {
            var response = await fetch(<?php echo json_encode(url('ajax/update_profile.php')); ?>, {method:'POST',credentials:'same-origin',body:new FormData(form),headers:{Accept:'application/json'}});
            var result = await response.json(); msg.textContent = result.message;
            if (result.success) { window.location.reload(); return; }
        } catch(e) { msg.textContent = 'Unable to save. Please try again.'; }
        btn.disabled = false;
    });
});
</script>
<?php endif; ?>
