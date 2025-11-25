<?php
// includes/submit-complaint.php

// Process form submission (moved to main file, this is just the form)
?>
<div class="form-card">
    <div class="full-form-header">
        <h3 class="text-xl font-bold text-gray-900 mb-1">Submit a Complaint</h3>
        <p class="text-sm text-gray-600">Help us resolve your concern quickly.</p>
    </div>
    <form id="complaintForm" enctype="multipart/form-data" class="full-form-grid">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
        <div class="form-group form-grid-col-span-2">
            <label class="form-label">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                </svg>
                Category <span class="text-red-500">*</span>
            </label>
            <select name="category" class="form-select" required>
                <option value="" disabled selected>Select category</option>
                <?php foreach ($ALLOWED_CATEGORIES as $cat): ?>
                    <option value="<?php echo e($cat); ?>"><?php echo e($cat); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group form-grid-col-span-2"> <!-- Location now full-width to allow bigger map -->
            <label class="form-label">Location <span class="text-red-500">*</span></label>
            <div id="map" style="height: 350px; width: 100%; border-radius: 0.5rem; border: 1px solid #d1d5db; margin-bottom: 0.25rem;"></div>
            <input type="hidden" name="location_lat" id="lat" required>
            <input type="hidden" name="location_lng" id="lng" required>
            <input type="text" name="location_address" id="address" class="form-input text-sm" placeholder="Click map to select location..." readonly required style="font-size: 0.875rem; padding: 0.375rem 0.5rem; margin-bottom: 0.25rem;">
            <div class="form-tip text-xs">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3 inline mr-1">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                </svg>
                Click map for street address & postal auto-fill. Drag the pin to adjust.
            </div>
        </div>
        <div class="form-group form-grid-col-span-2"> <!-- Attachment full-width below map row para balance at occupy space -->
            <label class="form-label">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" />
                </svg>
                Attachment (Optional)
            </label>
            <input type="file" name="attachment" class="form-input" accept="image/*,.pdf,.doc,.docx">
            <div class="form-tip text-xs">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3 inline mr-1">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                </svg>
                JPG, PNG, GIF, PDF, DOC, DOCX (max 5MB)
            </div>
        </div>
        <div class="form-group full-form-submit"> <!-- Description full-width, unchanged -->
            <label class="form-label">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
                </svg>
                Description <span class="text-red-500">*</span>
            </label>
            <textarea name="description" class="form-textarea" placeholder="Describe your concern in detail..." minlength="10" required rows="5"></textarea>
        </div>
        <div class="form-group full-form-submit submit-section"> <!-- Button full-width, unchanged -->
            <button type="submit" class="btn-primary w-full text-white px-4 py-2 rounded-xl font-semibold">
                <i class="fas fa-paper-plane mr-2"></i>
                Submit
            </button>
        </div>
    </form>
</div>

<style>
/* Custom CSS to occupy wasted spaces: tighter spacing, balanced grid */
.full-form-grid {
    display: grid;
    grid-template-columns: 1fr; /* Single column now for full-width elements, better for larger map */
    gap: 1rem; /* Uniform gap, reduced for tighter layout */
    align-items: start; /* Align items to top to reduce vertical waste */
}

.form-grid-col-span-2 {
    grid-column: 1 / -1; /* Full width for all spanned elements */
}

.form-group {
    margin-bottom: 0.75rem; /* Reduced from default 1rem to tighten vertical space */
}

.form-label {
    margin-bottom: 0.375rem; /* Tighter label spacing */
}

.form-tip {
    margin-top: 0.25rem; /* Reduced tip spacing */
}

.form-textarea {
    min-height: 120px; /* Slightly taller textarea to fill vertical space better */
}

@media (max-width: 768px) {
    .full-form-grid {
        grid-template-columns: 1fr; /* Already single column on mobile */
        gap: 1rem;
    }
    
    #map {
        height: 300px !important; /* Slightly smaller on mobile for usability */
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Leaflet Map (increased height to 350px for better space utilization)
    const map = L.map('map').setView([14.2127, 121.1154], 13); // Calamba center
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    // Custom blue pin (changed from default red)
    const customIcon = L.icon({
        iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-blue.png',
        shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
        iconSize: [25, 41],
        iconAnchor: [12, 41],
        popupAnchor: [1, -34],
        shadowSize: [41, 41]
    });

    let marker;
    map.on('click', function(e) {
        if (marker) {
            map.removeLayer(marker);
        }
        marker = L.marker(e.latlng, { icon: customIcon, draggable: true }).addTo(map);
        document.getElementById('lat').value = e.latlng.lat.toFixed(8);
        document.getElementById('lng').value = e.latlng.lng.toFixed(8);
        reverseGeocode(e.latlng.lat, e.latlng.lng);

        // Add dragend listener to update lat/lng and address when pin is dragged
        marker.on('dragend', function(e) {
            const latlng = e.target.getLatLng();
            document.getElementById('lat').value = latlng.lat.toFixed(8);
            document.getElementById('lng').value = latlng.lng.toFixed(8);
            reverseGeocode(latlng.lat, latlng.lng);
        });
    });

    async function reverseGeocode(lat, lng) {
        const url = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1`;
        try {
            const response = await fetch(url, {
                headers: { 'User-Agent': 'CWD-AquaSense-App/1.0' }
            });
            const data = await response.json();
            const displayName = data.display_name || 'Address not found';
            document.getElementById('address').value = displayName;
        } catch (error) {
            console.error('Reverse geocoding failed:', error);
            document.getElementById('address').value = 'Unable to fetch address. Try another spot.';
        }
    }

    const form = document.getElementById('complaintForm');
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const lat = document.getElementById('lat').value;
        const lng = document.getElementById('lng').value;
        if (!lat || !lng) {
            Swal.fire({
                title: 'Location Required!',
                text: 'Please click on the map to select your location.',
                icon: 'warning',
                confirmButtonColor: '#3b82f6'
            });
            return;
        }
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Submitting...';
        submitBtn.disabled = true;

        const formData = new FormData(form);

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const data = await response.json();

            if (data.success) {
                Swal.fire({
                    title: 'Success!',
                    text: data.msg,
                    icon: 'success',
                    confirmButtonColor: '#3b82f6',
                    timer: 3000,
                    timerProgressBar: true
                }).then(() => {
                    form.reset();
                    // Clear map & fields
                    if (marker) map.removeLayer(marker);
                    document.getElementById('address').value = '';
                    document.getElementById('lat').value = '';
                    document.getElementById('lng').value = '';
                    // Refresh list & switch tab (unchanged)
                    const refreshUrl = `${window.location.pathname}?ajax=refresh_list&page=1&status=&category=&q=`;
                    fetch(refreshUrl)
                        .then(res => res.json())
                        .then(refreshData => {
                            if (refreshData.success) {
                                document.getElementById('viewTabContent').innerHTML = refreshData.html;
                                const viewTabBtn = document.getElementById('viewTab');
                                const currentText = viewTabBtn.innerHTML;
                                const newText = currentText.replace(/\(\d+\)/, `(${refreshData.total})`);
                                viewTabBtn.innerHTML = newText;
                                viewTabBtn.click();
                            }
                        })
                        .catch(err => {
                            console.error('Failed to refresh list:', err);
                            document.getElementById('viewTab').click();
                        });
                });
            } else {
                Swal.fire({
                    title: 'Error!',
                    text: data.msg,
                    icon: 'error',
                    confirmButtonColor: '#3b82f6'
                });
            }
        } catch (error) {
            Swal.fire({
                title: 'Error!',
                text: 'Failed to submit complaint. Please try again.',
                icon: 'error',
                confirmButtonColor: '#3b82f6'
            });
        } finally {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    });
});
</script>