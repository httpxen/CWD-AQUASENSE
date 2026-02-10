<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING); // Suppress notices/warnings for testing (remove in prod)
ini_set('display_errors', 0); // Don't display errors to output
ob_start(); // Start output buffering to catch stray output
include 'session_check.php'; // Assumes this exists now
require __DIR__ . '/../vendor/autoload.php';
require '../db/db.php';
use Dotenv\Dotenv;
date_default_timezone_set('Asia/Manila');
// === HEADERS ===
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");
// === LOAD ENV ===
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
$apiKey = $_ENV['OPENAI_API_KEY'] ?? null;
if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error: Missing API Key']);
    exit;
}
$user_id = $_SESSION['user_id'];
global $conn;
// === DYNAMIC GREETING ===
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Magandang umaga' : ($hour < 18 ? 'Magandang hapon' : 'Magandang gabi');
$engGreeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
// === DATABASE HELPERS ===
function getCurrentBoard($conn) {
    $query = "SELECT pos.title AS position, p.name
              FROM position_assignments pa
              JOIN positions pos ON pa.position_id = pos.position_id
              JOIN people p ON pa.person_id = p.person_id
              WHERE pos.category = 'board' AND pa.is_current = 1
              ORDER BY pos.order_index";
    $result = mysqli_query($conn, $query);
    if (!$result) return ['title' => 'Board of Directors - Calamba Water District', 'members' => []]; // Fallback on error
    $members = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $members[] = ['position' => $row['position'], 'name' => $row['name']];
    }
    return ['title' => 'Board of Directors - Calamba Water District', 'members' => $members];
}
function getCurrentManagement($conn) {
    $gmQuery = "SELECT p.name FROM position_assignments pa
                JOIN positions pos ON pa.position_id = pos.position_id
                JOIN people p ON pa.person_id = p.person_id
                WHERE pos.title = 'General Manager' AND pa.is_current = 1";
    $gmResult = mysqli_query($conn, $gmQuery);
    $gmRow = mysqli_fetch_assoc($gmResult ?? []);
    $general_manager = ['title' => 'General Manager', 'name' => $gmRow['name'] ?? 'Vacant'];
    $deptQuery = "SELECT pos.department AS dept, p.name
                  FROM position_assignments pa
                  JOIN positions pos ON pa.position_id = pos.position_id
                  JOIN people p ON pa.person_id = p.person_id
                  WHERE pos.title = 'Department Manager' AND pa.is_current = 1
                  ORDER BY pos.order_index";
    $deptResult = mysqli_query($conn, $deptQuery);
    $deptList = [];
    while ($row = mysqli_fetch_assoc($deptResult ?? [])) {
        $deptList[] = ['dept' => $row['dept'], 'name' => $row['name']];
    }
    $divQuery = "SELECT pos.department, pos.division, p.name, pos.title
                 FROM position_assignments pa
                 JOIN positions pos ON pa.position_id = pos.position_id
                 JOIN people p ON pa.person_id = p.person_id
                 WHERE pos.category = 'management' AND pos.title != 'General Manager'
                   AND pos.title != 'Department Manager' AND pa.is_current = 1
                 ORDER BY pos.department, pos.order_index";
    $divResult = mysqli_query($conn, $divQuery);
    $divisions = [];
    while ($row = mysqli_fetch_assoc($divResult ?? [])) {
        $dept = $row['department'];
        if (!isset($divisions[$dept])) $divisions[$dept] = [];
        $divisions[$dept][] = [
            'division' => $row['title'] === 'OIC - Billing and Meter Reading' ? $row['title'] : $row['division'],
            'name' => $row['name']
        ];
    }
    return [
        'general_manager' => $general_manager,
        'department_managers' => ['title' => 'Department Managers', 'list' => $deptList],
        'division_managers' => $divisions
    ];
}
function getPastGeneralManagers($conn) {
    $query = "SELECT p.name, pa.start_date, pa.end_date
              FROM position_assignments pa
              JOIN positions pos ON pa.position_id = pos.position_id
              JOIN people p ON pa.person_id = p.person_id
              WHERE pos.title = 'General Manager' AND pa.is_current = 0
              ORDER BY pa.start_date DESC";
    $result = mysqli_query($conn, $query);
    if (!$result) return [];
    $past = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $past[] = $row;
    }
    return $past;
}
function getFullOrgMap($conn) {
    $orgQuery = "SELECT pos.title AS position_title, pos.category, pos.department, pos.division, pos.order_index,
                        p.name AS person_name, pa.start_date, pa.end_date, pa.is_current
                 FROM position_assignments pa
                 JOIN positions pos ON pa.position_id = pos.position_id
                 JOIN people p ON pa.person_id = p.person_id
                 WHERE pa.is_current = 1
                 ORDER BY
                   CASE WHEN pos.category = 'board' THEN 1 ELSE 2 END,
                   pos.order_index,
                   FIELD(pos.department, 'Administrative', 'Finance', 'Commercial', 'Technical Services', 'Operations'),
                   FIELD(pos.division, 'Human Resource', 'Property & Materials Management', 'General Services', 'General Accounting', 'Budget', 'Customer Accounts', 'Customer Care', 'Pipeline and Appurtenance Maintenance', 'Production'),
                   pos.position_id";
    $orgResult = mysqli_query($conn, $orgQuery);
    if (!$orgResult) return ['board' => [], 'management' => []];
  
    $fullOrgMap = ['board' => [], 'management' => []];
    while ($row = mysqli_fetch_assoc($orgResult)) {
        $cat = $row['category'];
        $key = strtolower(str_replace([' ', '-', '&'], [' ', ' ', 'and'], $row['position_title']));
        $deptOrDiv = !empty($row['department']) ? strtolower(str_replace(' ', '_', $row['department'])) :
                     (!empty($row['division']) ? strtolower(str_replace(' ', '_', $row['division'])) : $key);
        $fullOrgMap[$cat][$deptOrDiv][] = [
            'title' => ucwords(str_replace('_', ' ', $row['position_title'])),
            'name' => $row['person_name'],
            'department' => $row['department'] ?? null,
            'division' => $row['division'] ?? null
        ];
    }
    return $fullOrgMap;
}
function getStaticContent($conn) {
    $staticQuery = "SELECT content_key, title, content FROM static_content WHERE language = 'en'";
    $staticResult = mysqli_query($conn, $staticQuery);
    if (!$staticResult) return [];
    $staticMap = [];
    while ($row = mysqli_fetch_assoc($staticResult)) {
        $staticMap[strtolower($row['content_key'])] = [
            'title' => $row['title'],
            'content' => nl2br($row['content']) // Convert \n to <br> for HTML display
        ];
    }
    return $staticMap;
}
function getPublicStats($conn) {
    // Total complaints (public stat)
    $totalComplaintsQuery = "SELECT COUNT(*) as total FROM complaints";
    $totalComplaintsResult = mysqli_query($conn, $totalComplaintsQuery);
    $totalComplaints = mysqli_fetch_assoc($totalComplaintsResult)['total'] ?? 0;
    // Resolved complaints
    $resolvedComplaintsQuery = "SELECT COUNT(*) as resolved FROM complaints WHERE status = 'Resolved' OR status = 'Closed'";
    $resolvedComplaintsResult = mysqli_query($conn, $resolvedComplaintsQuery);
    $resolvedComplaints = mysqli_fetch_assoc($resolvedComplaintsResult)['resolved'] ?? 0;
    // Average resolution time (days, simplified)
    $avgResolutionQuery = "SELECT AVG(DATEDIFF(resolved_at, created_at)) as avg_days FROM complaints WHERE resolved_at IS NOT NULL";
    $avgResolutionResult = mysqli_query($conn, $avgResolutionQuery);
    $avgResolutionDays = round(mysqli_fetch_assoc($avgResolutionResult)['avg_days'] ?? 0, 1);
    // Feedback sentiment summary
    $feedbackQuery = "SELECT
        SUM(CASE WHEN sentiment = 'positive' THEN 1 ELSE 0 END) as positive,
        SUM(CASE WHEN sentiment = 'negative' THEN 1 ELSE 0 END) as negative,
        SUM(CASE WHEN sentiment = 'neutral' THEN 1 ELSE 0 END) as neutral,
        COUNT(*) as total_feedback
      FROM feedback";
    $feedbackResult = mysqli_query($conn, $feedbackQuery);
    $feedbackStats = mysqli_fetch_assoc($feedbackResult) ?? [
        'positive' => 0, 'negative' => 0, 'neutral' => 0, 'total_feedback' => 0
    ];
    return [
        'totalComplaints' => $totalComplaints,
        'resolvedComplaints' => $resolvedComplaints,
        'avgResolutionDays' => $avgResolutionDays,
        'feedbackStats' => $feedbackStats
    ];
}
// === LOAD FROM DATABASE (with session cache) ===
if (!isset($_SESSION['board_data']) || !isset($_SESSION['management_data'])) {
    $_SESSION['board_data'] = getCurrentBoard($conn);
    $_SESSION['management_data'] = getCurrentManagement($conn);
}
$boardData = $_SESSION['board_data'];
$managementData = $_SESSION['management_data'];
$fullOrgMap = getFullOrgMap($conn);
$staticMap = getStaticContent($conn);
$publicStats = getPublicStats($conn);
// Build board HTML (simple list design)
$boardHtml = "<p>Narito ang <strong>Board of Directors</strong>:</p><p style=\"margin:12px 0; line-height:1.8;\">";
if (!empty($fullOrgMap['board'])) {
    foreach ($fullOrgMap['board'] as $section => $positions) {
        if (!empty($positions)) {
            $boardHtml .= "<strong>".ucwords(str_replace('_', ' ', $section)).":</strong><br>";
            foreach ($positions as $pos) {
                $boardHtml .= "<strong>{$pos['title']}:</strong> <span style=\"color:#2563eb;\">{$pos['name']}</span><br>";
            }
            $boardHtml .= "<br>";
        }
    }
} else {
    $boardHtml .= "No current board members listed.<br>";
}
$boardHtml .= "</p>";
// Build management HTML (simple list design)
$managementHtml = "<p>Narito ang <strong>Management Team</strong>:</p><p style=\"margin:12px 0; line-height:1.8;\">";
if (!empty($fullOrgMap['management'])) {
    // Find GM first
    $gmFound = false;
    foreach ($fullOrgMap['management'] as $section => $positions) {
        foreach ($positions as $pos) {
            if (stripos($pos['title'], 'General Manager') !== false) {
                $managementHtml .= "<strong>General Manager:</strong> <span style=\"color:#2563eb;\">{$pos['name']}</span><br><br>";
                $gmFound = true;
                break 2;
            }
        }
    }
    if (!$gmFound) {
        $managementHtml .= "<strong>General Manager:</strong> <span style=\"color:#2563eb;\">Vacant</span><br><br>";
    }
    // Group by main departments
    $deptGroups = [
        'Administrative' => ['administrative'],
        'Finance' => ['finance'],
        'Commercial' => ['commercial'],
        'Technical Services' => ['technical_services'],
        'Operations' => ['operations']
    ];
    foreach ($deptGroups as $deptName => $keys) {
        $found = false;
        foreach ($keys as $key) {
            if (isset($fullOrgMap['management'][$key])) {
                $managementHtml .= "<strong>$deptName Department:</strong><br>";
                foreach ($fullOrgMap['management'][$key] as $pos) {
                    $subDept = $pos['division'] ? " ({$pos['division']})" : '';
                    $managementHtml .= "<strong>{$pos['title']}{$subDept}:</strong> <span style=\"color:#2563eb;\">{$pos['name']}</span><br>";
                }
                $managementHtml .= "<br>";
                $found = true;
            }
        }
        if (!$found) {
            $managementHtml .= "<strong>$deptName Department:</strong> No current managers listed.<br><br>";
        }
    }
    // Handle any uncategorized (e.g., OIC)
    foreach ($fullOrgMap['management'] as $section => $positions) {
        if (!in_array($section, ['general_manager', 'administrative', 'finance', 'commercial', 'technical_services', 'operations'])) {
            $managementHtml .= "<strong>Other (".ucwords(str_replace('_', ' ', $section))."):</strong><br>";
            foreach ($positions as $pos) {
                $managementHtml .= "<strong>{$pos['title']}:</strong> <span style=\"color:#2563eb;\">{$pos['name']}</span><br>";
            }
            $managementHtml .= "<br>";
        }
    }
} else {
    $managementHtml .= "No current management team listed.<br>";
}
$managementHtml .= "</p>";
// Build static HTML (simple)
$staticHtml = '';
foreach ($staticMap as $key => $data) {
    $staticHtml .= "<strong>{$data['title']}:</strong> {$data['content']}<br><br>";
}
// Build stats HTML (simple list)
$statsHtml = "<p>Narito ang ilang <strong>public statistics</strong>:</p><p style=\"margin:12px 0; line-height:1.8;\">
                Total Complaints: <strong>{$publicStats['totalComplaints']}</strong><br>
                Resolved: <strong>{$publicStats['resolvedComplaints']}</strong><br>
                Avg Resolution Time: <strong>{$publicStats['avgResolutionDays']} days</strong><br><br>
                Feedback: Positive <strong>{$publicStats['feedbackStats']['positive']}</strong> | Negative <strong>{$publicStats['feedbackStats']['negative']}</strong> | Neutral <strong>{$publicStats['feedbackStats']['neutral']}</strong> (Total: {$publicStats['feedbackStats']['total_feedback']})
              </p>";
// === ALLOWED CATEGORIES ===
$ALLOWED_CATEGORIES = [
    'Billing', 'Water quality', 'Service interruption', 'Meter / Leakage',
    'New Connection / Disconnection', 'Customer Service', 'Others'
];
$categoriesList = implode(', ', $ALLOWED_CATEGORIES);
$categoriesHtml = "<div class='bg-gradient-to-r from-blue-50 to-indigo-50 rounded-2xl border border-blue-200/50 p-6 shadow-sm mb-4'>
    <div class='flex items-start gap-3 mb-3'>
        <i class='fas fa-info-circle text-blue-500 mt-0.5 flex-shrink-0'></i>
        <div>
            <p class='font-semibold text-blue-800 text-lg mb-1'>Ano ang kategorya ng iyong reklamo?</p>
            <p class='text-sm text-blue-600'>Pumili mula sa mga sumusunod:</p>
        </div>
    </div>
    <div class='grid grid-cols-1 md:grid-cols-2 gap-2 text-sm'>
        " . implode('', array_map(fn($cat) => "<span class='inline-flex items-center px-3 py-1 bg-blue-100 text-blue-700 rounded-full'>$cat</span>", $ALLOWED_CATEGORIES)) . "
    </div>
    <p class='text-xs text-gray-600 mt-3 italic'>Pakibigay ang kategorya (e.g., 'Billing') at ilarawan ang iyong concern (hindi bababa sa 10 karakter).</p>
</div>";
// === HELPER: EXTRACT JSON ACTION ===
function extractJsonAction($text) {
    $pattern = '/\{[^{}]*+(?:(?=[^{}]*+\{)[^{}]*+\}[^{}]*+)*+\}/';
    if (preg_match($pattern, $text, $matches)) {
        $jsonStr = $matches[0];
        $decoded = json_decode($jsonStr, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }
    return null;
}
// === HELPER: STATUS BADGE ===
function getStatusBadge($status) {
    $status = strtolower(str_replace(' ', '_', $status));
    $colors = [
        'pending' => ['bg-yellow-100 text-yellow-800 border-yellow-200', 'fa-clock', 'Pending'],
        'in_progress' => ['bg-blue-100 text-blue-800 border-blue-200', 'fa-cogs', 'In Progress'],
        'resolved' => ['bg-green-100 text-green-800 border-green-200', 'fa-check-circle', 'Resolved'],
        'closed' => ['bg-gray-100 text-gray-800 border-gray-200', 'fa-lock', 'Closed']
    ];
    $color = $colors[$status] ?? ['bg-gray-100 text-gray-800 border-gray-200', 'fa-question-circle', 'Unknown'];
    return "<span class='inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-medium {$color[0]} border'>
                <i class='fas {$color[1]}'></i> {$color[2]}
            </span>";
}
// === HELPER: GET ASSIGNED STAFF ===
function getAssignedStaff($complaint_id, $conn) {
    $query = "SELECT s.name FROM complaint_assignments ca
              JOIN staff s ON ca.staff_id = s.staff_id
              WHERE ca.complaint_id = ?
              ORDER BY ca.assigned_at DESC LIMIT 1";
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $complaint_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($result)) {
            mysqli_stmt_close($stmt);
            return $row['name'];
        }
        mysqli_stmt_close($stmt);
    }
    return null;
}
// === HELPER: BUILD COMPLAINT CARD ===
function buildComplaintCard($row, $label = null) {
    global $conn;
    $filed = date('F j, Y \a\t g:i A', strtotime($row['created_at']));
    $resolved = $row['resolved_at'] ? date('F j, Y \a\t g:i A', strtotime($row['resolved_at'])) : null;
    $statusBadge = getStatusBadge($row['status']);
    $assignedStaff = getAssignedStaff($row['complaint_id'], $conn);
    $assignedText = $assignedStaff
        ? "<div class='flex items-center gap-2'><i class='fas fa-user-tie text-blue-500'></i><span class='text-blue-700 font-medium'>{$assignedStaff}</span></div>"
        : "<div class='flex items-center gap-2 text-gray-500'><i class='fas fa-user-slash'></i><em>Not yet assigned</em></div>";
    $headerLabel = $label ? "<span class='ml-2 text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded-full'>$label</span>" : '';
    $attachmentLink = $row['attachment_path'] ? "<div class='flex items-center gap-2'><i class='fas fa-paperclip text-gray-400'></i><a href='{$row['attachment_path']}' class='text-blue-600 hover:text-blue-800 underline text-sm' target='_blank'>View File</a></div>" : '';
    return "
    <div class='bg-white border border-gray-200 rounded-2xl p-6 shadow-sm hover:shadow-md transition-shadow mb-4 relative overflow-hidden'>
        <div class='absolute top-0 right-0 w-2 h-full bg-gradient-to-b from-blue-500 to-indigo-500'></div>
        <div class='complaint-header flex justify-between items-start mb-4 relative z-10'>
            <div class='flex items-center gap-3'>
                <div class='bg-gradient-to-br from-blue-500 to-indigo-500 text-white p-3 rounded-xl flex-shrink-0'>
                    <i class='fas fa-exclamation-triangle text-lg'></i>
                </div>
                <div>
                    <h3 class='text-xl font-bold text-gray-900'>Complaint #{$row['complaint_id']}</h3>
                    {$headerLabel}
                </div>
            </div>
            <div class='flex-shrink-0'>{$statusBadge}</div>
        </div>
        <div class='grid grid-cols-1 md:grid-cols-2 gap-4 mb-4 relative z-10'>
            <div class='space-y-2'>
                <div class='flex items-center gap-2'><i class='fas fa-tag text-purple-500'></i><span class='font-semibold text-gray-700'>Category:</span> <span class='text-purple-600'>{$row['category']}</span></div>
                <div class='flex items-center gap-2'><i class='fas fa-calendar text-gray-500'></i><span class='font-semibold text-gray-700'>Filed:</span> <span class='text-gray-600'>{$filed}</span></div>
                {$assignedText}
            </div>
            <div class='space-y-2'>
                " . ($resolved ? "<div class='flex items-center gap-2'><i class='fas fa-check text-green-500'></i><span class='font-semibold text-gray-700'>Resolved:</span> <span class='text-gray-600'>{$resolved}</span></div>" : "") . "
                {$attachmentLink}
            </div>
        </div>
        <div class='bg-gray-50 rounded-xl p-4 mt-4 relative z-10'>
            <h4 class='font-semibold text-gray-800 mb-2 flex items-center gap-2'><i class='fas fa-align-left text-gray-500'></i>Description:</h4>
            <p class='text-gray-700 italic leading-relaxed'>“{$row['description']}”</p>
        </div>
    </div>";
}
// === HELPER: FIND CLOSEST CATEGORY ===
function findClosestCategory($inputCategory, $allowed) {
    $inputLower = strtolower(trim($inputCategory));
    foreach ($allowed as $cat) {
        $catLower = strtolower($cat);
        if (stripos($catLower, $inputLower) !== false || stripos($inputLower, $catLower) !== false) {
            return $cat;
        }
    }
    return null;
}
// === MAIN LOGIC ===
try {
    // === PARSE INPUT ===
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['messages']) || !is_array($input['messages']) || empty($input['messages'])) {
        throw new Exception('No messages provided');
    }
    $lastUserMessage = end($input['messages']);
    $userText = strtolower($lastUserMessage['content'] ?? '');
    $userLower = mb_strtolower($userText, 'UTF-8');
    // === LANGUAGE DETECTION (Enhanced with OpenAI) ===
    $client = \OpenAI::client($apiKey); // Initialize client here for detection
    $detectPrompt = [
        'role' => 'system',
        'content' => 'Detect the primary language of the following user message. Respond with ONLY "en" for English (or dominant English), "tl" for Tagalog/Filipino (or dominant Tagalog), or "mixed" if truly balanced. Be accurate and concise—no explanations.'
    ];
    $detectMessages = [
        $detectPrompt,
        $lastUserMessage
    ];
    $detectResult = $client->chat()->create([
        'model' => 'gpt-4o-mini',
        'messages' => $detectMessages,
        'max_tokens' => 5,
        'temperature' => 0.1,
    ]);
    $langResponse = trim($detectResult->choices[0]->message->content ?? 'tl');
    $isEnglish = ($langResponse === 'en');
    $lang = $isEnglish ? 'en' : 'tl';
    if ($langResponse === 'mixed') {
        // Fallback to keyword-based for mixed
        $tagalogWords = ['paano', 'ano', 'sino', 'kailan', 'saan', 'bakit', 'magandang', 'umaga', 'hapon', 'gabi', 'salamat', 'po', 'ho', 'reklamo', 'presyo', 'aplay', 'bayad', 'tubig', 'halaga', 'koneksyon', 'serbisyo'];
        $englishWords = ['who','what','general','manager','chairperson','secretary','treasurer','department','head','bill','account','register','create','sign up','where','location','office','address','statistics','stats','feedback','complaints','history','mission','vision','values'];
        $tagalogCount = 0;
        $englishCount = 0;
        foreach ($tagalogWords as $word) {
            if (strpos($userLower, $word) !== false) $tagalogCount++;
        }
        foreach ($englishWords as $word) {
            if (strpos($userLower, $word) !== false) $englishCount++;
        }
        $isEnglish = ($englishCount > $tagalogCount && $englishCount > 2);
        $lang = $isEnglish ? 'en' : 'tl';
    }
    // === KEYWORDS ===
    $boardKeywords = ['chairman', 'chairperson', 'board', 'directors', 'bod', 'chair'];
    $mgmtKeywords = ['gm', 'general manager', 'manager', 'head', 'department', 'division'];
    $pastGmKeywords = ['past', 'previous', 'dating', 'former', 'ex', 'lumang'];
    $currentGmKeywords = ['current', 'present', 'kasalukuyan', 'ngayon'];
    $isBoardQuery = preg_match('/\b(' . implode('|', $boardKeywords) . ')\b/', $userLower);
    $isMgmtQuery = preg_match('/\b(' . implode('|', $mgmtKeywords) . ')\b/', $userLower);
    $isPastGmQuery = preg_match('/\b(' . implode('|', $pastGmKeywords) . ')\b/', $userLower) && preg_match('/\bgm\b/', $userLower);
    $isCurrentGmQuery = preg_match('/\b(' . implode('|', $currentGmKeywords) . ')\b/', $userLower) && preg_match('/\bgm\b/', $userLower);
    // === CITIZEN'S CHARTER PATTERNS ===
    $charterPatterns = [
        'estimate' => '/\b(estimate|halaga|application for estimate|filing of application for estimate|humiling ng estimate|proseso ng estimate)\b/iu',
        'connection' => '/\b(payment of application for new water service connection)\b/iu',
        'complaint' => '/\b(filing of complaint or request|proseso ng pag-file ng complaint|paano mag-file ng reklamo|citizen\'s charter complaint)\b/iu',
        'disconnection' => '/\b(disconnection|disconnect|request for disconnection|humiling ng pagputol|filing of request for disconnection)\b/iu',
        'ledger' => '/\b(account ledger|ledger|request for account ledger|kopya ng ledger|filing of request for a copy of account ledger)\b/iu',
        'payment' => '/\b(payment of water bill|bayad ng tubig|water bill|magbayad|payment of water bill)\b/iu',
        'name-change' => '/\b(change of name|pagbabago ng pangalan|request for change of name|filing of request for change of name)\b/iu',
        'bulk-sale' => '/\b(bulk sale|bulk water|payment of bulk sale|bumili ng bulk tubig)\b/iu',
        'ground-water' => '/\b(ground water assessment|groundwater|payment of ground water assessment|bayad ng groundwater)\b/iu',
        'new-water-connection' => '/\b(application for new water connection|aplay ng bagong water connection|new water connection application|new water connection|bagong koneksyon|aplay ng bagong tubig|new connection)\b/iu',
        'reconnection' => '/\b(reconnection|reconnect|procedures of re-connection|humiling ng reconnection)\b/iu',
        'water-analysis' => '/\b(water analysis|water test|analysis ng tubig|water analysis)\b/iu',
        // NEW: Water Rates Pattern
        'water-rates' => '/\b(water rates|presyo ng tubig|rate|tariff|singil|billing rate|water bill rates|presyo ng water|rate ng tubig|effective july 2010)\b/iu',
        // NEW: Violations and Penalties Pattern
        'violations-penalties' => '/\b(violations|penalties|multa|parusa|violation|penalty|tampering|illegal|offenses|reklamo sa paglabag|schedule of violations and penalties)\b/iu'
    ];
    $matchedService = null;
    foreach ($charterPatterns as $slug => $pattern) {
        if (preg_match($pattern, $userLower)) {
            $matchedService = $slug;
            break;
        }
    }
    // === OPENAI CALL ===
    $systemPrompt = [
        'role' => 'system',
        'content' => "You are Kuya Daloy, a friendly assistant for Calamba Water District. Respond in Filipino, English, or Taglish based on user language. Be natural, helpful, and concise. Use the full conversation history for context.
=== STRICT RULES ===
- DO NOT INVENT names or categories.
- Categories MUST be EXACTLY one of: $categoriesList. Suggest based on description if unclear, but always confirm.
- For questions about:
  → Chairman, Chairperson, Board, Directors → OUTPUT ONLY: {\"type\":\"get_board_of_directors\"}
  → GM, Manager, Department, Division, Head → OUTPUT ONLY: {\"type\":\"get_management_team\"}
  → Past GM, dating GM → {\"type\":\"get_past_gm\"}
  → Current GM, kasalukuyang GM → {\"type\":\"get_current_gm\"}
  → Full Org Chart, structure → {\"type\":\"get_full_org_chart\"}
  → Stats, statistics → {\"type\":\"get_public_stats\"}
  → History, kasaysayan → {\"type\":\"get_history\"}
  → Mission, layunin → {\"type\":\"get_mission\"}
  → Vision, paningin → {\"type\":\"get_vision\"}
  → Core Values, halaga → {\"type\":\"get_core_values\"}
  → Quality Policy, patakaran sa kalidad → {\"type\":\"get_quality_policy\"}
- DO NOT GIVE names outside JSON actions.
- If unsure: Say \"Pasensya na, hindi ko mahanap ngayon. Pwede mong i-check sa aming official website <a href='https://cwd.com.ph/' target='_blank'>Calamba Water District</a> para sa higit pang impormasyon.\"
=== INTENT DETECTION (USE HISTORY - NO REPEATS/LOOPS) ===
- FIRST TIME complaint intent ('complaint', 'reklamo', 'create complaint', 'mag-file', 'problem', 'issue', 'concern' AND no prior complaint flow): {\"type\":\"ask_category\"}
- AFTER ask_category (check history):
  - If ONLY category (e.g., 'Billing') WITHOUT description: {\"type\":\"ask_description\", \"category\":\"EXACT MATCH\"}
  - If category + CLEAR description (separate, >=10 chars, not category repeat, e.g., 'Billing - Overcharge sa bill ko dahil sa wrong reading'): {\"type\":\"confirm_complaint\", \"category\":\"EXACT MATCH\", \"description\":\"EXTRACT DESCRIPTION ONLY (clean, no category if redundant)\"}
  - If unclear: {\"type\":\"ask_category\"} with brief reminder.
- AFTER ask_description: If description >=10 chars & distinct: {\"type\":\"confirm_complaint\", \"category\":\"[PREVIOUS]\", \"description\":\"...\"}
- AFTER confirm_complaint: 'yes/oo/sige' → {\"type\":\"file_complaint\", \"category\":\"[PREVIOUS]\", \"description\":\"[PREVIOUS]\"}; 'no/hindi/change' → {\"type\":\"ask_category\"}
- For status check: '#ID', 'status', 'latest', 'all' → {\"type\":\"get_complaint_details\", \"complaint_id\":ID or null for latest/all}
- FIRST TIME feedback intent ('feedback', 'suggestion', 'magbigay ng feedback', 'maganda/sama' AND no prior feedback flow): {\"type\":\"ask_feedback\"}
- AFTER ask_feedback: If text >=10 chars: {\"type\":\"confirm_feedback\", \"text\":\"EXTRACTED TEXT\", \"sentiment\":\"positive/negative/neutral\"}
- AFTER confirm_feedback: 'yes/oo' → {\"type\":\"file_feedback\", \"text\":\"[PREVIOUS]\", \"sentiment\":\"[PREVIOUS]\"}; 'no' → {\"type\":\"ask_feedback\"}
- To view feedback: 'show feedback', 'my feedback', 'ipakita feedback' → {\"type\":\"get_feedback_history\", \"count\":5}
- For menu/help: 'help', 'ano pwede', 'menu' → {\"type\":\"show_menu\"}
- For login/register: 'login', 'register', 'sign up' → {\"type\":\"login_guide\"}
- For office location: 'address', 'location', 'office' → {\"type\":\"get_office_location\"}
- If no specific intent, respond with helpful text (no JSON).
=== JSON OUTPUT ONLY FOR ACTIONS ===
Output ONLY valid JSON for actions, NO EXTRA TEXT. For general responses, output plain text.
Examples:
{\"type\":\"ask_category\"}
{\"type\":\"ask_description\", \"category\":\"Billing\"}
{\"type\":\"confirm_complaint\", \"category\":\"Billing\", \"description\":\"Overcharge sa bill ko... (min 10 chars)\"}
{\"type\":\"file_complaint\", \"category\":\"Billing\", \"description\":\"...\"}
{\"type\":\"ask_feedback\"}
{\"type\":\"confirm_feedback\", \"text\":\"Maganda ang service... (min 10 chars)\", \"sentiment\":\"positive\"}
{\"type\":\"file_feedback\", \"text\":\"...\", \"sentiment\":\"positive\"}
{\"type\":\"get_feedback_history\", \"count\":5}
{\"type\":\"show_menu\"}
{\"type\":\"login_guide\"}
{\"type\":\"get_board_of_directors\"}
{\"type\":\"get_management_team\"}
{\"type\":\"get_current_gm\"}
{\"type\":\"get_past_gm\"}
{\"type\":\"get_full_org_chart\"}
{\"type\":\"get_public_stats\"}
{\"type\":\"get_history\"}
{\"type\":\"get_mission\"}
{\"type\":\"get_vision\"}
{\"type\":\"get_core_values\"}
{\"type\":\"get_quality_policy\"}
{\"type\":\"get_office_location\"}
Be context-aware: Recall from history. Avoid loops by strict checks on text length/uniqueness.
Full Org Structure: $boardHtml $managementHtml
Public Stats: $statsHtml
Static Info: $staticHtml"
    ];
    $messages = array_merge([$systemPrompt], $input['messages']);
    $result = $client->chat()->create([
        'model' => 'gpt-4o-mini',
        'messages' => $messages,
        'max_tokens' => 700,
        'temperature' => 0.8,
    ]);
    $rawResponse = $result->choices[0]->message->content ?? '';
    $decoded = extractJsonAction($rawResponse);
    $response = $rawResponse;
    // === ANTI-HALLUCINATION OVERRIDE ===
    if ($isBoardQuery && (!is_array($decoded) || $decoded['type'] !== 'get_board_of_directors')) {
        $decoded = ['type' => 'get_board_of_directors'];
    }
    if ($isMgmtQuery && (!is_array($decoded) || $decoded['type'] !== 'get_management_team')) {
        $decoded = ['type' => 'get_management_team'];
    }
    if ($isPastGmQuery && (!is_array($decoded) || $decoded['type'] !== 'get_past_gm')) {
        $decoded = ['type' => 'get_past_gm'];
    }
    if ($isCurrentGmQuery && (!is_array($decoded) || $decoded['type'] !== 'get_current_gm')) {
        $decoded = ['type' => 'get_current_gm'];
    }
    // === PROCESS CITIZEN'S CHARTER (before JSON actions) ===
    if ($matchedService) {
        // Special handling for Water Rates (fetch fees from service_id=6 directly)
        if ($matchedService === 'water-rates') {
            $serviceId = 6; // Payment of Water Bill service_id
            $serviceTitle = $isEnglish ? 'Schedule of Water Rates (Effective July 2010)' : 'Mga Rate ng Tubig (Epektibo Hulyo 2010)';
            // Fetch Fees only (rates)
            $feeQuery = "SELECT fee_category, particular, amount FROM service_fees WHERE service_id = ? AND fee_category LIKE '%List of Formula%' ORDER BY fee_category, id";
            $feeStmt = mysqli_prepare($conn, $feeQuery);
            mysqli_stmt_bind_param($feeStmt, 'i', $serviceId);
            mysqli_stmt_execute($feeStmt);
            $feeResult = mysqli_stmt_get_result($feeStmt);
            $fees = [];
            while ($row = mysqli_fetch_assoc($feeResult)) {
                $fees[] = $row;
            }
            // Build Rates Tables (separate for Category A and B) - Tailwind classes
            $charterHtml = "<h3 class='text-lg font-bold text-gray-800 mb-2'>$serviceTitle</h3>";
            if (!empty($fees)) {
                $charterHtml .= "<p class='text-gray-600 italic mb-4'>Official Document - Calamba Water District. <a href='https://cwd.com.ph/water_rates.html' target='_blank' class='text-blue-600 hover:text-blue-800 underline'>View Official Rates on CWD Website</a><br>Note: These are historical rates. Check CWD office for current updates.</p>";
                // Group fees by category and meter size
                $groupedFees = ['Category A: Service Areas (Residential / Government)' => [], 'Category B: Service Areas (NHA, VLP, VPB, Major Homes)' => []];
                foreach ($fees as $fee) {
                    $cat = strpos($fee['fee_category'], 'NHA') !== false ? 'Category B: Service Areas (NHA, VLP, VPB, Major Homes)' : 'Category A: Service Areas (Residential / Government)';
                    $particular = $fee['particular'];
                    // Better regex to handle '1 1/2"' and '1/2"'
                    if (preg_match('/^((?:\d+\s+)?\d+(?:\/\d+)?")?\s*(.*)$/u', $particular, $matches)) {
                        $meter = $matches[1] ?? '';
                        $desc = trim($matches[2]);
                        if ($meter) { // Only if meter extracted
                            $groupedFees[$cat][$meter][$desc] = $fee['amount'];
                        }
                    }
                }
                // Meters order - with space for 1 1/2"
                $meters = ['1/2"', '3/4"', '1"', '1 1/2"', '2"'];
                foreach ($groupedFees as $category => $metersData) {
                    $charterHtml .= "<div class='mb-6'><h4 class='text-base font-semibold text-blue-800 mb-2'>$category</h4>";
                    $charterHtml .= "<div class='bg-gray-50 rounded-lg p-3 overflow-x-auto'>";
                    $charterHtml .= "<table class='min-w-full divide-y divide-gray-200'>";
                    $charterHtml .= "<thead><tr><th class='px-3 py-2 text-left text-xs font-medium text-gray-500'>Meter Size</th><th class='px-3 py-2 text-right text-xs font-medium text-gray-500'>Min Charge (1-10 cu.m.)</th><th class='px-3 py-2 text-right text-xs font-medium text-gray-500'>11-20 cu.m. (per cu.m.)</th><th class='px-3 py-2 text-right text-xs font-medium text-gray-500'>21-30 cu.m. (per cu.m.)</th><th class='px-3 py-2 text-right text-xs font-medium text-gray-500'>31-40 cu.m. (per cu.m.)</th><th class='px-3 py-2 text-right text-xs font-medium text-gray-500'>41+ cu.m. (per cu.m.)</th></tr></thead><tbody class='bg-white divide-y divide-gray-200'>";
                    foreach ($meters as $meter) {
                        if (isset($metersData[$meter])) {
                            $row = "<tr><td class='px-3 py-2 text-sm text-gray-900 font-medium'>" . htmlspecialchars($meter) . "</td>";
                            $row .= "<td class='px-3 py-2 text-sm text-gray-900 text-right'>₱" . number_format($metersData[$meter]['Minimum Charge (1-10 m³)'] ?? 0, 2) . "</td>";
                            $row .= "<td class='px-3 py-2 text-sm text-gray-900 text-right'>₱" . number_format($metersData[$meter]['11-20 m³ (per m³)'] ?? 0, 2) . "</td>";
                            $row .= "<td class='px-3 py-2 text-sm text-gray-900 text-right'>₱" . number_format($metersData[$meter]['21-30 m³ (per m³)'] ?? 0, 2) . "</td>";
                            $row .= "<td class='px-3 py-2 text-sm text-gray-900 text-right'>₱" . number_format($metersData[$meter]['31-40 m³ (per m³)'] ?? 0, 2) . "</td>";
                            $row .= "<td class='px-3 py-2 text-sm text-gray-900 text-right'>₱" . number_format($metersData[$meter]['41+ m³ (per m³)'] ?? 0, 2) . "</td></tr>";
                            $charterHtml .= $row;
                        }
                    }
                    $charterHtml .= "</tbody></table></div><p class='text-xs text-gray-500 mt-1 text-right'>* All figures in Philippine Peso (PHP)</p></div>";
                }
            } else {
                $charterHtml .= "<p class='text-gray-600'>No rates data available right now. Please visit the CWD office for the latest.</p>";
            }
            // Disclaimer
            $disclaimer = "<div class='bg-yellow-50 border-l-4 border-yellow-400 p-4 mt-4 rounded-r-lg'><p class='text-sm text-yellow-800 mb-1'><strong>DISCLAIMER:</strong> These rates are subject to adjustments based on national government mandates. For official billing concerns, please coordinate with the <strong>Calamba Water District Billing Department</strong>.</p><p class='text-sm text-yellow-700'>Last verified: As per official CWD website (July 2010 rates still in effect).</p></div>";
            $engResponse = "$engGreeting! Here's the details for the <strong>Citizen's Charter: Water Rates</strong>:<br><br>$charterHtml$disclaimer<br><br>Need help with anything else?";
            $tlResponse = "$greeting! Narito ang detalye para sa <strong>Citizen's Charter: Mga Rate ng Tubig</strong>:<br><br>$charterHtml$disclaimer<br><br>Kailangan mo ba ng tulong sa iba pa?";
            $response = "<div class='bg-white rounded-2xl border border-blue-200 p-6 shadow-sm'>" . ($isEnglish ? $engResponse : $tlResponse) . "</div>";
            echo json_encode(['response' => $response], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            exit;
        }
        // Special handling for Violations and Penalties (fetch from violations_penalties table)
        if ($matchedService === 'violations-penalties') {
            // Fetch service details first to get service_id
            $serviceQuery = "SELECT * FROM citizen_charter_services WHERE slug = ?";
            $stmt = mysqli_prepare($conn, $serviceQuery);
            mysqli_stmt_bind_param($stmt, 's', $matchedService);
            mysqli_stmt_execute($stmt);
            $serviceResult = mysqli_stmt_get_result($stmt);
            $service = mysqli_fetch_assoc($serviceResult);
            if (!$service) {
                $response = "<div class='bg-red-50 rounded-2xl border border-red-200 p-6 shadow-sm'><p class='text-red-800'>Service not found. Please check the official website.</p></div>";
                echo json_encode(['response' => $response], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                exit;
            }
            $serviceTitle = $isEnglish ? 'Violations and Penalties (As per Calamba Water District Policies)' : 'Mga Paglabag at Parusa (Ayonsa sa Patakaran ng Calamba Water District)';
            // Fetch Penalties
            $penaltyQuery = "SELECT offense, sub_offense, residential_1st, residential_2nd, residential_3rd, commercial_1st, commercial_2nd, commercial_3rd, notes FROM violations_penalties WHERE service_id = ? ORDER BY id";
            $penaltyStmt = mysqli_prepare($conn, $penaltyQuery);
            mysqli_stmt_bind_param($penaltyStmt, 'i', $service['id']);
            mysqli_stmt_execute($penaltyStmt);
            $penaltyResult = mysqli_stmt_get_result($penaltyStmt);
            $penalties = [];
            while ($row = mysqli_fetch_assoc($penaltyResult)) {
                $penalties[] = $row;
            }
            // Build Penalties Table - Tailwind classes
            $charterHtml = "<h3 class='text-lg font-bold text-gray-800 mb-2'>$serviceTitle</h3>";
            if (!empty($penalties)) {
                $charterHtml .= "<p class='text-gray-600 italic mb-4'>Official Document - Calamba Water District. <a href='https://cwd.com.ph/water_rates.html' target='_blank' class='text-blue-600 hover:text-blue-800 underline'>View Official Policies on CWD Website</a></p>";
                $charterHtml .= "<div class='bg-gray-50 rounded-lg p-3 mb-4 overflow-x-auto'>";
                $charterHtml .= "<table class='min-w-full divide-y divide-gray-200'>";
                $charterHtml .= "<thead><tr><th class='px-3 py-2 text-left text-xs font-medium text-gray-500'>Offenses</th><th colspan='3' class='px-3 py-2 text-center text-xs font-medium text-gray-500'>Residential</th><th colspan='3' class='px-3 py-2 text-center text-xs font-medium text-gray-500'>Commercial</th></tr>";
                $charterHtml .= "<tr class='bg-gray-100'><th class='px-3 py-2 text-left text-xs font-medium text-gray-500'></th><th class='px-3 py-2 text-center text-xs font-medium text-gray-500'>1st</th><th class='px-3 py-2 text-center text-xs font-medium text-gray-500'>2nd</th><th class='px-3 py-2 text-center text-xs font-medium text-gray-500'>3rd</th><th class='px-3 py-2 text-center text-xs font-medium text-gray-500'>1st</th><th class='px-3 py-2 text-center text-xs font-medium text-gray-500'>2nd</th><th class='px-3 py-2 text-center text-xs font-medium text-gray-500'>3rd</th></tr></thead><tbody class='bg-white divide-y divide-gray-200'>";
                $currentMainOffense = '';
                foreach ($penalties as $penalty) {
                    $fullOffense = $penalty['sub_offense'] ? $penalty['offense'] . ': ' . $penalty['sub_offense'] : $penalty['offense'];
                    if ($fullOffense !== $currentMainOffense) {
                        if ($currentMainOffense && $penalty['sub_offense']) {
                            $charterHtml .= "<tr class='bg-red-50'><td colspan='7' class='px-3 py-2 text-center text-sm text-red-600 italic'>Meter Tampering</td></tr>";
                        }
                        $charterHtml .= "<tr><th class='px-3 py-2 text-sm text-gray-900 font-medium text-left'>" . htmlspecialchars($fullOffense) . "</th>";
                        $charterHtml .= "<td class='px-3 py-2 text-sm text-gray-900 text-right'>₱" . number_format($penalty['residential_1st'], 2) . "</td>";
                        $charterHtml .= "<td class='px-3 py-2 text-sm text-gray-900 text-right'>₱" . number_format($penalty['residential_2nd'], 2) . "</td>";
                        $charterHtml .= "<td class='px-3 py-2 text-sm text-gray-900 text-right'>₱" . number_format($penalty['residential_3rd'], 2) . "</td>";
                        $charterHtml .= "<td class='px-3 py-2 text-sm text-gray-900 text-right'>₱" . number_format($penalty['commercial_1st'], 2) . "</td>";
                        $charterHtml .= "<td class='px-3 py-2 text-sm text-gray-900 text-right'>₱" . number_format($penalty['commercial_2nd'], 2) . "</td>";
                        $charterHtml .= "<td class='px-3 py-2 text-sm text-gray-900 text-right'>₱" . number_format($penalty['commercial_3rd'], 2) . "</td></tr>";
                        if ($penalty['notes']) {
                            $charterHtml .= "<tr><td colspan='7' class='px-3 py-2 text-center text-sm text-green-600 italic'>" . htmlspecialchars($penalty['notes']) . "</td></tr>";
                        }
                        $currentMainOffense = $fullOffense;
                    }
                }
                $charterHtml .= "</tbody></table></div><p class='text-xs text-gray-500 mt-1 text-right'>* All figures in Philippine Peso (PHP)</p>";
            } else {
                $charterHtml .= "<p class='text-gray-600'>No penalties data available right now. Please visit the CWD office for the latest.</p>";
            }
            // Disclaimer
            $disclaimer = "<div class='bg-yellow-50 border-l-4 border-yellow-400 p-4 mt-4 rounded-r-lg'><p class='text-sm text-yellow-800 mb-1'><strong>DISCLAIMER:</strong> These penalties are subject to enforcement policies and may be adjusted based on national government mandates. For official inquiries or concerns, please coordinate with the <strong>Calamba Water District Enforcement Department</strong>.</p><p class='text-sm text-yellow-700'>Last verified: As per official CWD website.</p></div>";
            $engResponse = "$engGreeting! Here's the details for <strong>Violations and Penalties</strong>:<br><br>$charterHtml$disclaimer<br><br>Need help with anything else?";
            $tlResponse = "$greeting! Narito ang detalye para sa <strong>Mga Paglabag at Parusa</strong>:<br><br>$charterHtml$disclaimer<br><br>Kailangan mo ba ng tulong sa iba pa?";
            $response = "<div class='bg-white rounded-2xl border border-blue-200 p-6 shadow-sm'>" . ($isEnglish ? $engResponse : $tlResponse) . "</div>";
            echo json_encode(['response' => $response], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            exit;
        }
        // Fetch service details
        $serviceQuery = "SELECT * FROM citizen_charter_services WHERE slug = ?";
        $stmt = mysqli_prepare($conn, $serviceQuery);
        mysqli_stmt_bind_param($stmt, 's', $matchedService);
        mysqli_stmt_execute($stmt);
        $serviceResult = mysqli_stmt_get_result($stmt);
        $service = mysqli_fetch_assoc($serviceResult);
        if ($service) {
            // Fetch Requirements
            $reqQuery = "SELECT section_title, requirement_text FROM service_requirements WHERE service_id = ? ORDER BY id";
            $reqStmt = mysqli_prepare($conn, $reqQuery);
            mysqli_stmt_bind_param($reqStmt, 'i', $service['id']);
            mysqli_stmt_execute($reqStmt);
            $reqResult = mysqli_stmt_get_result($reqStmt);
            $requirements = [];
            while ($row = mysqli_fetch_assoc($reqResult)) {
                $requirements[] = $row;
            }
            // Fetch Procedures
            $procQuery = "SELECT step_number, description, processing_time, fee, responsible, location FROM service_procedures WHERE service_id = ? ORDER BY step_number";
            $procStmt = mysqli_prepare($conn, $procQuery);
            mysqli_stmt_bind_param($procStmt, 'i', $service['id']);
            mysqli_stmt_execute($procStmt);
            $procResult = mysqli_stmt_get_result($procStmt);
            $procedures = [];
            while ($row = mysqli_fetch_assoc($procResult)) {
                $procedures[] = $row;
            }
            // Fetch Fees
            $feeQuery = "SELECT fee_category, particular, amount FROM service_fees WHERE service_id = ? ORDER BY id";
            $feeStmt = mysqli_prepare($conn, $feeQuery);
            mysqli_stmt_bind_param($feeStmt, 'i', $service['id']);
            mysqli_stmt_execute($feeStmt);
            $feeResult = mysqli_stmt_get_result($feeStmt);
            $fees = [];
            while ($row = mysqli_fetch_assoc($feeResult)) {
                $fees[] = $row;
            }
            // Fetch Remarks
            $remarkQuery = "SELECT remark FROM service_remarks WHERE service_id = ? ORDER BY id";
            $remarkStmt = mysqli_prepare($conn, $remarkQuery);
            mysqli_stmt_bind_param($remarkStmt, 'i', $service['id']);
            mysqli_stmt_execute($remarkStmt);
            $remarkResult = mysqli_stmt_get_result($remarkStmt);
            $remarks = [];
            while ($row = mysqli_fetch_assoc($remarkResult)) {
                $remarks[] = $row['remark'];
            }
            // Build formatted HTML response (adapt to Tailwind-ish classes)
            $charterHtml = "<h3 class='text-lg font-bold text-gray-800 mb-2'>" . htmlspecialchars($service['main_title']) . "</h3>";
            if ($service['subtitle']) $charterHtml .= "<p class='text-gray-600 italic mb-4'>" . htmlspecialchars($service['subtitle']) . "</p>";
            // Requirements Table (simple HTML table with Tailwind)
            if (!empty($requirements)) {
                $charterHtml .= "<p class='font-semibold text-gray-700 mb-2'>Requirements:</p><div class='bg-gray-50 rounded-lg p-3 mb-4 overflow-x-auto'>";
                $charterHtml .= "<table class='min-w-full divide-y divide-gray-200'>";
                $charterHtml .= "<thead><tr><th class='px-3 py-2 text-left text-xs font-medium text-gray-500'>Item</th></tr></thead><tbody class='bg-white divide-y divide-gray-200'>";
                foreach ($requirements as $req) {
                    $section = $req['section_title'] ? "<strong>" . htmlspecialchars($req['section_title']) . ":</strong><br>" : '';
                    $charterHtml .= "<tr><td class='px-3 py-2 text-sm text-gray-900'>" . $section . htmlspecialchars($req['requirement_text']) . "</td></tr>";
                }
                $charterHtml .= "</tbody></table></div>";
            }
            // Procedures Table
            if (!empty($procedures)) {
                $charterHtml .= "<p class='font-semibold text-gray-700 mb-2'>Procedure:</p><div class='bg-gray-50 rounded-lg p-3 mb-4 overflow-x-auto'>";
                $charterHtml .= "<table class='min-w-full divide-y divide-gray-200'>";
                $charterHtml .= "<thead><tr><th class='px-3 py-2 text-left text-xs font-medium text-gray-500'>Step</th><th class='px-3 py-2 text-left text-xs font-medium text-gray-500'>Description</th><th class='px-3 py-2 text-center text-xs font-medium text-gray-500'>Time/Fee</th><th class='px-3 py-2 text-left text-xs font-medium text-gray-500'>Responsible</th><th class='px-3 py-2 text-left text-xs font-medium text-gray-500'>Location</th></tr></thead><tbody class='bg-white divide-y divide-gray-200'>";
                foreach ($procedures as $proc) {
                    $timeFee = ($proc['processing_time'] ? htmlspecialchars($proc['processing_time']) : '') . ($proc['fee'] ? '<br>₱' . number_format($proc['fee'], 2) : '');
                    $charterHtml .= "<tr><td class='px-3 py-2 text-sm text-gray-900'>" . htmlspecialchars($proc['step_number']) . "</td><td class='px-3 py-2 text-sm text-gray-900'>" . htmlspecialchars($proc['description']) . "</td><td class='px-3 py-2 text-center text-sm text-gray-900'>$timeFee</td><td class='px-3 py-2 text-sm text-gray-900'>" . htmlspecialchars($proc['responsible']) . "</td><td class='px-3 py-2 text-sm text-gray-900'>" . htmlspecialchars($proc['location']) . "</td></tr>";
                }
                if ($service['total_time']) $charterHtml .= "<tr class='bg-gray-100'><td colspan='2' class='px-3 py-2 font-semibold'>Total Time:</td><td class='px-3 py-2 font-semibold'>" . htmlspecialchars($service['total_time']) . "</td><td colspan='2'></td></tr>";
                if ($service['total_fee']) $charterHtml .= "<tr class='bg-gray-100'><td colspan='2' class='px-3 py-2 font-semibold'>Total Fee:</td><td class='px-3 py-2 font-semibold'>₱" . number_format($service['total_fee'], 2) . "</td><td colspan='2'></td></tr>";
                $charterHtml .= "</tbody></table></div>";
            }
            // Fees Table (if any)
            if (!empty($fees)) {
                $charterHtml .= "<p class='font-semibold text-gray-700 mb-2'>Fees:</p><div class='bg-gray-50 rounded-lg p-3 mb-4 overflow-x-auto'>";
                $charterHtml .= "<table class='min-w-full divide-y divide-gray-200'>";
                $charterHtml .= "<thead><tr><th class='px-3 py-2 text-left text-xs font-medium text-gray-500'>Category</th><th class='px-3 py-2 text-left text-xs font-medium text-gray-500'>Particular</th><th class='px-3 py-2 text-right text-xs font-medium text-gray-500'>Amount (₱)</th></tr></thead><tbody class='bg-white divide-y divide-gray-200'>";
                foreach ($fees as $fee) {
                    $charterHtml .= "<tr><td class='px-3 py-2 text-sm text-gray-900'>" . ($fee['fee_category'] ? htmlspecialchars($fee['fee_category']) : '') . "</td><td class='px-3 py-2 text-sm text-gray-900'>" . htmlspecialchars($fee['particular']) . "</td><td class='px-3 py-2 text-right text-sm text-gray-900'>" . ($fee['amount'] ? number_format($fee['amount'], 2) : 'Varies') . "</td></tr>";
                }
                $charterHtml .= "</tbody></table></div>";
            }
            // Remarks
            if (!empty($remarks)) {
                $charterHtml .= "<p class='font-semibold text-gray-700 mb-2'>Remarks:</p><ul class='list-disc pl-5 mb-4 space-y-1'>";
                foreach ($remarks as $remark) {
                    $charterHtml .= "<li class='text-sm text-gray-900'>" . htmlspecialchars($remark) . "</li>";
                }
                $charterHtml .= "</ul>";
            }
            $engResponse = "$engGreeting! Here's the details for the <strong>Citizen's Charter: " . htmlspecialchars($service['sidebar_title']) . "</strong>:<br><br>$charterHtml<br><br>Need help with anything else?";
            $tlResponse = "$greeting! Narito ang detalye para sa <strong>Citizen's Charter: " . htmlspecialchars($service['sidebar_title']) . "</strong>:<br><br>$charterHtml<br><br>Kailangan mo ba ng tulong sa iba pa?";
            $response = "<div class='bg-white rounded-2xl border border-blue-200 p-6 shadow-sm'>" . ($isEnglish ? $engResponse : $tlResponse) . "</div>";
            echo json_encode(['response' => $response], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            exit;
        }
    }
    // === PROCESS JSON ACTION ===
    if (is_array($decoded) && isset($decoded['type'])) {
        switch ($decoded['type']) {
            case 'ask_category':
                $response = "<div class='bg-gradient-to-r from-blue-50 to-indigo-50 rounded-2xl border border-blue-200/50 p-6 shadow-sm'>
                    <div class='flex items-start gap-3 mb-4'>
                        <div class='bg-gradient-to-br from-blue-500 to-indigo-500 text-white p-3 rounded-xl flex-shrink-0'>
                            <i class='fas fa-question-circle text-lg'></i>
                        </div>
                        <div>
                            <h3 class='text-lg font-bold text-blue-800 mb-2'>Sige! Tutulungan kita mag file ng inquiries/complaints!</h3>
                            <p class='text-sm text-blue-700'>Pumili ng kategorya mula sa listahan:</p>
                        </div>
                    </div>
                    <div class='grid grid-cols-1 md:grid-cols-2 gap-3 mb-4'>
                        " . implode('', array_map(fn($cat) => "<span class='inline-flex items-center px-4 py-2 bg-white text-blue-700 rounded-lg shadow-sm border border-blue-200 hover:shadow-md transition-shadow'>$cat</span>", $ALLOWED_CATEGORIES)) . "
                    </div>
                    <p class='text-xs text-gray-600 italic bg-white p-3 rounded-lg border border-gray-100'>Halimbawa: <em class='text-blue-600'>'Billing - Overcharge sa bill ko'</em></p>
                </div>";
                break;
            case 'ask_description':
                if (isset($decoded['category'])) {
                    $category = trim($decoded['category']);
                    if (!in_array($category, $ALLOWED_CATEGORIES)) {
                        $closest = findClosestCategory($category, $ALLOWED_CATEGORIES);
                        if (!$closest) {
                            $response = "<div class='bg-gradient-to-r from-red-50 to-rose-50 rounded-2xl border border-red-200/50 p-6 shadow-sm'>
                                <div class='flex items-start gap-3 mb-3'>
                                    <div class='bg-red-500 text-white p-3 rounded-xl flex-shrink-0'>
                                        <i class='fas fa-exclamation-triangle'></i>
                                    </div>
                                    <div>
                                        <p class='font-semibold text-red-800 text-lg'>Hindi valid na category: <strong>{$category}</strong></p>
                                        <p class='text-sm text-red-700'>Pumili mula sa list: $categoriesList</p>
                                    </div>
                                </div>
                            </div>";
                            break;
                        }
                        $category = $closest;
                    }
                    $response = "<div class='bg-gradient-to-r from-yellow-50 to-amber-50 rounded-2xl border border-yellow-200/50 p-6 shadow-sm'>
                        <div class='flex items-start gap-3 mb-4'>
                            <div class='bg-yellow-500 text-white p-3 rounded-xl flex-shrink-0'>
                                <i class='fas fa-edit text-lg'></i>
                            </div>
                            <div>
                                <h3 class='text-lg font-bold text-yellow-800 mb-2'>Category: <strong>{$category}</strong></h3>
                                <p class='text-sm text-yellow-700'>Pakilarawan ang detalye ng iyong concern (min. 10 karakter):</p>
                            </div>
                        </div>
                        <div class='bg-white p-4 rounded-xl border border-yellow-100'>
                            <p class='text-xs text-gray-600 italic'>Halimbawa: <em>'May tumutulo sa metro ko sa bahay, hindi accurate ang reading.'</em></p>
                        </div>
                    </div>";
                } else {
                    $response = "<div class='bg-gradient-to-r from-red-50 to-rose-50 rounded-2xl border border-red-200/50 p-6 shadow-sm'>
                        <div class='flex items-start gap-3 mb-3'>
                            <i class='fas fa-exclamation-circle text-red-500 mt-0.5 flex-shrink-0'></i>
                            <p class='text-red-800 font-semibold'>Hindi ko nakuha ang category. Subukan ulit.</p>
                        </div>
                    </div>" . $categoriesHtml;
                }
                break;
            case 'confirm_complaint':
                if (isset($decoded['category'], $decoded['description'])) {
                    $description = trim($decoded['description']);
                    $category = trim($decoded['category']);
                  
                    if (strlen($description) < 10 || trim(strip_tags($description)) === '' || strtolower(trim($description)) === strtolower($category)) {
                        $response = "<div class='bg-gradient-to-r from-yellow-50 to-amber-50 rounded-2xl border border-yellow-200/50 p-6 shadow-sm'>
                            <div class='flex items-start gap-3 mb-4'>
                                <div class='bg-yellow-500 text-white p-3 rounded-xl flex-shrink-0'>
                                    <i class='fas fa-exclamation-triangle'></i>
                                </div>
                                <div>
                                    <h3 class='text-lg font-bold text-yellow-800'>Kulang pa ang description mo!</h3>
                                    <p class='text-sm text-yellow-700 mt-1'>Pakilarawan nang mas detalyado (min. 10 karakter).</p>
                                </div>
                            </div>
                        </div>";
                        break;
                    }
                  
                    if (!in_array($category, $ALLOWED_CATEGORIES)) {
                        $closest = findClosestCategory($category, $ALLOWED_CATEGORIES);
                        if (!$closest) {
                            $response = "<div class='bg-gradient-to-r from-red-50 to-rose-50 rounded-2xl border border-red-200/50 p-6 shadow-sm'>
                                <div class='flex items-start gap-3 mb-3'>
                                    <div class='bg-red-500 text-white p-3 rounded-xl flex-shrink-0'>
                                        <i class='fas fa-times'></i>
                                    </div>
                                    <div>
                                        <p class='text-red-800 font-semibold text-lg'>Hindi valid na category: <strong>{$category}</strong></p>
                                        <p class='text-sm text-red-700'>Pumili mula sa list: $categoriesList</p>
                                    </div>
                                </div>
                            </div>";
                            break;
                        }
                        $decoded['category'] = $closest;
                        $category = $closest;
                    }
                    $response = "<div class='bg-gradient-to-r from-green-50 to-emerald-50 rounded-2xl border border-green-200/50 p-6 shadow-sm'>
                        <div class='flex items-start gap-3 mb-4'>
                            <div class='bg-green-500 text-white p-3 rounded-xl flex-shrink-0'>
                                <i class='fas fa-check-circle text-lg'></i>
                            </div>
                            <h3 class='text-lg font-bold text-green-800 mb-2'>Kumpirmasyon ng Complaint</h3>
                        </div>
                        <div class='space-y-3 mb-4'>
                            <div class='bg-white p-4 rounded-xl border border-green-100'>
                                <div class='flex items-center gap-2 mb-2'><i class='fas fa-tag text-purple-500'></i><strong>Category:</strong></div>
                                <span class='text-purple-600 font-medium'>“{$category}”</span>
                            </div>
                            <div class='bg-white p-4 rounded-xl border border-green-100'>
                                <div class='flex items-center gap-2 mb-2'><i class='fas fa-align-left text-blue-500'></i><strong>Description:</strong></div>
                                <p class='text-blue-700 text-sm italic'>“{$description}”</p>
                            </div>
                        </div>
                        <div class='bg-white p-4 rounded-xl border border-green-100 text-center'>
                            <p class='font-semibold text-green-800 text-lg'>I-file ba ito?</p>
                            <p class='text-sm text-gray-600 mt-1'>Reply <strong>Yes</strong> o <strong>No/Change</strong></p>
                        </div>
                    </div>";
                } else {
                    $response = "<div class='bg-gradient-to-r from-red-50 to-rose-50 rounded-2xl border border-red-200/50 p-6 shadow-sm'>
                        <div class='flex items-center gap-3 mb-3'>
                            <i class='fas fa-exclamation-circle text-red-500 flex-shrink-0'></i>
                            <p class='text-red-800 font-semibold'>Hindi kumpleto ang detalye. Subukan ulit.</p>
                        </div>
                    </div>";
                }
                break;
            case 'file_complaint':
                if (isset($decoded['category'], $decoded['description'])) {
                    $category = trim($decoded['category']);
                    $description = trim($decoded['description']);
                    if (!in_array($category, $ALLOWED_CATEGORIES)) {
                        $closest = findClosestCategory($category, $ALLOWED_CATEGORIES);
                        if (!$closest) {
                            $response = "<div class='bg-gradient-to-r from-red-50 to-rose-50 rounded-2xl border border-red-200/50 p-6 shadow-sm'>
                                <div class='flex items-center justify-center gap-3 mb-3'>
                                    <i class='fas fa-times-circle text-red-500 text-2xl'></i>
                                    <div>
                                        <p class='text-red-800 font-semibold text-lg'>Invalid category</p>
                                        <p class='text-sm text-red-700'>Hindi nai-save ang complaint.</p>
                                    </div>
                                </div>
                            </div>";
                            break;
                        }
                        $category = $closest;
                    }
                    if (strlen($description) < 10) {
                        $response = "<div class='bg-gradient-to-r from-red-50 to-rose-50 rounded-2xl border border-red-200/50 p-6 shadow-sm'>
                            <div class='flex items-center justify-center gap-3 mb-3'>
                                <i class='fas fa-exclamation-triangle text-red-500 text-2xl'></i>
                                <div>
                                    <p class='text-red-800 font-semibold text-lg'>Maikli ang description</p>
                                    <p class='text-sm text-red-700'>Hindi nai-save ang complaint.</p>
                                </div>
                            </div>
                        </div>";
                        break;
                    }
                    $query = "INSERT INTO complaints (user_id, category, description, status) VALUES (?, ?, ?, 'Pending')";
                    $stmt = mysqli_prepare($conn, $query);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "iss", $user_id, $category, $description);
                        if (mysqli_stmt_execute($stmt)) {
                            $complaint_id = mysqli_insert_id($conn);
                            $response = "<div class='bg-gradient-to-r from-green-50 to-emerald-50 rounded-2xl border border-green-200/50 p-6 shadow-sm text-center'>
                                <div class='flex flex-col items-center gap-3 mb-4'>
                                    <div class='bg-green-500 text-white p-4 rounded-2xl flex-shrink-0'>
                                        <i class='fas fa-check-circle text-3xl'></i>
                                    </div>
                                    <div>
                                        <h3 class='text-2xl font-bold text-green-800 mb-1'>Nai-file na ang complaint!</h3>
                                        <p class='text-sm text-green-700'>Salamat sa pag-report. Tutulungan ka namin agad.</p>
                                    </div>
                                </div>
                                <div class='bg-white p-4 rounded-xl border border-green-100'>
                                    <p class='text-lg font-bold text-green-600'>ID: <span class='text-2xl'>#{$complaint_id}</span></p>
                                    <p class='text-xs text-gray-600 mt-1'>I-save mo ito para sa status check mamaya.</p>
                                </div>
                            </div>";
                            error_log("Complaint #$complaint_id filed by user $user_id");
                        } else {
                            $response = "<div class='bg-gradient-to-r from-red-50 to-rose-50 rounded-2xl border border-red-200/50 p-6 shadow-sm'>
                                <div class='flex items-center justify-center gap-3 mb-3'>
                                    <i class='fas fa-exclamation-triangle text-red-500 text-2xl'></i>
                                    <div>
                                        <p class='text-red-800 font-semibold text-lg'>Error sa pag-save</p>
                                        <p class='text-sm text-red-700'>Subukan ulit mamaya.</p>
                                    </div>
                                </div>
                            </div>";
                            error_log("Insert error: " . mysqli_error($conn));
                        }
                        mysqli_stmt_close($stmt);
                    } else {
                        $response = "<div class='bg-gradient-to-r from-red-50 to-rose-50 rounded-2xl border border-red-200/50 p-6 shadow-sm'>
                            <div class='flex items-center justify-center gap-3 mb-3'>
                                <i class='fas fa-database text-red-500 text-2xl'></i>
                                <div>
                                    <p class='text-red-800 font-semibold text-lg'>Database error</p>
                                    <p class='text-sm text-red-700'>I-ulat sa support team.</p>
                                </div>
                            </div>
                        </div>";
                    }
                }
                break;
            case 'ask_feedback':
                $response = "<div class='bg-gradient-to-r from-indigo-50 to-purple-50 rounded-2xl border border-indigo-200/50 p-6 shadow-sm'>
                    <div class='flex items-start gap-3 mb-4'>
                        <div class='bg-indigo-500 text-white p-3 rounded-xl flex-shrink-0'>
                            <i class='fas fa-comment-dots text-lg'></i>
                        </div>
                        <div>
                            <h3 class='text-lg font-bold text-indigo-800 mb-2'>Magbigay ng Feedback</h3>
                            <p class='text-sm text-indigo-700'>Sabihin mo ang iyong opinyon tungkol sa aming serbisyo (min. 10 karakter).</p>
                        </div>
                    </div>
                    <div class='bg-white p-4 rounded-xl border border-indigo-100'>
                        <p class='text-xs text-gray-600 italic'>Halimbawa: <em>'Maganda ang service, sana mas mabilis ang response sa complaints.'</em></p>
                    </div>
                </div>";
                break;
            case 'confirm_feedback':
                if (isset($decoded['text'], $decoded['sentiment'])) {
                    $text = trim($decoded['text']);
                    $sentiment = ucfirst($decoded['sentiment'] ?? 'neutral');
                  
                    if (strlen($text) < 10 || trim(strip_tags($text)) === '') {
                        $response = "<div class='bg-gradient-to-r from-yellow-50 to-amber-50 rounded-2xl border border-yellow-200/50 p-6 shadow-sm'>
                            <div class='flex items-start gap-3 mb-4'>
                                <div class='bg-yellow-500 text-white p-3 rounded-xl flex-shrink-0'>
                                    <i class='fas fa-exclamation-triangle'></i>
                                </div>
                                <div>
                                    <h3 class='text-lg font-bold text-yellow-800'>Kulang ang feedback mo!</h3>
                                    <p class='text-sm text-yellow-700 mt-1'>Pakilarawan nang mas detalyado (min. 10 karakter).</p>
                                </div>
                            </div>
                        </div>";
                        break;
                    }
                  
                    $sentimentIcon = strtolower($sentiment) === 'positive' ? 'fa-thumbs-up text-green-500' : (strtolower($sentiment) === 'negative' ? 'fa-thumbs-down text-red-500' : 'fa-minus text-gray-500');
                    $response = "<div class='bg-gradient-to-r from-green-50 to-emerald-50 rounded-2xl border border-green-200/50 p-6 shadow-sm'>
                        <div class='flex items-start gap-3 mb-4'>
                            <div class='bg-green-500 text-white p-3 rounded-xl flex-shrink-0'>
                                <i class='fas fa-check-circle text-lg'></i>
                            </div>
                            <h3 class='text-lg font-bold text-green-800 mb-2'>Kumpirmasyon ng Feedback</h3>
                        </div>
                        <div class='space-y-3 mb-4'>
                            <div class='bg-white p-4 rounded-xl border border-green-100'>
                                <div class='flex items-center gap-2 mb-2'><i class='fas fa-comment text-blue-500'></i><strong>Feedback:</strong></div>
                                <p class='text-blue-700 text-sm italic'>“{$text}”</p>
                            </div>
                            <div class='flex items-center gap-2 bg-white p-4 rounded-xl border border-green-100'>
                                <i class='fas {$sentimentIcon}'></i><strong>Sentiment:</strong> <span class='font-medium text-green-700'>{$sentiment}</span>
                            </div>
                        </div>
                        <div class='bg-white p-4 rounded-xl border border-green-100 text-center'>
                            <p class='font-semibold text-green-800 text-lg'>I-save ba ito?</p>
                            <p class='text-sm text-gray-600 mt-1'>Reply <strong>Yes</strong> o <strong>No/Change</strong></p>
                        </div>
                    </div>";
                } else {
                    $response = "<div class='bg-gradient-to-r from-red-50 to-rose-50 rounded-2xl border border-red-200/50 p-6 shadow-sm'>
                        <div class='flex items-center gap-3 mb-3'>
                            <i class='fas fa-exclamation-circle text-red-500 flex-shrink-0'></i>
                            <p class='text-red-800 font-semibold'>Hindi kumpleto ang feedback. Subukan ulit.</p>
                        </div>
                    </div>";
                }
                break;
            case 'file_feedback':
                if (isset($decoded['text'], $decoded['sentiment'])) {
                    $text = trim($decoded['text']);
                    $sentiment = ucfirst(trim($decoded['sentiment']) ?: 'neutral');
                    if (strlen($text) < 10) {
                        $response = "<div class='bg-gradient-to-r from-red-50 to-rose-50 rounded-2xl border border-red-200/50 p-6 shadow-sm'>
                            <div class='flex items-center justify-center gap-3 mb-3'>
                                <i class='fas fa-exclamation-triangle text-red-500 text-2xl'></i>
                                <div>
                                    <p class='text-red-800 font-semibold text-lg'>Maikli ang feedback</p>
                                    <p class='text-sm text-red-700'>Hindi nai-save.</p>
                                </div>
                            </div>
                        </div>";
                        break;
                    }
                    $query = "INSERT INTO feedback (user_id, feedback_text, sentiment) VALUES (?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $query);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "iss", $user_id, $text, $sentiment);
                        if (mysqli_stmt_execute($stmt)) {
                            $response = "<div class='bg-gradient-to-r from-green-50 to-emerald-50 rounded-2xl border border-green-200/50 p-6 shadow-sm text-center'>
                                <div class='flex flex-col items-center gap-3 mb-4'>
                                    <div class='bg-green-500 text-white p-4 rounded-2xl flex-shrink-0'>
                                        <i class='fas fa-heart text-3xl'></i>
                                    </div>
                                    <div>
                                        <h3 class='text-2xl font-bold text-green-800 mb-1'>Salamat sa feedback!</h3>
                                        <p class='text-sm text-green-700'>Nai-save na at tinitignan namin para sa improvement.</p>
                                    </div>
                                </div>
                            </div>";
                            error_log("Feedback saved by user $user_id");
                        } else {
                            $response = "<div class='bg-gradient-to-r from-red-50 to-rose-50 rounded-2xl border border-red-200/50 p-6 shadow-sm'>
                                <div class='flex items-center justify-center gap-3 mb-3'>
                                    <i class='fas fa-exclamation-triangle text-red-500 text-2xl'></i>
                                    <div>
                                        <p class='text-red-800 font-semibold text-lg'>Error sa pag-save</p>
                                    </div>
                                </div>
                            </div>";
                        }
                        mysqli_stmt_close($stmt);
                    }
                }
                break;
            case 'get_complaint_details':
                preg_match_all('/\b#?(\d+)\b/', $userLower, $matches);
                $ids = array_unique(array_map('intval', $matches[1]));
                $wantsLatest = preg_match('/\b(latest|pinakabago|recent)\b/', $userLower);
                $wantsAll = preg_match('/\b(lahat|all|lahat ng)\b/', $userLower);
                if (!empty($ids)) {
                    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                    $query = "SELECT * FROM complaints WHERE complaint_id IN ($placeholders) AND user_id = ? ORDER BY FIELD(complaint_id, " . implode(',', $ids) . ")";
                    $stmt = mysqli_prepare($conn, $query);
                    if ($stmt) {
                        $params = array_merge($ids, [$user_id]);
                        $types = str_repeat('i', count($ids)) . 'i';
                        mysqli_stmt_bind_param($stmt, $types, ...$params);
                        mysqli_stmt_execute($stmt);
                        $result = mysqli_stmt_get_result($stmt);
                        $cards = [];
                        while ($row = mysqli_fetch_assoc($result)) {
                            $cards[] = buildComplaintCard($row);
                        }
                        mysqli_stmt_close($stmt);
                        $idList = implode(', ', array_map(fn($id) => "#$id", $ids));
                        $header = count($ids) == 1 ? "Detalye ng Complaint $idList:" : "Mga Detalye ng Complaint: $idList";
                        $response = !empty($cards)
                            ? "<div class='bg-white rounded-2xl border border-blue-200 p-4 mb-4 shadow-sm'><h3 class='text-lg font-bold text-blue-800 mb-3 flex items-center gap-2'><i class='fas fa-list'></i>{$header}</h3>" . implode('', $cards) . "</div>"
                            : "<div class='bg-gradient-to-r from-gray-50 to-gray-100 rounded-2xl p-6 text-center shadow-sm'><i class='fas fa-search text-gray-400 text-3xl mb-3'></i><p class='text-gray-600 font-medium'>Wala akong mahanap na complaint.</p></div>";
                    } else {
                        $response = "<div class='bg-gradient-to-r from-red-50 to-rose-50 rounded-2xl border border-red-200/50 p-6 shadow-sm text-center'>
                            <i class='fas fa-database text-red-500 text-2xl mb-3'></i>
                            <p class='text-red-800 font-semibold'>Error sa pagkuha ng data.</p>
                        </div>";
                    }
                } elseif ($wantsLatest || empty(trim($userText))) {
                    $query = "SELECT * FROM complaints WHERE user_id = ? ORDER BY created_at DESC LIMIT 3";
                    $stmt = mysqli_prepare($conn, $query);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "i", $user_id);
                        mysqli_stmt_execute($stmt);
                        $result = mysqli_stmt_get_result($stmt);
                        $cards = []; $count = 0;
                        while ($row = mysqli_fetch_assoc($result)) {
                            $count++;
                            $label = $count == 1 ? 'Pinakabago' : ($count == 2 ? 'Pangalawa' : 'Pangatlo');
                            $cards[] = buildComplaintCard($row, $label);
                        }
                        mysqli_stmt_close($stmt);
                        $response = !empty($cards)
                            ? "<div class='bg-white rounded-2xl border border-blue-200 p-4 mb-4 shadow-sm'><h3 class='text-lg font-bold text-blue-800 mb-3 flex items-center gap-2'><i class='fas fa-clock'></i>3 Pinakabagong Reklamo:</h3>" . implode('', $cards) . "</div>"
                            : "<div class='bg-gradient-to-r from-gray-50 to-gray-100 rounded-2xl p-6 text-center shadow-sm'><i class='fas fa-inbox text-gray-400 text-3xl mb-3'></i><p class='text-gray-600 font-medium'>Wala ka pang complaint.</p></div>";
                    } else {
                        $response = "<div class='bg-gradient-to-r from-red-50 to-rose-50 rounded-2xl border border-red-200/50 p-6 shadow-sm text-center'>
                            <i class='fas fa-database text-red-500 text-2xl mb-3'></i>
                            <p class='text-red-800 font-semibold'>Error sa pagkuha ng data.</p>
                        </div>";
                    }
                } elseif ($wantsAll) {
                    $query = "SELECT * FROM complaints WHERE user_id = ? ORDER BY created_at DESC";
                    $stmt = mysqli_prepare($conn, $query);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "i", $user_id);
                        mysqli_stmt_execute($stmt);
                        $result = mysqli_stmt_get_result($stmt);
                        $cards = [];
                        while ($row = mysqli_fetch_assoc($result)) {
                            $cards[] = buildComplaintCard($row);
                        }
                        mysqli_stmt_close($stmt);
                        $response = !empty($cards)
                            ? "<div class='bg-white rounded-2xl border border-blue-200 p-4 mb-4 shadow-sm'><h3 class='text-lg font-bold text-blue-800 mb-3 flex items-center gap-2'><i class='fas fa-list-ul'></i>Lahat ng Reklamo:</h3>" . implode('', $cards) . "</div>"
                            : "<div class='bg-gradient-to-r from-gray-50 to-gray-100 rounded-2xl p-6 text-center shadow-sm'><i class='fas fa-inbox text-gray-400 text-3xl mb-3'></i><p class='text-gray-600 font-medium'>Wala ka pang complaint.</p></div>";
                    } else {
                        $response = "<div class='bg-gradient-to-r from-red-50 to-rose-50 rounded-2xl border border-red-200/50 p-6 shadow-sm text-center'>
                            <i class='fas fa-database text-red-500 text-2xl mb-3'></i>
                            <p class='text-red-800 font-semibold'>Error sa pagkuha ng data.</p>
                        </div>";
                    }
                }
                break;
            case 'get_feedback_history':
                $count = min((int)($decoded['count'] ?? 5), 10);
                $query = "SELECT feedback_text, sentiment, created_at FROM feedback WHERE user_id = ? ORDER BY created_at DESC LIMIT ?";
                $stmt = mysqli_prepare($conn, $query);
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "ii", $user_id, $count);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    $items = [];
                    while ($row = mysqli_fetch_assoc($result)) {
                        $date = date('M j, Y', strtotime($row['created_at']));
                        $sentiment = ucfirst($row['sentiment']);
                        $sentimentColor = strtolower($sentiment) === 'positive' ? 'text-green-600' : (strtolower($sentiment) === 'negative' ? 'text-red-600' : 'text-gray-600');
                        $items[] = "<div class='bg-white border border-gray-200 rounded-xl p-4 mb-3 shadow-sm hover:shadow-md transition-shadow'>
                                        <p class='text-sm italic text-gray-700 mb-2 leading-relaxed'>“{$row['feedback_text']}”</p>
                                        <div class='flex items-center justify-between text-xs'>
                                            <span class='{$sentimentColor} font-medium'>{$sentiment}</span>
                                            <span class='text-gray-500'>{$date}</span>
                                        </div>
                                    </div>";
                    }
                    mysqli_stmt_close($stmt);
                    $response = !empty($items)
                        ? "<div class='bg-white rounded-2xl border border-indigo-200 p-4 mb-4 shadow-sm'><h3 class='text-lg font-bold text-indigo-800 mb-3 flex items-center gap-2'><i class='fas fa-comments'></i>Iyong Feedback ({$count}):</h3>" . implode('', $items) . "</div>"
                        : "<div class='bg-gradient-to-r from-gray-50 to-gray-100 rounded-2xl p-6 text-center shadow-sm'><i class='fas fa-comments text-gray-400 text-3xl mb-3'></i><p class='text-gray-600 font-medium'>Wala ka pang feedback.</p></div>";
                } else {
                    $response = "<div class='bg-gradient-to-r from-red-50 to-rose-50 rounded-2xl border border-red-200/50 p-6 shadow-sm text-center'>
                        <i class='fas fa-database text-red-500 text-2xl mb-3'></i>
                        <p class='text-red-800 font-semibold'>Error sa pagkuha ng data.</p>
                    </div>";
                }
                break;
            case 'show_menu':
                $response = "<div class='bg-gradient-to-r from-blue-50 to-indigo-50 rounded-2xl border border-blue-200/50 p-6 shadow-sm'>
                    <div class='flex items-start gap-3 mb-4'>
                        <div class='bg-blue-500 text-white p-3 rounded-xl flex-shrink-0'>
                            <i class='fas fa-bars text-lg'></i>
                        </div>
                        <h3 class='text-lg font-bold text-blue-800 mb-3'>Ano ang Pwedeng Gawin?</h3>
                    </div>
                    <div class='space-y-3 text-sm'>
                        <div class='flex items-start gap-3 p-3 bg-white rounded-xl border border-blue-100'>
                            <i class='fas fa-exclamation-triangle text-blue-500 mt-0.5 flex-shrink-0'></i>
                            <p><strong>Mag-file ng reklamo:</strong> 'May sira yung gripo ko'</p>
                        </div>
                        <div class='flex items-start gap-3 p-3 bg-white rounded-xl border border-blue-100'>
                            <i class='fas fa-eye text-blue-500 mt-0.5 flex-shrink-0'></i>
                            <p><strong>Tingnan ang status:</strong> '#123' o 'latest'</p>
                        </div>
                        <div class='flex items-start gap-3 p-3 bg-white rounded-xl border border-blue-100'>
                            <i class='fas fa-comment-dots text-blue-500 mt-0.5 flex-shrink-0'></i>
                            <p><strong>Magbigay ng feedback:</strong> 'Maganda yung service'</p>
                        </div>
                        <div class='flex items-start gap-3 p-3 bg-white rounded-xl border border-blue-100'>
                            <i class='fas fa-history text-blue-500 mt-0.5 flex-shrink-0'></i>
                            <p><strong>Tingnan ang feedback:</strong> 'ipakita feedback ko'</p>
                        </div>
                        <div class='flex items-start gap-3 p-3 bg-white rounded-xl border border-blue-100'>
                            <i class='fas fa-question text-blue-500 mt-0.5 flex-shrink-0'></i>
                            <p><strong>Help:</strong> 'ano pwede ko gawin'</p>
                        </div>
                    </div>
                </div>";
                break;
            case 'login_guide':
                $response = "<div class='bg-gradient-to-r from-blue-50 to-indigo-50 rounded-2xl border border-blue-200/50 p-6 shadow-sm'>
                    <div class='flex items-start gap-3 mb-4'>
                        <div class='bg-blue-500 text-white p-3 rounded-xl flex-shrink-0'>
                            <i class='fas fa-sign-in-alt text-lg'></i>
                        </div>
                        <h3 class='text-lg font-bold text-blue-800 mb-3'>Para makapag-login o mag-register:</h3>
                    </div>
                    <div class='space-y-3'>
                        <a href='../login.php' class='flex items-center gap-3 p-3 bg-white rounded-xl border border-blue-100 hover:shadow-md transition-shadow text-sm'>
                            <i class='fas fa-lock text-blue-500 flex-shrink-0'></i>
                            <div><strong>Mag-login</strong> - Kung may account ka na.</div>
                        </a>
                        <a href='../register.php' class='flex items-center gap-3 p-3 bg-white rounded-xl border border-blue-100 hover:shadow-md transition-shadow text-sm'>
                            <i class='fas fa-user-plus text-blue-500 flex-shrink-0'></i>
                            <div><strong>Mag-register</strong> - Gumawa ng bagong account.</div>
                        </a>
                    </div>
                </div>";
                break;
            case 'get_board_of_directors':
                $items = '';
                foreach ($boardData['members'] as $m) {
                    $items .= "<div class='flex items-center justify-between p-3 bg-white rounded-xl border border-blue-100 mb-2 hover:shadow-md transition-shadow'>
                        <span class='font-medium text-gray-700'>{$m['position']}</span>
                        <span class='font-bold text-blue-800'>{$m['name']}</span>
                    </div>";
                }
                $response = "<div class='bg-gradient-to-r from-blue-50 to-indigo-50 rounded-2xl border border-blue-200/50 p-6 shadow-sm'>
                    <h3 class='text-xl font-bold text-blue-800 mb-4 flex items-center gap-2'><i class='fas fa-users'></i>{$boardData['title']}</h3>
                    <div class='space-y-1 text-sm'>{$items}</div>
                </div>";
                break;
            case 'get_management_team':
                $deptList = '';
                foreach ($managementData['department_managers']['list'] as $d) {
                    $deptList .= "<div class='flex items-center justify-between p-3 bg-white rounded-xl border border-indigo-100 mb-2 hover:shadow-md transition-shadow'>
                        <span class='font-medium text-gray-700'>{$d['dept']}</span>
                        <span class='font-bold text-indigo-800'>{$d['name']}</span>
                    </div>";
                }
                $divList = '';
                foreach ($managementData['division_managers'] as $dept => $divs) {
                    $divList .= "<div class='mb-4'>
                        <h4 class='font-semibold text-indigo-700 mb-2 bg-indigo-100 px-3 py-1 rounded-lg inline-block'>$dept</h4>
                        <div class='space-y-1'>";
                    foreach ($divs as $div) {
                        $name = $div['name'] === 'Vacant' ? '<em class="text-gray-500">Vacant</em>' : $div['name'];
                        $divList .= "<div class='flex items-center justify-between p-3 bg-white rounded-xl border border-indigo-100 hover:shadow-md transition-shadow text-xs'>
                            <span class='text-gray-600'>{$div['division']}</span>
                            <span class='font-medium'>{$name}</span>
                        </div>";
                    }
                    $divList .= "</div></div>";
                }
                $response = "<div class='bg-gradient-to-r from-indigo-50 to-purple-50 rounded-2xl border border-indigo-200/50 p-6 shadow-sm'>
                    <h3 class='text-xl font-bold text-indigo-800 mb-4 flex items-center gap-2'><i class='fas fa-sitemap'></i>Management Team</h3>
                    <div class='mb-4'>
                        <h4 class='font-semibold text-indigo-700 mb-2'>General Manager</h4>
                        <div class='bg-white p-4 rounded-xl border border-indigo-100 shadow-sm'>
                            <span class='font-bold text-2xl text-indigo-800'>{$managementData['general_manager']['name']}</span>
                        </div>
                    </div>
                    <div class='mb-4'>
                        <h4 class='font-semibold text-indigo-700 mb-2'>Department Managers</h4>
                        <div class='space-y-1'>{$deptList}</div>
                    </div>
                    <div class='text-xs'>{$divList}</div>
                </div>";
                break;
            case 'get_current_gm':
                $gm = $managementData['general_manager']['name'];
                $response = "<div class='bg-gradient-to-r from-green-50 to-emerald-50 rounded-2xl border border-green-200/50 p-6 shadow-sm text-center'>
                    <div class='flex flex-col items-center gap-3'>
                        <div class='bg-green-500 text-white p-4 rounded-2xl'>
                            <i class='fas fa-user-tie text-3xl'></i>
                        </div>
                        <h3 class='text-lg font-bold text-green-800 mb-2'>Kasalukuyang General Manager:</h3>
                        <p class='text-2xl font-bold text-green-700'>{$gm}</p>
                    </div>
                </div>";
                break;
            case 'get_past_gm':
                $past = getPastGeneralManagers($conn);
                if (empty($past)) {
                    $response = "<div class='bg-gradient-to-r from-gray-50 to-gray-100 rounded-2xl p-6 text-center shadow-sm'>
                        <i class='fas fa-history text-gray-400 text-3xl mb-3'></i>
                        <p class='text-gray-600 font-medium'>Wala pang dating General Manager na nirekord.</p>
                    </div>";
                } else {
                    $list = '';
                    foreach ($past as $p) {
                        $end = $p['end_date'] ? " - " . date('M Y', strtotime($p['end_date'])) : '';
                        $list .= "<div class='flex items-center justify-between p-3 bg-white rounded-xl border border-yellow-100 mb-2 hover:shadow-md transition-shadow'>
                            <span class='font-bold text-yellow-800'>{$p['name']}</span>
                            <span class='text-sm text-gray-600'>(" . date('M Y', strtotime($p['start_date'])) . "$end)</span>
                        </div>";
                    }
                    $response = "<div class='bg-gradient-to-r from-yellow-50 to-amber-50 rounded-2xl border border-yellow-200/50 p-6 shadow-sm'>
                        <h3 class='text-lg font-bold text-yellow-800 mb-3 flex items-center gap-2'><i class='fas fa-history'></i>Dating General Managers:</h3>
                        <div class='space-y-1 text-sm'>{$list}</div>
                    </div>";
                }
                break;
            case 'get_full_org_chart':
                $response = "<div class='bg-gradient-to-r from-indigo-50 to-purple-50 rounded-2xl border border-indigo-200/50 p-6 shadow-sm'>
                    <h3 class='text-xl font-bold text-indigo-800 mb-4 flex items-center gap-2'><i class='fas fa-sitemap'></i>Full Organizational Structure</h3>
                    <div class='prose prose-sm max-w-none'>{$boardHtml}{$managementHtml}</div>
                </div>";
                break;
            case 'get_public_stats':
                $response = "<div class='bg-gradient-to-r from-gray-50 to-gray-100 rounded-2xl border border-gray-200 p-6 shadow-sm'>
                    <h3 class='text-xl font-bold text-gray-800 mb-4 flex items-center gap-2'><i class='fas fa-chart-bar'></i>Public Statistics</h3>
                    <div class='prose prose-sm max-w-none'>{$statsHtml}</div>
                </div>";
                break;
            case 'get_history':
                if (isset($staticMap['history'])) {
                    $title = $staticMap['history']['title'];
                    $content = $staticMap['history']['content'];
                    $response = "<div class='bg-white rounded-2xl border border-blue-200 p-6 shadow-sm'>
                        <h3 class='text-xl font-bold text-blue-800 mb-4 flex items-center gap-2'><i class='fas fa-history'></i>{$title}</h3>
                        <div class='prose prose-sm max-w-none'>{$content}</div>
                    </div>";
                } else {
                    $response = "<div class='bg-gradient-to-r from-gray-50 to-gray-100 rounded-2xl p-6 text-center shadow-sm'><i class='fas fa-history text-gray-400 text-3xl mb-3'></i><p class='text-gray-600 font-medium'>Wala pang history na nirekord.</p></div>";
                }
                break;
            case 'get_mission':
                if (isset($staticMap['mission'])) {
                    $title = $staticMap['mission']['title'];
                    $content = $staticMap['mission']['content'];
                    $response = "<div class='bg-white rounded-2xl border border-green-200 p-6 shadow-sm'>
                        <h3 class='text-xl font-bold text-green-800 mb-4 flex items-center gap-2'><i class='fas fa-bullseye'></i>{$title}</h3>
                        <div class='prose prose-sm max-w-none'>{$content}</div>
                    </div>";
                } else {
                    $response = "<div class='bg-gradient-to-r from-gray-50 to-gray-100 rounded-2xl p-6 text-center shadow-sm'><i class='fas fa-bullseye text-gray-400 text-3xl mb-3'></i><p class='text-gray-600 font-medium'>Wala pang mission na nirekord.</p></div>";
                }
                break;
            case 'get_vision':
                if (isset($staticMap['vision'])) {
                    $title = $staticMap['vision']['title'];
                    $content = $staticMap['vision']['content'];
                    $response = "<div class='bg-white rounded-2xl border border-purple-200 p-6 shadow-sm'>
                        <h3 class='text-xl font-bold text-purple-800 mb-4 flex items-center gap-2'><i class='fas fa-eye'></i>{$title}</h3>
                        <div class='prose prose-sm max-w-none'>{$content}</div>
                    </div>";
                } else {
                    $response = "<div class='bg-gradient-to-r from-gray-50 to-gray-100 rounded-2xl p-6 text-center shadow-sm'><i class='fas fa-eye text-gray-400 text-3xl mb-3'></i><p class='text-gray-600 font-medium'>Wala pang vision na nirekord.</p></div>";
                }
                break;
            case 'get_core_values':
                if (isset($staticMap['core_values'])) {
                    $title = $staticMap['core_values']['title'];
                    $content = $staticMap['core_values']['content'];
                    $response = "<div class='bg-white rounded-2xl border border-indigo-200 p-6 shadow-sm'>
                        <h3 class='text-xl font-bold text-indigo-800 mb-4 flex items-center gap-2'><i class='fas fa-star'></i>{$title}</h3>
                        <div class='prose prose-sm max-w-none'>{$content}</div>
                    </div>";
                } else {
                    $response = "<div class='bg-gradient-to-r from-gray-50 to-gray-100 rounded-2xl p-6 text-center shadow-sm'><i class='fas fa-star text-gray-400 text-3xl mb-3'></i><p class='text-gray-600 font-medium'>Wala pang core values na nirekord.</p></div>";
                }
                break;
            case 'get_quality_policy':
                if (isset($staticMap['quality_policy'])) {
                    $title = $staticMap['quality_policy']['title'];
                    $content = $staticMap['quality_policy']['content'];
                    $response = "<div class='bg-white rounded-2xl border border-yellow-200 p-6 shadow-sm'>
                        <h3 class='text-xl font-bold text-yellow-800 mb-4 flex items-center gap-2'><i class='fas fa-certificate'></i>{$title}</h3>
                        <div class='prose prose-sm max-w-none'>{$content}</div>
                    </div>";
                } else {
                    $response = "<div class='bg-gradient-to-r from-gray-50 to-gray-100 rounded-2xl p-6 text-center shadow-sm'><i class='fas fa-certificate text-gray-400 text-3xl mb-3'></i><p class='text-gray-600 font-medium'>Wala pang quality policy na nirekord.</p></div>";
                }
                break;
            case 'get_office_location':
                $gmapEmbed = '<iframe src="https://www.google.com/maps/embed?pb=!1m14!1m8!1m3!1d15472.411686808355!2d121.1576812!3d14.1887497!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x33bd63dde4221d71%3A0x2b48f46c8c2e3e91!2sCalamba%20Water%20District!5e0!3m2!1sen!2sph!4v1763387982920!5m2!1sen!2sph" width="100%" height="300" style="border:0; border-radius:12px; margin:15px 0; box-shadow:0 4px 12px rgba(0,0,0,0.15);" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>';
                $response = "<div class='bg-gradient-to-r from-blue-50 to-indigo-50 rounded-2xl border border-blue-200 p-6 shadow-sm'>
                    <h3 class='text-lg font-bold text-blue-800 mb-4 flex items-center gap-2'><i class='fas fa-map-marker-alt'></i>Office Location</h3>
                    <p class='text-gray-700 mb-2'>                    <p class='text-gray-700 mb-2'><strong>Lake View Subd., St Paul St, Calamba, 4027 Laguna</strong></p>
                    <p class='text-sm text-gray-600 mb-4'>Open Monday–Friday, 8:00 AM – 5:00 PM</p>
                    <div class='bg-white rounded-lg overflow-hidden shadow-sm'>{$gmapEmbed}</div>
                </div>";
                break;
            default:
                $response = trim(preg_replace('/\{[^}]+\}/', '', $rawResponse));
                break;
        }
    } else {
        $response = $rawResponse;
    }
    // === SANITIZE HTML ===
    $allowed = '<div><p><strong><em><span><a><br><b><i><u><ul><li><table><thead><tbody><tr><th><td><i class="fas';
    $response = strip_tags($response, $allowed);
    $response = preg_replace_callback('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', function($m) {
        $href = trim($m[1]);
        $text = trim(strip_tags($m[2]));
        // Always style and secure external/absolute links
        if (preg_match('/^https?:\/\//i', $href) || preg_match('/^\/|^\./', $href)) {
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" class="text-blue-600 underline hover:text-blue-800" target="_blank" rel="noopener noreferrer">' . $text . '</a>';
        }
        // For other hrefs, strip to text
        return $text;
    }, $response);
    // === CLEAN OUTPUT ===
    ob_end_clean(); // Discard any buffered output
    echo json_encode(['response' => $response], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
} catch (Throwable $e) {
    error_log("Chat Error: " . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Service temporarily unavailable.'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
} finally {
    if (isset($conn)) mysqli_close($conn);
}
?>