<?php
// /api/chat.php — JSON-only endpoint with strong error handling

ob_start(); // catch accidental output

// PHP errors → log file (never to client)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/chat_error.log');

// CORS / JSON headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Method guard
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Composer autoload (optional)
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoloadPath)) {
    try { require_once $autoloadPath; } catch (Throwable $e) {
        error_log("Autoload error: ".$e->getMessage());
    }
}

// Load OPENAI_API_KEY (dotenv → env → .env manual → env.php)
try {
    if (class_exists('Dotenv\\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();
    }
} catch (Throwable $e) {
    error_log("Dotenv error: ".$e->getMessage());
}
$openaiApiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?? null;
if (!$openaiApiKey) {
    $envFile = __DIR__ . '/../.env';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if (strpos($line, 'OPENAI_API_KEY=') === 0) {
                $openaiApiKey = trim(substr($line, strlen('OPENAI_API_KEY=')), "\"' \t");
                break;
            }
        }
    }
}
if (!$openaiApiKey && is_file(__DIR__ . '/../env.php')) {
    try {
        require_once __DIR__ . '/../env.php';
        $openaiApiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?? null;
    } catch (Throwable $e) {
        error_log("env.php error: ".$e->getMessage());
    }
}

ob_clean();
if (!$openaiApiKey) {
    error_log("API key missing");
    http_response_code(500);
    echo json_encode(['error' => 'Server misconfig: OPENAI_API_KEY not found']);
    exit;
}

// Read JSON body
$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}
$userMessage = trim((string)($input['message'] ?? ''));
if ($userMessage === '') {
    http_response_code(400);
    echo json_encode(['error' => 'No message provided']);
    exit;
}
error_log("Chat request: ".$userMessage);

// Prepare payload
$payload = [
    'model' => 'gpt-3.5-turbo',
    'messages' => [
        [
            'role' => 'system',
            'content' => "You are AquaSense Assistant, a helpful AI for water management services. Keep responses concise (<=100 words), friendly, and focused on billing, usage, complaints, conservation tips, and account help. You may reply in English or Tagalog. End with a question."
        ],
        ['role' => 'user', 'content' => $userMessage],
    ],
    'max_tokens' => 150,
    'temperature' => 0.7,
];

// Call OpenAI
$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $openaiApiKey,
    ],
    CURLOPT_TIMEOUT => 45,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

ob_clean(); // ensure **only** JSON goes out

if ($curlErr) {
    error_log("cURL error: ".$curlErr);
    http_response_code(502);
    echo json_encode(['error' => 'Network problem contacting AI']);
    exit;
}

// Friendly messages for common codes
if ($httpCode === 401 || $httpCode === 403) {
    error_log("Auth error ($httpCode): ".$response);
    http_response_code($httpCode);
    echo json_encode(['error' => 'Auth error with AI provider. Check API key.']);
    exit;
}
if ($httpCode === 429) {
    error_log("Rate limited (429): ".$response);
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit reached. Please wait a moment then try again.']);
    exit;
}
if ($httpCode < 200 || $httpCode >= 300) {
    error_log("OpenAI HTTP $httpCode: ".$response);
    http_response_code(502);
    echo json_encode(['error' => 'AI service temporarily unavailable.']);
    exit;
}

// Parse AI response
$data = json_decode($response, true);
$aiReply = $data['choices'][0]['message']['content'] ?? null;

if (!$aiReply) {
    error_log("Malformed OpenAI response: ".$response);
    http_response_code(502);
    echo json_encode(['error' => 'Invalid AI response']);
    exit;
}

echo json_encode(['reply' => trim($aiReply)], JSON_UNESCAPED_UNICODE);
