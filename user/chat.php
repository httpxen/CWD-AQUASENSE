<?php
include 'session_check.php'; // Include the separated session check
session_name('CustomerSession');
session_start();
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

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'User not authenticated']);
    exit;
}
$user_id = $_SESSION['user_id'];

global $conn;

// === DATABASE HELPERS ===
function getCurrentBoard($conn) {
    $query = "SELECT pos.title AS position, p.name 
              FROM position_assignments pa
              JOIN positions pos ON pa.position_id = pos.position_id
              JOIN people p ON pa.person_id = p.person_id
              WHERE pos.category = 'board' AND pa.is_current = 1
              ORDER BY pos.order_index";
    $result = mysqli_query($conn, $query);
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
    $gmRow = mysqli_fetch_assoc($gmResult);
    $general_manager = ['title' => 'General Manager', 'name' => $gmRow['name'] ?? 'Vacant'];

    $deptQuery = "SELECT pos.department AS dept, p.name 
                  FROM position_assignments pa
                  JOIN positions pos ON pa.position_id = pos.position_id
                  JOIN people p ON pa.person_id = p.person_id
                  WHERE pos.title = 'Department Manager' AND pa.is_current = 1
                  ORDER BY pos.order_index";
    $deptResult = mysqli_query($conn, $deptQuery);
    $deptList = [];
    while ($row = mysqli_fetch_assoc($deptResult)) {
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
    while ($row = mysqli_fetch_assoc($divResult)) {
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
    $past = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $past[] = $row;
    }
    return $past;
}

// === LOAD FROM DATABASE (with session cache) ===
if (!isset($_SESSION['board_data']) || !isset($_SESSION['management_data'])) {
    $_SESSION['board_data'] = getCurrentBoard($conn);
    $_SESSION['management_data'] = getCurrentManagement($conn);
}
$boardData = $_SESSION['board_data'];
$managementData = $_SESSION['management_data'];

// === ALLOWED CATEGORIES ===
$ALLOWED_CATEGORIES = [
    'Billing', 'Water quality', 'Service interruption', 'Meter / Leakage',
    'New Connection / Disconnection', 'Customer Service', 'Others'
];
$categoriesList = implode(', ', $ALLOWED_CATEGORIES);
$categoriesHtml = "<div class='p-3 bg-blue-50 rounded-lg border border-blue-200 mb-2'>
    <p class='font-semibold text-blue-800 mb-2'>Ano ang kategorya ng iyong reklamo?</p>
    <ul class='space-y-1 text-sm text-blue-700 list-disc list-inside'>
        <li><strong>Billing</strong></li>
        <li><strong>Water quality</strong></li>
        <li><strong>Service interruption</strong></li>
        <li><strong>Meter / Leakage</strong></li>
        <li><strong>New Connection / Disconnection</strong></li>
        <li><strong>Customer Service</strong></li>
        <li><strong>Others</strong></li>
    </ul>
    <p class='text-xs text-gray-600 mt-2'>Pakibigay ang kategorya (e.g., 'Billing') at ilarawan ang iyong concern (hindi bababa sa 10 karakter).</p>
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
        'pending'       => ['bg-yellow-100 text-yellow-800', 'fa-clock'],
        'in_progress'   => ['bg-blue-100 text-blue-800', 'fa-cogs'],
        'resolved'      => ['bg-green-100 text-green-800', 'fa-check-circle'],
        'closed'        => ['bg-gray-100 text-gray-800', 'fa-lock']
    ];
    $color = $colors[$status] ?? ['bg-gray-100 text-gray-800', 'fa-question-circle'];
    return "<span class='inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium {$color[0]}'>
                <i class='fas {$color[1]}'></i> " . ucfirst(str_replace('_', ' ', $status)) . "
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
        ? "<p><strong>Assigned to:</strong> <span class='text-blue-700 font-medium'>{$assignedStaff}</span></p>"
        : "<p><strong>Assigned to:</strong> <em class='text-gray-500'>Not yet assigned</em></p>";

    $headerLabel = $label ? "<em class='text-xs text-gray-500'>($label)</em>" : '';
    $attachmentLink = $row['attachment_path'] ? "<p><strong>Attachment:</strong> <a href='{$row['attachment_path']}' class='text-blue-600 underline' target='_blank'>View File</a></p>" : '';

    return "
    <div class='complaint-card border border-gray-300 rounded-lg p-4 mb-4 bg-white shadow-sm'>
        <div class='complaint-header flex justify-between items-center mb-2'>
            <div>
                <strong class='text-lg'>Complaint #{$row['complaint_id']}</strong> $headerLabel
            </div>
            <div>$statusBadge</div>
        </div>
        <div class='complaint-body text-sm space-y-1'>
            <p><strong>Category:</strong> {$row['category']}</p>
            <p><strong>Filed on:</strong> {$filed}</p>
            $assignedText
            " . ($resolved ? "<p><strong>Resolved on:</strong> {$resolved}</p>" : "") . "
            $attachmentLink
            <p><strong>Sentiment:</strong> " . ucfirst($row['sentiment']) . "</p>
        </div>
        <div class='complaint-description mt-3 pt-3 border-t border-gray-200'>
            <p class='font-medium text-sm'>Description:</p>
            <p class='description-text text-gray-700 italic'>\"{$row['description']}\"</p>
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

// === PARSE INPUT ===
$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['messages']) || !is_array($input['messages']) || empty($input['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No messages provided']);
    exit;
}

$lastUserMessage = end($input['messages']);
$userText = strtolower($lastUserMessage['content'] ?? '');

// === KEYWORDS ===
$boardKeywords = ['chairman', 'chairperson', 'board', 'directors', 'bod', 'chair'];
$mgmtKeywords = ['gm', 'general manager', 'manager', 'head', 'department', 'division'];
$pastGmKeywords = ['past', 'previous', 'dating', 'former', 'ex', 'lumang'];
$currentGmKeywords = ['current', 'present', 'kasalukuyan', 'ngayon'];

$isBoardQuery = preg_match('/\b(' . implode('|', $boardKeywords) . ')\b/', $userText);
$isMgmtQuery = preg_match('/\b(' . implode('|', $mgmtKeywords) . ')\b/', $userText);
$isPastGmQuery = preg_match('/\b(' . implode('|', $pastGmKeywords) . ')\b/', $userText) && preg_match('/\bgm\b/', $userText);
$isCurrentGmQuery = preg_match('/\b(' . implode('|', $currentGmKeywords) . ')\b/', $userText) && preg_match('/\bgm\b/', $userText);

// === OPENAI CALL ===
try {
    $client = \OpenAI::client($apiKey);

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
- DO NOT GIVE names outside JSON actions.
- If unsure: Say \"Hindi ko mahanap. Pakicheck sa official website.\"

=== INTENT DETECTION (USE HISTORY - NO REPEATS/LOOPS) ===
- FIRST TIME complaint intent ('complaint', 'reklamo', 'create complaint', 'mag-file', 'problem', 'issue', 'concern' AND no prior complaint flow): {\"type\":\"ask_category\"}
- AFTER ask_category (check history): 
  - If ONLY category (e.g., 'Billing') WITHOUT description: {\"type\":\"ask_description\", \"category\":\"EXACT MATCH\"}
  - If category + CLEAR description (separate, >=10 chars, not category repeat, e.g., 'Billing - Overcharge sa bill ko dahil sa wrong reading'): {\"type\":\"confirm_complaint\", \"category\":\"EXACT MATCH\", \"description\":\"EXTRACT DESCRIPTION ONLY (clean, no category if redundant)\", \"sentiment\":\"positive/negative/neutral\"}
  - If unclear: {\"type\":\"ask_category\"} with brief reminder.
- AFTER ask_description: If description >=10 chars & distinct: {\"type\":\"confirm_complaint\", \"category\":\"[PREVIOUS]\", \"description\":\"...\", \"sentiment\":\"...\"}
- AFTER confirm_complaint: 'yes/oo/sige' → {\"type\":\"file_complaint\", \"category\":\"[PREVIOUS]\", \"description\":\"[PREVIOUS]\", \"sentiment\":\"[PREVIOUS]\"}; 'no/hindi/change' → {\"type\":\"ask_category\"}
- For status check: '#ID', 'status', 'latest', 'all' → {\"type\":\"get_complaint_details\", \"complaint_id\":ID or null for latest/all}
- FIRST TIME feedback intent ('feedback', 'suggestion', 'magbigay ng feedback', 'maganda/sama' AND no prior feedback flow): {\"type\":\"ask_feedback\"}
- AFTER ask_feedback: If text >=10 chars: {\"type\":\"confirm_feedback\", \"text\":\"EXTRACTED TEXT\", \"sentiment\":\"positive/negative/neutral\"}
- AFTER confirm_feedback: 'yes/oo' → {\"type\":\"file_feedback\", \"text\":\"[PREVIOUS]\", \"sentiment\":\"[PREVIOUS]\"}; 'no' → {\"type\":\"ask_feedback\"}
- To view feedback: 'show feedback', 'my feedback', 'ipakita feedback' → {\"type\":\"get_feedback_history\", \"count\":5}
- For menu/help: 'help', 'ano pwede', 'menu' → {\"type\":\"show_menu\"}
- For login/register: 'login', 'register', 'sign up' → {\"type\":\"login_guide\"}
- If no specific intent, respond with helpful text (no JSON).

=== JSON OUTPUT ONLY FOR ACTIONS ===
Output ONLY valid JSON for actions, NO EXTRA TEXT. For general responses, output plain text.
Examples:
{\"type\":\"ask_category\"}
{\"type\":\"ask_description\", \"category\":\"Billing\"}
{\"type\":\"confirm_complaint\", \"category\":\"Billing\", \"description\":\"Overcharge sa bill ko... (min 10 chars)\", \"sentiment\":\"negative\"}
{\"type\":\"file_complaint\", \"category\":\"Billing\", \"description\":\"...\", \"sentiment\":\"negative\"}
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

Be context-aware: Recall from history. Avoid loops by strict checks on text length/uniqueness."
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

    // === PROCESS JSON ACTION ===
    if (is_array($decoded) && isset($decoded['type'])) {
        switch ($decoded['type']) {

            case 'ask_category':
                $response = "<div class='p-4 bg-blue-50 rounded-lg border border-blue-200'>
                    <p class='font-semibold text-blue-800 mb-3'>Sige, tutulungan kita sa pag-file ng complaint!</p>
                    <p class='text-sm text-blue-700 mb-2'>Pumili ng kategorya:</p>
                    <ul class='list-disc list-inside text-sm text-blue-700 space-y-1'>
                        <li>Billing</li>
                        <li>Water quality</li>
                        <li>Service interruption</li>
                        <li>Meter / Leakage</li>
                        <li>New Connection / Disconnection</li>
                        <li>Customer Service</li>
                        <li>Others</li>
                    </ul>
                    <p class='text-xs text-gray-600 mt-3'>Halimbawa: <em>Billing - Overcharge sa bill ko</em></p>
                </div>";
                break;

            case 'ask_description':
                if (isset($decoded['category'])) {
                    $category = trim($decoded['category']);
                    if (!in_array($category, $ALLOWED_CATEGORIES)) {
                        $closest = findClosestCategory($category, $ALLOWED_CATEGORIES);
                        if (!$closest) {
                            $response = "<div class='p-3 bg-red-50 rounded-lg border border-red-200'>
                                <p class='text-red-800 font-medium'>Hindi valid na category: <strong>{$category}</strong></p>
                                <p class='text-sm text-red-700'>Pumili mula sa list: $categoriesList</p>
                            </div>";
                            break;
                        }
                        $category = $closest;
                    }
                    $response = "<div class='p-4 bg-yellow-50 rounded-lg border border-yellow-200'>
                        <p class='font-semibold text-yellow-800 mb-2'>Category: <strong>{$category}</strong></p>
                        <p class='text-sm text-yellow-700'>Pakilarawan ang detalye (min. 10 karakter):</p>
                        <p class='text-xs text-gray-600 mt-2'>Halimbawa: 'May tumutulo sa metro ko sa bahay.'</p>
                    </div>";
                } else {
                    $response = "<div class='p-3 bg-red-50 rounded-lg border border-red-200'>
                        <p class='text-red-800'>Hindi ko nakuha ang category. Subukan ulit.</p>
                    </div>" . $categoriesHtml;
                }
                break;

            case 'confirm_complaint':
                if (isset($decoded['category'], $decoded['description'])) {
                    $description = trim($decoded['description']);
                    $sentiment = ucfirst($decoded['sentiment'] ?? 'neutral');
                    $category = trim($decoded['category']);
                    
                    if (strlen($description) < 10 || trim(strip_tags($description)) === '' || strtolower(trim($description)) === strtolower($category)) {
                        $response = "<div class='p-4 bg-yellow-50 rounded-lg border border-yellow-200'>
                            <p class='font-semibold text-yellow-800'>Kulang pa ang description.</p>
                            <p class='text-sm text-yellow-700'>Pakilarawan nang maayos (min. 10 karakter).</p>
                        </div>";
                        break;
                    }
                    
                    if (!in_array($category, $ALLOWED_CATEGORIES)) {
                        $closest = findClosestCategory($category, $ALLOWED_CATEGORIES);
                        if (!$closest) {
                            $response = "<div class='p-3 bg-red-50 rounded-lg border border-red-200'>
                                <p class='text-red-800'>Hindi valid na category: <strong>{$category}</strong></p>
                                <p class='text-sm'>Pumili mula sa list: $categoriesList</p>
                            </div>";
                            break;
                        }
                        $decoded['category'] = $closest;
                        $category = $closest;
                    }
                    $response = "<div class='p-4 bg-green-50 rounded-lg border border-green-200 text-sm'>
                        <p class='font-bold text-green-800 mb-3'>Kumpirmasyon ng Complaint</p>
                        <div class='space-y-2'>
                            <p><strong>Category:</strong> <span class='italic'>“{$category}”</span></p>
                            <p><strong>Description:</strong> <span class='italic text-sm'>“{$description}”</span></p>
                            <p><strong>Sentiment:</strong> <span class='font-medium text-green-700'>{$sentiment}</span></p>
                        </div>
                        <p class='font-semibold text-green-800 mt-4 pt-3 border-t border-green-200'>
                            I-file ito? Reply <strong>Yes</strong> o <strong>No/Change</strong>
                        </p>
                    </div>";
                } else {
                    $response = "<div class='p-3 bg-red-50 rounded-lg border border-red-200'>
                        <p class='text-red-800'>Hindi kumpleto ang detalye. Subukan ulit.</p>
                    </div>";
                }
                break;

            case 'file_complaint':
                if (isset($decoded['category'], $decoded['description'], $decoded['sentiment'])) {
                    $category = trim($decoded['category']);
                    $description = trim($decoded['description']);
                    $sentiment = ucfirst(trim($decoded['sentiment']) ?: 'neutral');

                    if (!in_array($category, $ALLOWED_CATEGORIES)) {
                        $closest = findClosestCategory($category, $ALLOWED_CATEGORIES);
                        if (!$closest) {
                            $response = "<div class='p-3 bg-red-50 rounded-lg border border-red-200'>
                                <p class='text-red-800'>Invalid category. Hindi nai-save.</p>
                            </div>";
                            break;
                        }
                        $category = $closest;
                    }

                    if (strlen($description) < 10) {
                        $response = "<div class='p-3 bg-red-50 rounded-lg border border-red-200'>
                            <p class='text-red-800'>Maikli ang description. Hindi nai-save.</p>
                        </div>";
                        break;
                    }

                    $query = "INSERT INTO complaints (user_id, category, description, sentiment, status) VALUES (?, ?, ?, ?, 'Pending')";
                    $stmt = mysqli_prepare($conn, $query);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "isss", $user_id, $category, $description, $sentiment);
                        if (mysqli_stmt_execute($stmt)) {
                            $complaint_id = mysqli_insert_id($conn);
                            $response = "<div class='p-4 bg-green-50 rounded-lg border border-green-200'>
                                <p class='font-bold text-green-800 mb-2'>Nai-file na ang complaint!</p>
                                <p class='text-sm text-green-700'>ID: <strong>#$complaint_id</strong></p>
                            </div>";
                            error_log("Complaint #$complaint_id filed by user $user_id");
                        } else {
                            $response = "<div class='p-3 bg-red-50 rounded-lg border border-red-200'>
                                <p class='text-red-800'>Error sa pag-save. Subukan ulit.</p>
                            </div>";
                            error_log("Insert error: " . mysqli_error($conn));
                        }
                        mysqli_stmt_close($stmt);
                    } else {
                        $response = "<div class='p-3 bg-red-50 rounded-lg border border-red-200'>
                            <p class='text-red-800'>Database error. I-ulat sa support.</p>
                        </div>";
                    }
                }
                break;

            case 'ask_feedback':
                $response = "<div class='p-4 bg-blue-50 rounded-lg border border-blue-200'>
                    <p class='font-semibold text-blue-800 mb-2'>Magbigay ng Feedback</p>
                    <p class='text-sm text-blue-700'>Pakilarawan ang iyong opinyon (min. 10 karakter).</p>
                    <p class='text-xs text-gray-600 mt-2'>Halimbawa: 'Maganda ang service, sana mas mabilis ang response.'</p>
                </div>";
                break;

            case 'confirm_feedback':
                if (isset($decoded['text'], $decoded['sentiment'])) {
                    $text = trim($decoded['text']);
                    $sentiment = ucfirst($decoded['sentiment'] ?? 'neutral');
                    
                    if (strlen($text) < 10 || trim(strip_tags($text)) === '') {
                        $response = "<div class='p-4 bg-yellow-50 rounded-lg border border-yellow-200'>
                            <p class='font-semibold text-yellow-800'>Kulang ang feedback.</p>
                            <p class='text-sm text-yellow-700'>Pakilarawan nang maayos (min. 10 karakter).</p>
                        </div>";
                        break;
                    }
                    
                    $response = "<div class='p-4 bg-green-50 rounded-lg border border-green-200 text-sm'>
                        <p class='font-bold text-green-800 mb-3'>Kumpirmasyon ng Feedback</p>
                        <p><strong>Feedback:</strong> <span class='italic text-sm'>“{$text}”</span></p>
                        <p><strong>Sentiment:</strong> <span class='font-medium text-green-700'>{$sentiment}</span></p>
                        <p class='font-semibold text-green-800 mt-4 pt-3 border-t border-green-200'>
                            I-save ito? Reply <strong>Yes</strong> o <strong>No/Change</strong>
                        </p>
                    </div>";
                } else {
                    $response = "<div class='p-3 bg-red-50 rounded-lg border border-red-200'>
                        <p class='text-red-800'>Hindi kumpleto ang feedback. Subukan ulit.</p>
                    </div>";
                }
                break;

            case 'file_feedback':
                if (isset($decoded['text'], $decoded['sentiment'])) {
                    $text = trim($decoded['text']);
                    $sentiment = ucfirst(trim($decoded['sentiment']) ?: 'neutral');

                    if (strlen($text) < 10) {
                        $response = "<div class='p-3 bg-red-50 rounded-lg border border-red-200'>
                            <p class='text-red-800'>Maikli ang feedback. Hindi nai-save.</p>
                        </div>";
                        break;
                    }

                    $query = "INSERT INTO feedback (user_id, feedback_text, sentiment) VALUES (?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $query);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "iss", $user_id, $text, $sentiment);
                        if (mysqli_stmt_execute($stmt)) {
                            $response = "<div class='p-4 bg-green-50 rounded-lg border border-green-200'>
                                <p class='font-bold text-green-800 mb-2'>Salamat sa feedback!</p>
                                <p class='text-sm text-green-700'>Nai-save na ito.</p>
                            </div>";
                            error_log("Feedback saved by user $user_id");
                        } else {
                            $response = "<div class='p-3 bg-red-50 rounded-lg border border-red-200'>
                                <p class='text-red-800'>Error sa pag-save.</p>
                            </div>";
                        }
                        mysqli_stmt_close($stmt);
                    }
                }
                break;

            case 'get_complaint_details':
                preg_match_all('/\b#?(\d+)\b/', $userText, $matches);
                $ids = array_unique(array_map('intval', $matches[1]));

                $wantsLatest = preg_match('/\b(latest|pinakabago|recent)\b/', $userText);
                $wantsAll = preg_match('/\b(lahat|all|lahat ng)\b/', $userText);

                if (!empty($ids)) {
                    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                    $query = "SELECT * FROM complaints WHERE complaint_id IN ($placeholders) AND user_id = ? ORDER BY FIELD(complaint_id, " . implode(',', $ids) . ")";
                    $stmt = mysqli_prepare($conn, $query);
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
                    $header = count($ids) == 1 ? "Complaint $idList:" : "Mga Complaint: $idList";
                    $response = !empty($cards)
                        ? "<div class='font-semibold mb-3 text-blue-700'>$header</div>" . implode('', $cards)
                        : "<div class='p-3 bg-gray-50 rounded-lg'>Wala akong mahanap na complaint.</div>";

                } elseif ($wantsLatest || empty(trim($userText))) {
                    $query = "SELECT * FROM complaints WHERE user_id = ? ORDER BY created_at DESC LIMIT 3";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "i", $user_id);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    $cards = []; $count = 0;
                    while ($row = mysqli_fetch_assoc($result)) {
                        $count++;
                        $label = $count == 1 ? 'Pinakabago' : ($count == 2 ? 'Pangalawa' : 'Pangatlo');
                        $cards[] = buildComplaintCard($row, $label);
                    }
                    $response = !empty($cards)
                        ? "<div class='font-semibold mb-3 text-blue-700'>3 Pinakabagong Reklamo:</div>" . implode('', $cards)
                        : "<div class='p-3 bg-gray-50 rounded-lg'>Wala ka pang complaint.</div>";
                    mysqli_stmt_close($stmt);

                } elseif ($wantsAll) {
                    $query = "SELECT * FROM complaints WHERE user_id = ? ORDER BY created_at DESC";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "i", $user_id);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    $cards = [];
                    while ($row = mysqli_fetch_assoc($result)) {
                        $cards[] = buildComplaintCard($row);
                    }
                    $response = !empty($cards)
                        ? "<div class='font-semibold mb-3 text-blue-700'>Lahat ng Reklamo:</div>" . implode('', $cards)
                        : "<div class='p-3 bg-gray-50 rounded-lg'>Wala ka pang complaint.</div>";
                    mysqli_stmt_close($stmt);
                }
                break;

            case 'get_feedback_history':
                $count = min((int)($decoded['count'] ?? 5), 10);
                $query = "SELECT feedback_text, sentiment, created_at FROM feedback WHERE user_id = ? ORDER BY created_at DESC LIMIT ?";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "ii", $user_id, $count);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $items = [];
                while ($row = mysqli_fetch_assoc($result)) {
                    $date = date('M j, Y', strtotime($row['created_at']));
                    $sentiment = ucfirst($row['sentiment']);
                    $items[] = "<div class='p-3 bg-gray-50 rounded-lg mb-2 border-l-4 border-blue-500'>
                                    <p class='text-sm italic'>\"{$row['feedback_text']}\"</p>
                                    <p class='text-xs text-gray-600 mt-1'>$sentiment • $date</p>
                                </div>";
                }
                $response = !empty($items)
                    ? "<div class='font-semibold text-blue-700 mb-2'>Iyong Feedback ($count):</div>" . implode('', $items)
                    : "<div class='p-3 bg-gray-50 rounded-lg'>Wala ka pang feedback.</div>";
                mysqli_stmt_close($stmt);
                break;

            case 'show_menu':
                $response = "
                <div class='p-4 bg-blue-50 rounded-xl border border-blue-200 text-sm'>
                    <p class='font-bold text-blue-800 mb-3'>Ano ang Pwedeng Gawin?</p>
                    <ul class='space-y-2 text-blue-700'>
                        <li><strong>Mag-file ng reklamo:</strong> 'May sira yung gripo ko'</li>
                        <li><strong>Tingnan ang status:</strong> '#123' o 'latest'</li>
                        <li><strong>Magbigay ng feedback:</strong> 'Maganda yung service'</li>
                        <li><strong>Tingnan ang feedback:</strong> 'ipakita feedback ko'</li>
                        <li><strong>Help:</strong> 'ano pwede ko gawin'</li>
                    </ul>
                </div>";
                break;

            case 'login_guide':
                $response = "<div class='p-4 bg-blue-50 rounded-lg border border-blue-200 text-sm'>
                    <p class='font-semibold text-blue-800 mb-3'>Para makapag-login o mag-register:</p>
                    <ul class='space-y-2 text-blue-700'>
                        <li><a href='../login.php' class='text-blue-600 underline font-medium'>Mag-login</a> - Kung may account ka na.</li>
                        <li><a href='../register.php' class='text-blue-600 underline font-medium'>Mag-register</a> - Gumawa ng bagong account.</li>
                    </ul>
                </div>";
                break;

            case 'get_board_of_directors':
                $items = '';
                foreach ($boardData['members'] as $m) {
                    $items .= "<li><strong>{$m['position']}</strong>: {$m['name']}</li>";
                }
                $response = "
                <div class='p-4 bg-blue-50 rounded-xl border border-blue-200 text-sm'>
                    <p class='font-bold text-blue-800 mb-3'>{$boardData['title']}</p>
                    <ul class='space-y-1 text-gray-700'>$items</ul>
                </div>";
                break;

            case 'get_management_team':
                $deptList = '';
                foreach ($managementData['department_managers']['list'] as $d) {
                    $deptList .= "<li><strong>{$d['dept']}</strong>: {$d['name']}</li>";
                }
                $divList = '';
                foreach ($managementData['division_managers'] as $dept => $divs) {
                    $divList .= "<div class='mt-3'><p class='font-medium text-indigo-600'>$dept</p><ul class='list-disc list-inside text-xs text-gray-700'>";
                    foreach ($divs as $div) {
                        $name = $div['name'] === 'Vacant' ? '<em>Vacant</em>' : $div['name'];
                        $divList .= "<li>{$div['division']}: $name</li>";
                    }
                    $divList .= "</ul></div>";
                }
                $response = "
                <div class='p-5 bg-indigo-50 rounded-xl border border-indigo-200 text-sm'>
                    <p class='font-bold text-indigo-800 mb-4'>Management Team</p>
                    <div class='mb-3'>
                        <p class='font-semibold text-indigo-700'>General Manager</p>
                        <p><strong>{$managementData['general_manager']['name']}</strong></p>
                    </div>
                    <div class='mb-3'>
                        <p class='font-semibold text-indigo-700'>Department Managers</p>
                        <ul class='space-y-1 text-gray-700'>$deptList</ul>
                    </div>
                    <div class='text-xs'>$divList</div>
                </div>";
                break;

            case 'get_current_gm':
                $gm = $managementData['general_manager']['name'];
                $response = "<div class='p-3 bg-green-50 rounded-lg border border-green-200 text-sm'>
                    <p class='font-semibold text-green-800'>Kasalukuyang GM:</p>
                    <p class='font-bold text-lg text-green-700'>{$gm}</p>
                </div>";
                break;

            case 'get_past_gm':
                $past = getPastGeneralManagers($conn);
                if (empty($past)) {
                    $response = "<div class='p-3 bg-gray-50 rounded-lg'>Wala pang dating GM.</div>";
                } else {
                    $list = '';
                    foreach ($past as $p) {
                        $end = $p['end_date'] ? " - " . date('M Y', strtotime($p['end_date'])) : '';
                        $list .= "<li><strong>{$p['name']}</strong> (" . date('M Y', strtotime($p['start_date'])) . "$end)</li>";
                    }
                    $response = "<div class='p-3 bg-yellow-50 rounded-lg text-sm'>
                        <p class='font-semibold'>Dating General Manager:</p>
                        <ul class='list-disc list-inside mt-1 space-y-1'>$list</ul>
                    </div>";
                }
                break;

            default:
                $response = trim(preg_replace('/\{[^}]+\}/', '', $rawResponse));
                break;
        }
    } else {
        $response = $rawResponse;
    }

    // === SANITIZE HTML ===
    $allowed = '<div><p><strong><em><span><a><br><b><i><u><ul><li><i class="fas';
    $response = strip_tags($response, $allowed);
    $response = preg_replace_callback('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', function($m) {
        $href = trim($m[1]);
        $text = trim(strip_tags($m[2]));
        return preg_match('/^(?!http|https|javascript)/i', $href) && $text ?
               '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" class="text-blue-600 underline" target="_blank" rel="noopener">' . $text . '</a>' :
               $text;
    }, $response);

    echo json_encode(['response' => $response]);

} catch (Throwable $e) {
    error_log("OpenAI Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Service temporarily unavailable.']);
}

mysqli_close($conn);
?>