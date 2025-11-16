<?php
date_default_timezone_set('Asia/Manila');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Kuya Daloy</title><style>body{font-family:Arial,sans-serif;max-width:600px;margin:50px auto;padding:20px;text-align:center;background:#f4f4f4;}.guide{background:white;padding:30px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.1);}</style></head><body><div class="guide"><h1>Kuya Daloy Chatbot</h1><p>CWD AquaSense assistant mo sa Calamba, Laguna.</p><p>Tanong ka lang — billing, leak, o info!</p><p><small>English or Tagalog? I’ll reply in your language!</small></p></div></body></html>';
    exit;
}

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    require_once __DIR__ . '/vendor/autoload.php';
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    $apiKey = $_ENV['OPENAI_API_KEY'] ?? null;
    if (!$apiKey) throw new Exception('OPENAI_API_KEY missing');

    $googleApiKey = $_ENV['GOOGLE_API_KEY'] ?? null;
    $cseId = $_ENV['GOOGLE_CSE_ID'] ?? null;

    require_once 'db/db.php';
    global $conn;

    // === DYNAMIC GREETING ===
    $hour = (int)date('H');
    $greeting = $hour < 12 ? 'Magandang umaga' : ($hour < 18 ? 'Magandang hapon' : 'Magandang gabi');

    // === FETCH DATA FROM DB ===
    // General Manager
    $gmQuery = "SELECT p.name FROM position_assignments pa
                JOIN positions pos ON pa.position_id = pos.position_id
                JOIN people p ON pa.person_id = p.person_id
                WHERE pos.title = 'General Manager' AND pa.is_current = 1";
    $gmResult = mysqli_query($conn, $gmQuery);
    $gmRow = mysqli_fetch_assoc($gmResult);
    $generalManager = $gmRow['name'] ?? 'Vacant';

    // Board of Directors
    $boardQuery = "SELECT pos.title AS position, p.name 
                   FROM position_assignments pa
                   JOIN positions pos ON pa.position_id = pos.position_id
                   JOIN people p ON pa.person_id = p.person_id
                   WHERE pos.category = 'board' AND pa.is_current = 1
                   ORDER BY pos.order_index";
    $boardResult = mysqli_query($conn, $boardQuery);
    $boardMap = [];
    $positionMap = [
        'Chairperson' => 'Chairperson',
        'Vice-Chairperson' => 'Vice-Chairperson',
        'Secretary' => 'Corporate Secretary',
        'Treasurer' => 'Treasurer',
        'P.P.R.O.' => 'P.P.R.O.'
    ];
    while ($row = mysqli_fetch_assoc($boardResult)) {
        $title = $positionMap[$row['position']] ?? $row['position'];
        $boardMap[strtolower($title)] = $row['name'];
    }

    // Department Managers
    $deptQuery = "SELECT pos.department AS dept, p.name 
                  FROM position_assignments pa
                  JOIN positions pos ON pa.position_id = pos.position_id
                  JOIN people p ON pa.person_id = p.person_id
                  WHERE pos.title = 'Department Manager' AND pa.is_current = 1";
    $deptResult = mysqli_query($conn, $deptQuery);
    $deptMap = [];
    $deptAliases = [
        'administrative' => ['admin', 'administrative'],
        'commercial' => ['commercial', 'billing', 'customer'],
        'engineering' => ['engineering', 'technical'],
        'finance' => ['finance', 'accounting'],
        'operations' => ['operations', 'production']
    ];
    while ($row = mysqli_fetch_assoc($deptResult)) {
        $dept = strtolower($row['dept']);
        $deptMap[$dept] = $row['name'];
        if (isset($deptAliases[$dept])) {
            foreach ($deptAliases[$dept] as $alias) {
                $deptMap[$alias] = $row['name'];
            }
        }
    }

    // === MESSAGES & USER MESSAGE ===
    $input = file_get_contents('php://input');
    $data = [];
    parse_str($input, $data);
    $messagesJson = $data['messages'] ?? $_POST['messages'] ?? null;
    if (!$messagesJson) throw new Exception('No messages');

    $messages = json_decode($messagesJson, true);
    if (!is_array($messages)) throw new Exception('Invalid messages');

    $userMessage = '';
    foreach (array_reverse($messages) as $msg) {
        if ($msg['role'] === 'user') {
            $userMessage = trim($msg['content']);
            break;
        }
    }

    // === LANGUAGE DETECTION ===
    $isEnglish = false;
    if ($userMessage) {
        $englishWords = ['who', 'what', 'general', 'manager', 'chairperson', 'secretary', 'treasurer', 'department', 'head', 'bill'];
        $lowercase = strtolower($userMessage);
        foreach ($englishWords as $word) {
            if (strpos($lowercase, $word) !== false) {
                $isEnglish = true;
                break;
            }
        }
        if (!$isEnglish && preg_match('/^[a-zA-Z0-9\s\.\,\!\?]+$/', $userMessage)) {
            $isEnglish = true;
        }
    }

    $lang = $isEnglish ? 'en' : 'tl';
    $userLower = strtolower($userMessage);

    // ==================================================================
    // === SMART SPECIFIC ANSWERS (BYPASS AI) ===
    // ==================================================================

    // 1. General Manager
    $gmKeywords = $lang === 'en' 
        ? ['general manager', 'gm', 'head of cwd', 'who is the general manager'] 
        : ['general manager', 'gm', 'pangkalahatang tagapamahala', 'sino ang gm', 'sino ang general manager'];
    if (preg_match('/\b(' . implode('|', $gmKeywords) . ')\b/i', $userLower)) {
        $response = $isEnglish
            ? "$greeting! The <strong>General Manager</strong> of CWD is <span style=\"color:#2563eb;\"><strong>$generalManager</strong></span>. May iba ka pa bang itatanong?"
            : "$greeting! Ang <strong>General Manager</strong> ng CWD ay si <span style=\"color:#2563eb;\"><strong>$generalManager</strong></span>. May iba ka pa bang itatanong?";
        echo json_encode(['response' => $response]);
        mysqli_close($conn);
        exit;
    }

    // 2. Specific Board Member
    foreach ($boardMap as $title => $name) {
        $titleEn = $title;
        $titleTl = [
            'chairperson' => 'chairperson|tserman',
            'vice-chairperson' => 'bise|vice',
            'corporate secretary' => 'secretary|sekretarya',
            'treasurer' => 'treasurer|tagapag-ingat',
            'p.p.r.o.' => 'ppro|public relations'
        ][$title] ?? $title;

        $pattern = $lang === 'en'
            ? "/\\b(who is the )?$titleEn\\b/i"
            : "/\\b(sino ang )?($titleTl)\\b/i";

        if (preg_match($pattern, $userLower)) {
            $displayTitle = ucwords($title);
            $response = $isEnglish
                ? "$greeting! The <strong>$displayTitle</strong> is <span style=\"color:#2563eb;\"><strong>$name</strong></span>. May iba ka pa bang itatanong?"
                : "$greeting! Ang <strong>$displayTitle</strong> ay si <span style=\"color:#2563eb;\"><strong>$name</strong></span>. May iba ka pa bang itatanong?";
            echo json_encode(['response' => $response]);
            mysqli_close($conn);
            exit;
        }
    }

    // 3. Department Manager
    foreach ($deptMap as $deptKey => $name) {
        $deptNames = $lang === 'en'
            ? [ucwords($deptKey) . ' Department']
            : [ucwords($deptKey) . ' Department', ucwords($deptKey)];

        $pattern = $lang === 'en'
            ? "/\\b(manager|head) (of|sa) (" . implode('|', $deptNames) . ")\\b/i"
            : "/\\b(manager|tagapamahala) (ng|sa) (" . implode('|', $deptNames) . ")\\b/i";

        if (preg_match($pattern, $userLower)) {
            $deptDisplay = ucwords($deptKey) . ' Department Manager';
            $response = $isEnglish
                ? "$greeting! The <strong>$deptDisplay</strong> is <span style=\"color:#2563eb;\"><strong>$name</strong></span>. May iba ka pa bang itatanong?"
                : "$greeting! Ang <strong>$deptDisplay</strong> ay si <span style=\"color:#2563eb;\"><strong>$name</strong></span>. May iba ka pa bang itatanong?";
            echo json_encode(['response' => $response]);
            mysqli_close($conn);
            exit;
        }
    }

    // 4. COMPLAINT REDIRECT (from previous)
    $complaintKeywords = ['reklamo','complaint','problema','sira','walang tubig','leak','mataas ang bill','issue'];
    $isComplaint = false;
    foreach ($complaintKeywords as $kw) {
        if (strpos($userLower, $kw) !== false) {
            $isComplaint = true; break;
        }
    }
    if ($isComplaint) {
        $registerLink = '<a href="register.php" style="color:#2563eb; text-decoration:underline; font-weight:600;">Register Here</a>';
        $response = $isEnglish
            ? "I understand you have a concern. Please $registerLink to file your complaint officially. We'll fix it ASAP! Need help registering?"
            : "May reklamo ka? Pwedeng mag-$registerLink para maayos na ma-file. Aayusin agad! Tulong sa pag-register?";
        echo json_encode(['response' => $response]);
        mysqli_close($conn);
        exit;
    }

    // ==================================================================
    // === PROCEED TO AI IF NO SPECIFIC MATCH ===
    // ==================================================================

    // Build full info for AI
    $boardLines = [];
    foreach ($boardMap as $title => $name) {
        $boardLines[] = "<strong>" . ucwords($title) . ":</strong> <span style=\"color:#2563eb;\">$name</span><br>";
    }
    $boardHtml = "<p>$greeting Narito ang <strong>Board of Directors</strong>:</p><p style=\"margin:12px 0; line-height:1.7; font-size:15px;\">" . implode("", $boardLines) . "</p>";

    $deptLines = [];
    foreach ($deptMap as $dept => $name) {
        if (in_array($dept, ['admin','commercial','engineering','finance','operations'])) {
            $deptLines[] = "<strong>" . ucwords($dept) . " Department Manager:</strong> <span style=\"color:#2563eb;\">$name</span><br>";
        }
    }
    $managementHtml = "<p>$greeting Narito ang <strong>Management Team</strong>:</p><p style=\"margin:12px 0; line-height:1.7; font-size:15px;\">
        <strong>General Manager:</strong> <span style=\"color:#2563eb;\">$generalManager</span><br>" . implode("", $deptLines) . "</p>";

    $systemPrompt = [
        'role' => 'system',
        'content' => "You are Kuya Daloy — CWD assistant.\n\n" .
                     "Use this for Board: $boardHtml\n" .
                     "Use this for Management: $managementHtml\n" .
                     "Reply in " . ($isEnglish ? "English" : "Tagalog/Taglish") . ". Keep short. End with question."
    ];

    if (empty($messages) || $messages[0]['role'] !== 'system') {
        array_unshift($messages, $systemPrompt);
    } else {
        $messages[0] = $systemPrompt;
    }

    // Web Search + OpenAI (same as before)
    if ($userMessage && strlen($userMessage) > 5 && $googleApiKey && $cseId) {
        $googleUrl = "https://www.googleapis.com/customsearch/v1?key=$googleApiKey&cx=$cseId&q=" . urlencode($userMessage) . "&num=3&hl=$lang&gl=ph";
        $googleResponse = @file_get_contents($googleUrl);
        if ($googleResponse) {
            $items = json_decode($googleResponse, true)['items'] ?? [];
            if ($items) {
                $snippets = array_map(fn($i) => ($i['title']??'') . ": " . ($i['snippet']??''), array_slice($items, 0, 3));
                $searchMsg = ['role' => 'system', 'content' => "WEB RESULTS:\n" . implode("\n\n", $snippets)];
                array_splice($messages, -1, 0, [$searchMsg]);
            }
        }
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.openai.com/v1/chat/completions',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
            'max_tokens' => 700,
            'temperature' => 0.8
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_TIMEOUT => 45
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception(json_decode($response, true)['error']['message'] ?? 'API error');
    }

    $botResponse = json_decode($response, true)['choices'][0]['message']['content'] ?? 'Sorry.';
    echo json_encode(['response' => trim($botResponse)]);

} catch (Exception $e) {
    error_log('Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

if (isset($conn)) mysqli_close($conn);
?>