<?php
// submit-complaint.php
// This file contains the complaint submission form with client-side file validation using SweetAlert2

// Make sure these are available from your including file or session
// $ALLOWED_CATEGORIES array
// $_SESSION['csrf_token']

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<div class="form-card">
    <div class="full-form-header">
        <h3 class="text-xl font-bold text-gray-900 mb-1">Submit a Complaint</h3>
        <p class="text-sm text-gray-600">Help us resolve your concern quickly.</p>
    </div>

    <form id="complaintForm" enctype="multipart/form-data" class="full-form-grid">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <!-- Category -->
        <div class="form-group form-grid-col-span-2">
            <label class="form-label">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 inline mr-1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                </svg>
                Category <span class="text-red-500">*</span>
            </label>
            <select name="category" class="form-select" required>
                <option value="" disabled selected>Select category</option>
                <?php foreach ($ALLOWED_CATEGORIES as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Location / Map -->
        <div class="form-group form-grid-col-span-2">
            <label class="form-label">Location <span class="text-red-500">*</span></label>
            <div id="map" style="height: 350px; width: 100%; border-radius: 0.5rem; border: 1px solid #d1d5db; margin-bottom: 1rem;"></div>
            <input type="hidden" name="location_lat" id="lat" required>
            <input type="hidden" name="location_lng" id="lng" required>
            <input type="text" name="location_address" id="address" class="form-input text-sm" placeholder="Click map to select location..." readonly required>
            <div class="form-tip text-xs mt-1">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3 inline mr-1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                </svg>
                Click map for street address & postal auto-fill. Drag the pin to adjust.
            </div>
        </div>

        <!-- Attachment with SweetAlert2 validation -->
        <div class="form-group form-grid-col-span-2">
            <label class="form-label">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 inline mr-1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" />
                </svg>
                Attachment (Optional)
            </label>
            <input type="file" name="attachment" id="attachmentInput" class="form-input" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx">
            <div class="form-tip text-xs mt-1">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3 inline mr-1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                </svg>
                JPG, JPEG, PNG, GIF, PDF, DOC, DOCX only • Max 20MB
            </div>
        </div>

        <!-- Description -->
        <div class="form-group full-form-submit">
            <label class="form-label">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 inline mr-1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
                </svg>
                Description <span class="text-red-500">*</span>
            </label>
            <textarea name="description" class="form-textarea" placeholder="Describe your concern in detail..." minlength="10" required rows="5"></textarea>
        </div>

        <!-- Submit -->
        <div class="form-group full-form-submit submit-section">
            <button type="submit" class="btn-primary w-full text-white px-4 py-2 rounded-xl font-semibold">
                <i class="fas fa-paper-plane mr-2"></i> Submit
            </button>
        </div>
    </form>
</div>

<!-- SweetAlert2 CDN (add this if not already in your main layout) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Your CSS (keep or move to external file) -->
<style>
.full-form-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.5rem;
}
.form-grid-col-span-2 { grid-column: 1 / -1; }
.form-label { margin-bottom: 0.375rem; display: flex; align-items: center; gap: 0.375rem; }
.form-tip { color: #6b7280; }
.btn-primary { background: #3b82f6; }
.btn-primary:hover { background: #2563eb; }
.form-input, .form-select, .form-textarea {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid #d1d5db;
    border-radius: 0.375rem;
}
</style>

<script>
// ────────────────────────────────────────────────
//   MAIN SCRIPT – MAP + FILE VALIDATION + SUBMIT
// ────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {

    // Map setup
    const map = L.map('map', { scrollWheelZoom: false }).setView([14.2127, 121.1154], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    const customIcon = L.icon({
        iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-blue.png',
        shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
        iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41]
    });

    let marker = null;

    map.on('click', function(e) {
        if (!map.scrollWheelZoom.enabled()) map.scrollWheelZoom.enable();
        if (marker) map.removeLayer(marker);
        marker = L.marker(e.latlng, { icon: customIcon, draggable: true }).addTo(map);
        document.getElementById('lat').value = e.latlng.lat.toFixed(8);
        document.getElementById('lng').value = e.latlng.lng.toFixed(8);
        reverseGeocode(e.latlng.lat, e.latlng.lng);

        marker.on('dragend', function(ev) {
            const latlng = ev.target.getLatLng();
            document.getElementById('lat').value = latlng.lat.toFixed(8);
            document.getElementById('lng').value = latlng.lng.toFixed(8);
            reverseGeocode(latlng.lat, latlng.lng);
        });
    });

    async function reverseGeocode(lat, lng) {
        try {
            const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1`, {
                headers: { 'User-Agent': 'ComplaintApp/1.0' }
            });
            const data = await res.json();
            document.getElementById('address').value = data.display_name || 'Address not found';
        } catch {
            document.getElementById('address').value = 'Unable to fetch address';
        }
    }

    setTimeout(() => map.invalidateSize(), 150);

    // ──────────────── FILE VALIDATION WITH SWEETALERT2 ────────────────
    const allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
    const maxSizeBytes = 20 * 1024 * 1024;

    const fileInput = document.getElementById('attachmentInput');

    fileInput.addEventListener('change', function() {
        const file = this.files[0];
        if (!file) return;

        const ext = file.name.split('.').pop()?.toLowerCase() || '';

        if (!allowedExtensions.includes(ext)) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid File Type',
                confirmButtonColor: '#dc2626',
                confirmButtonText: 'Got it'
            });
            this.value = '';
            return;
        }

        if (file.size > maxSizeBytes) {
            Swal.fire({
                icon: 'warning',
                title: 'File Too Large',
                html: `Your file is ${(file.size / 1024 / 1024).toFixed(1)} MB<br>Maximum allowed: <strong>20 MB</strong>`,
                confirmButtonColor: '#f59e0b',
                confirmButtonText: 'OK'
            });
            this.value = '';
            return;
        }

        // Optional: success toast (uncomment if you want)
        // Swal.fire({ icon: 'success', title: 'File accepted', toast: true, position: 'top-end', showConfirmButton: false, timer: 1500 });
    });

    // ──────────────── FORM SUBMISSION ────────────────
    document.getElementById('complaintForm').addEventListener('submit', async function(e) {
        e.preventDefault();

        // Double-check attachment before sending
        if (fileInput.files.length > 0) {
            const file = fileInput.files[0];
            const ext = file.name.split('.').pop()?.toLowerCase() || '';
            if (!allowedExtensions.includes(ext) || file.size > maxSizeBytes) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Attachment',
                    text: 'Please correct or remove the attachment before submitting.',
                    confirmButtonColor: '#3b82f6'
                });
                return;
            }
        }

        // Location check
        if (!document.getElementById('lat').value || !document.getElementById('lng').value) {
            Swal.fire({
                icon: 'warning',
                title: 'Location Required',
                text: 'Please select a location on the map.',
                confirmButtonColor: '#3b82f6'
            });
            return;
        }

        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Submitting...';
        submitBtn.disabled = true;

        const formData = new FormData(this);

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const data = await response.json();

            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.msg || 'Your complaint has been submitted.',
                    confirmButtonColor: '#10b981',
                    timer: 3000,
                    timerProgressBar: true
                }).then(() => {
                    this.reset();
                    if (marker) map.removeLayer(marker);
                    document.getElementById('address').value = '';
                    document.getElementById('lat').value = '';
                    document.getElementById('lng').value = '';

                    // Your list refresh logic
                    const refreshUrl = `${window.location.pathname}?ajax=refresh_list&page=1&status=&category=&q=`;
                    fetch(refreshUrl)
                        .then(r => r.json())
                        .then(refresh => {
                            if (refresh.success) {
                                document.getElementById('viewTabContent').innerHTML = refresh.html;
                                const viewTab = document.getElementById('viewTab');
                                viewTab.innerHTML = viewTab.innerHTML.replace(/\(\d+\)/, `(${refresh.total})`);
                                viewTab.click();
                            }
                        })
                        .catch(() => document.getElementById('viewTab')?.click());
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Submission Failed',
                    text: data.msg || 'An error occurred. Please try again.',
                    confirmButtonColor: '#ef4444'
                });
            }
        } catch (err) {
            Swal.fire({
                icon: 'error',
                title: 'Network Error',
                text: 'Please check your internet connection and try again.',
                confirmButtonColor: '#ef4444'
            });
        } finally {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    });
});
</script>