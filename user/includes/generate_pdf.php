<?php
// includes/generate_pdf.php
// Generates a PDF for a specific complaint using TCPDF

// Include database connection
include '../../db/db.php'; // Adjust if db.php is in a different location

session_start();

// Session guard
$timeout_duration = 1800;
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php?message=Please log in to access complaints.");
    exit();
}
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: ../login.php?message=Session expired, please log in again.");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

// Utility: safe output
function e($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }

// Check for complaint_id
if (!isset($_GET['complaint_id']) || !is_numeric($_GET['complaint_id'])) {
    header("Location: ../complaints.php?error=Invalid complaint ID.&tab=view");
    exit();
}
$complaint_id = (int)$_GET['complaint_id'];
$user_id = $_SESSION['user_id'];

// Verify database connection
if (!isset($conn) || !$conn) {
    header("Location: ../complaints.php?error=Database connection failed. Please try again later.&tab=view");
    exit();
}

// Fetch complaint details
$sql = "
    SELECT c.complaint_id, c.category, c.description, c.status, c.sentiment, c.action_due, c.created_at, c.updated_at, c.attachment_path,
           s.name AS staff_name, s.role AS staff_role
    FROM complaints c
    LEFT JOIN (
        SELECT ca1.*
        FROM complaint_assignments ca1
        JOIN (
            SELECT complaint_id, MAX(id) AS max_id
            FROM complaint_assignments
            GROUP BY complaint_id
        ) latest ON latest.max_id = ca1.id
    ) ca ON ca.complaint_id = c.complaint_id
    LEFT JOIN staff s ON s.staff_id = ca.staff_id
    WHERE c.complaint_id = ? AND c.user_id = ?
";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    header("Location: ../complaints.php?error=Failed to prepare database query.&tab=view");
    exit();
}
mysqli_stmt_bind_param($stmt, "ii", $complaint_id, $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$complaint = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$complaint) {
    header("Location: ../complaints.php?error=Complaint not found or you do not have access.&tab=view");
    exit();
}

// Include TCPDF library
$tcpdf_path = '../../tcpdf/tcpdf.php'; // Manual installation path
// For Composer: $tcpdf_path = '../../../vendor/tecnickcom/tcpdf/tcpdf.php';
if (!file_exists($tcpdf_path)) {
    header("Location: ../complaints.php?error=TCPDF library not found. Please contact support.&tab=view");
    exit();
}
require_once($tcpdf_path);

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('CWD AquaSense');
$pdf->SetTitle('Complaint #' . $complaint['complaint_id']);
$pdf->SetSubject('Complaint Details');
$pdf->SetKeywords('Complaint, CWD, AquaSense');

// Set default header data
$pdf->SetHeaderData('', 0, 'CWD AquaSense', 'Complaint Details #' . $complaint['complaint_id']);

// Set header and footer fonts
$pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
$pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

// Set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// Set margins
$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
$pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

// Set image scale factor
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// Set font
$pdf->SetFont('helvetica', '', 12);

// Add a page
$pdf->AddPage();

// Prepare content
$html = '
<h1 style="text-align: center;">Complaint #' . e($complaint['complaint_id']) . '</h1>
<table border="0" cellpadding="4" style="width: 100%;">
    <tr>
        <td style="width: 30%; font-weight: bold;">Category:</td>
        <td style="width: 70%;">' . e($complaint['category']) . '</td>
    </tr>
    <tr>
        <td style="font-weight: bold;">Status:</td>
        <td>' . e($complaint['status']) . '</td>
    </tr>
    <tr>
        <td style="font-weight: bold;">Sentiment:</td>
        <td>' . e($complaint['sentiment'] ?? 'N/A') . '</td>
    </tr>
    <tr>
        <td style="font-weight: bold;">Assigned Staff:</td>
        <td>' . e($complaint['staff_name'] ? $complaint['staff_name'] . ($complaint['staff_role'] ? ' (' . $complaint['staff_role'] . ')' : '') : 'Unassigned') . '</td>
    </tr>
    <tr>
        <td style="font-weight: bold;">Action Due:</td>
        <td>' . e($complaint['action_due'] ? date('M d, Y', strtotime($complaint['action_due'])) : 'N/A') . '</td>
    </tr>
    <tr>
        <td style="font-weight: bold;">Created:</td>
        <td>' . e(date('M d, Y h:i A', strtotime($complaint['created_at']))) . '</td>
    </tr>
    <tr>
        <td style="font-weight: bold;">Updated:</td>
        <td>' . e(date('M d, Y h:i A', strtotime($complaint['updated_at']))) . '</td>
    </tr>';
if ($complaint['attachment_path']) {
    $html .= '
    <tr>
        <td style="font-weight: bold;">Attachment:</td>
        <td>' . e($complaint['attachment_path']) . '</td>
    </tr>';
}
$html .= '
</table>
<h3 style="margin-top: 20px;">Description</h3>
<p style="border: 1px solid #e5e7eb; padding: 10px; border-radius: 5px;">' . e($complaint['description']) . '</p>
';

// Write HTML content
$pdf->writeHTML($html, true, false, true, false, '');

// Close and output PDF document
$pdf->Output('complaint_' . $complaint['complaint_id'] . '_' . date('Ymd_His') . '.pdf', 'D');

// Clean up
mysqli_close($conn);
?>