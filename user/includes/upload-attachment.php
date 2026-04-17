<?php
include 'session_check.php';
session_name('CustomerSession');
session_start();
require '../db/db.php';

// Generate CSRF if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id']) || !isset($_GET['complaint_id'])) {
    header('Location: login.php');
    exit;
}
$user_id = $_SESSION['user_id'];
$complaint_id = (int)$_GET['complaint_id'];

// CSRF check (GET - optional but good practice)
if (isset($_GET['csrf']) && !hash_equals($_SESSION['csrf_token'], $_GET['csrf'])) {
    die("Security error. Please refresh and try again.");
}

// Verify ownership
$stmt = mysqli_prepare($conn, "SELECT * FROM complaints WHERE complaint_id = ? AND user_id = ?");
mysqli_stmt_bind_param($stmt, "ii", $complaint_id, $user_id);
mysqli_stmt_execute($stmt);
if (!mysqli_stmt_get_result($stmt)->num_rows) {
    mysqli_stmt_close($stmt);
    die("Invalid complaint. Access denied.");
}
mysqli_stmt_close($stmt);

// Upload directory
$uploadDir = __DIR__ . '/../uploads/attachments/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        error_log("Failed to create upload directory: $uploadDir");
        die("Server error. Contact admin.");
    }
}

$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
$allowedMimeTypes = [
    'image/jpeg', 'image/png', 'image/gif',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];
$maxSize = 5 * 1024 * 1024; // 5MB

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['attachment'])) {
    $file = $_FILES['attachment'];

    // CSRF check on POST
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Security error. Invalid token.']);
        exit;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form limit.',
            UPLOAD_ERR_PARTIAL => 'File only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by PHP extension.'
        ];
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $errors[$file['error']] ?? 'Upload error occurred.']);
        exit;
    }

    $originalName = basename($file['name']);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX only.']);
        exit;
    }

    if ($file['size'] > $maxSize) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File is too large. Maximum allowed size is 20MB.']);
        exit;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedMimeTypes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File content type is not allowed. Only images and documents permitted.']);
        exit;
    }

    // Secure filename
    $fileName = $complaint_id . '_' . time() . '_' . uniqid() . '.' . $extension;
    $filePath = $uploadDir . $fileName;
    $relativePath = 'uploads/attachments/' . $fileName;

    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        $stmt = mysqli_prepare($conn, "UPDATE complaints SET attachment_path = ? WHERE complaint_id = ? AND user_id = ?");
        mysqli_stmt_bind_param($stmt, "sii", $relativePath, $complaint_id, $user_id);

        if (mysqli_stmt_execute($stmt)) {
            echo json_encode([
                'success' => true,
                'message' => 'Attachment uploaded successfully!',
                'redirect' => "?complaint_id=$complaint_id"
            ]);
        } else {
            unlink($filePath);
            error_log("DB update failed: " . mysqli_error($conn));
            echo json_encode(['success' => false, 'message' => 'Failed to save attachment record. Try again.']);
        }
        mysqli_stmt_close($stmt);
    } else {
        error_log("Move uploaded file failed to: $filePath");
        echo json_encode(['success' => false, 'message' => 'Failed to save file. Check folder permissions.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Attachment - Complaint #<?php echo $complaint_id; ?></title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; margin:0; }
        .container { max-width: 460px; margin: 40px auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        h2 { color: #1e40af; margin-bottom: 24px; text-align: center; }
        .tip { font-size: 13px; color: #4b5563; margin: 8px 0 16px; }
        .error { color: #dc2626; font-size: 14px; margin-top: 8px; display: none; background: #fee2e2; padding: 10px; border-radius: 6px; }
        button { width: 100%; padding: 14px; background: #3b82f6; color: white; border: none; border-radius: 6px; font-size: 16px; cursor: pointer; }
        button:hover { background: #2563eb; }
        button:disabled { background: #93c5fd; cursor: not-allowed; }
        input[type="file"] { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; }
        .back { text-align: center; margin-top: 24px; }
        .back a { color: #3b82f6; text-decoration: none; font-weight: 500; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Upload Attachment<br>Complaint #<?php echo $complaint_id; ?></h2>

        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="file" name="attachment" id="attachmentInput" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx" required>
            <div class="tip">Allowed: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX  |  Max 20MB</div>
            <div id="errorMessage" class="error"></div>
            <button type="submit" id="submitBtn">Upload File</button>
        </form>

        <div class="back">
            <a href="chat.php">← Back to Chat</a>
        </div>
    </div>

    <script>
        const allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
        const maxSize = 20 * 1024 * 1024;
        const fileInput = document.getElementById('attachmentInput');
        const errorDiv = document.getElementById('errorMessage');
        const submitBtn = document.getElementById('submitBtn');
        const form = document.getElementById('uploadForm');

        function showError(msg) {
            errorDiv.textContent = msg;
            errorDiv.style.display = 'block';
            fileInput.value = '';
            submitBtn.disabled = true;
        }

        function clearError() {
            errorDiv.textContent = '';
            errorDiv.style.display = 'none';
            submitBtn.disabled = false;
        }

        fileInput.addEventListener('change', function() {
            clearError();
            const file = this.files[0];
            if (!file) return;

            const ext = file.name.split('.').pop().toLowerCase();
            if (!allowedExtensions.includes(ext)) {
                showError('Invalid file type. Only JPG, JPEG, PNG, GIF, PDF, DOC, DOCX allowed.');
                return;
            }

            if (file.size > maxSize) {
                showError(`File too large (${(file.size / 1024 / 1024).toFixed(1)} MB). Maximum is 20 MB.`);
                return;
            }

            // Optional extra MIME check
            if (file.type && !['image/jpeg','image/png','image/gif','application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'].includes(file.type)) {
                showError('File content does not match allowed document/image types.');
                return;
            }
        });

        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            clearError();

            if (!fileInput.files[0]) {
                showError('Please select a file first.');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Uploading...';

            const formData = new FormData(form);

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    alert(result.message);
                    window.location.href = result.redirect || 'chat.php';
                } else {
                    showError(result.message || 'Upload failed. Please try again.');
                }
            } catch (err) {
                showError('Network error. Please check your connection and try again.');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Upload File';
            }
        });
    </script>
</body>
</html>