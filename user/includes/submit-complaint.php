<?php
// includes/submit-complaint.php
?>
                        <div class="form-card">
                            <div class="full-form-header">
                                <h3 class="text-xl font-bold text-gray-900 mb-1">Submit a Complaint</h3>
                                <p class="text-sm text-gray-600">Help us resolve your concern quickly.</p>
                            </div>
                            <form method="POST" enctype="multipart/form-data" class="full-form-grid">
                                <input type="hidden" name="action" value="create">
                                <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                                <div class="form-group">
                                    <label class="form-label">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                          <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                                        </svg>
                                        Category
                                    </label>
                                    <select name="category" class="form-select" required>
                                        <option value="" disabled selected>Select category</option>
                                        <?php foreach ($ALLOWED_CATEGORIES as $cat): ?>
                                            <option value="<?php echo e($cat); ?>"><?php echo e($cat); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                          <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" />
                                        </svg>
                                        Attachment (Optional)
                                    </label>
                                    <input type="file" name="attachment" class="form-input" accept="image/*,.pdf,.doc,.docx">
                                    <div class="form-tip">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3">
                                          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                                        </svg>
                                        JPG, PNG, GIF, PDF, DOC, DOCX (max 5MB)
                                    </div>
                                </div>
                                <div class="form-group full-form-submit">
                                    <label class="form-label">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                          <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
                                        </svg>
                                        Description
                                    </label>
                                    <textarea name="description" class="form-textarea" placeholder="Describe your concern in detail..." minlength="10" required></textarea>
                                </div>
                                <div class="form-group full-form-submit submit-section">
                                    <button type="submit" class="btn-primary w-full text-white px-4 py-2 rounded-xl font-semibold">
                                        <i class="fas fa-paper-plane mr-2"></i>
                                        Submit
                                    </button>
                                </div>
                            </form>
                        </div>