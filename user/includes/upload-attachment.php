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

// CSRF check if provided (for security, but optional for now)
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

// Upload dir setup with better permissions
$uploadDir = __DIR__ . '/../uploads/attachments/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        error_log("Failed to create upload directory: $uploadDir");
        die("Server error. Contact admin.");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['attachment'])) {
    $file = $_FILES['attachment'];
    
    // CSRF check on POST (add hidden input in form)
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf'])) {
        die("Security error. Invalid token.");
    }
    
    // File validation
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File too large (server limit).',
            UPLOAD_ERR_FORM_SIZE => 'File too large (form limit).',
            UPLOAD_ERR_PARTIAL => 'Upload incomplete.',
            UPLOAD_ERR_NO_FILE => 'No file selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file.',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension.'
        ];
        die("Upload error: " . ($errors[$file['error']] ?? 'Unknown error'));
    }
    
    if ($file['size'] > $maxSize) {
        die("File too large. Maximum 5MB allowed.");
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        die("Invalid file type. Allowed: JPG, PNG, GIF, PDF, DOC, DOCX.");
    }
    
    // Secure filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = $complaint_id . '_' . time() . '_' . uniqid() . '.' . $extension;
    $filePath = $uploadDir . $fileName;
    $relativePath = 'uploads/attachments/' . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        // Update DB with error handling
        $updateStmt = mysqli_prepare($conn, "UPDATE complaints SET attachment_path = ? WHERE complaint_id = ? AND user_id = ?");
        if (!$updateStmt) {
            unlink($filePath); // Cleanup on failure
            error_log("Prepare failed: " . mysqli_error($conn));
            die("Database error. Contact admin.");
        }
        mysqli_stmt_bind_param($updateStmt, "sii", $relativePath, $complaint_id, $user_id);
        if (mysqli_stmt_execute($updateStmt)) {
            mysqli_stmt_close($updateStmt);
            echo "<div style='padding: 20px; background: #d4edda; border: 1px solid #c3e6cb; color: #155724; border-radius: 5px; margin: 10px 0;'>
                    <strong>Success!</strong> Attachment uploaded for Complaint #$complaint_id.
                    <br><a href='?complaint_id=$complaint_id' style='color: #007bff; text-decoration: underline;'>Upload another</a> | 
                    <a href='chat.php' style='color: #007bff; text-decoration: underline;'>Back to Chat</a>
                  </div>";
        } else {
            unlink($filePath); // Cleanup
            error_log("Execute failed: " . mysqli_error($conn));
            echo "<div style='padding: 20px; background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; border-radius: 5px; margin: 10px 0;'>
                    <strong>Error:</strong> Failed to save in database. Try again.
                  </div>";
        }
    } else {
        error_log("Move failed: From " . $file['tmp_name'] . " to $filePath");
        echo "<div style='padding: 20px; background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; border-radius: 5px; margin: 10px 0;'>
                <strong>Error:</strong> Upload failed. Check file permissions.
              </div>";
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
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 400px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h2 { color: #333; margin-bottom: 20px; }
        input[type="file"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 10px; }
        button { width: 100%; padding: 12px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #0056b3; }
        .tip { font-size: 12px; color: #666; margin-top: 5px; }
        .back { text-align: center; margin-top: 20px; }
        .back a { color: #007bff; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Upload Attachment for Complaint #<?php echo $complaint_id; ?></h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="file" name="attachment" accept="image/*,.pdf,.doc,.docx" required>
            <div class="tip">Max 5MB. Allowed: JPG, PNG, GIF, PDF, DOC, DOCX</div>
            <button type="submit">Upload File</button>
        </form>
        <div class="back">
            <a href="chat.php">Back to Chat</a>
        </div>
    </div>
</body>
</html>