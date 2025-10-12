<?php
include '../db/db.php';
session_start();

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

// Catch ALL runtime errors and convert to JSON
set_exception_handler(function ($e) {
    error_log("Uncaught exception: " . $e->getMessage());
    echo json_encode(['error' => 'Server exception: ' . $e->getMessage()]);
    exit;
});
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    error_log("PHP error: $errstr in $errfile:$errline");
    echo json_encode(['error' => 'Server error: ' . $errstr]);
    exit;
});

// Load .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$apiKey = $_ENV['OPENAI_API_KEY'] ?? null;
if (!$apiKey) {
    echo json_encode(['error' => 'Missing OpenAI API key in .env']);
    exit;
}

// Get user_id from session
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'User not logged in']);
    exit;
}
$user_id = (int)$_SESSION['user_id'];

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['messages']) || !is_array($input['messages'])) {
    echo json_encode(['error' => 'No valid messages provided']);
    exit;
}

try {
    $client = \OpenAI::client($apiKey);

    // Enhanced system prompt for guided interactions with buttons
    $systemPrompt = [
        'role' => 'system',
        'content' => 'You are Kuya Daloy, a friendly and helpful water management assistant for CWD AquaSense. Respond in Taglish (mix of Tagalog and English), keep it fun and concise. Focus on: water bills, complaints filing, usage tips, leak detection, payment options. If unsure, suggest contacting support@aquasense.com.

            For common queries, suggest quick actions. If the user wants to file a complaint (keywords: complain, problema, issue, sumbong, report, file complaint, I want to file a complaint), guide them step-by-step with interactive buttons:

            1. Ask for category: Output ONLY this JSON: {"type":"buttons", "message":"Ano po ang category ng complaint niyo? Piliin sa mga ito:", "buttons":["Water Supply Issues", "Billing Concerns", "Meter Problems", "Leaks", "Other"]}

            2. After user selects category, ask for description: Output ONLY: {"type":"input", "message":"Sabihin mo po ang detailed description ng issue mo (hal. location, duration, etc.)."}

            3. After description, confirm: Output ONLY: {"type":"confirm", "message":"Confirm po: Category - [CATEGORY], Description - [DESCRIPTION]. Tama ba? Kung oo, piliin \'Yes\' to file.", "buttons":["Yes", "No"]}

            4. If user says "Yes" or confirms, output ONLY: {"action": "file_complaint", "category": "EXACT_CATEGORY", "description": "FULL_DESCRIPTION"}

            For other responses, use normal text. Keep guidance under 100 words. Use buttons format only when guiding complaints.'
    ];
    $messages = array_merge([$systemPrompt], $input['messages']);

    $result = $client->chat()->create([
        'model' => 'gpt-4o-mini', // Model of OpenAI chatbot
        'messages' => $messages,
        'max_tokens' => 250,  // Increased for structured outputs
        'temperature' => 0.7,
    ]);

    $rawAnswer = $result->choices[0]->message->content ?? 'Sorry, walang response.';

    // Check if this is a complaint filing action
    $filingData = null;
    $structuredResponse = null;
    $jsonStart = strpos($rawAnswer, '{');
    if ($jsonStart !== false) {
        $jsonEnd = strrpos($rawAnswer, '}') + 1;
        $jsonStr = substr($rawAnswer, $jsonStart, $jsonEnd - $jsonStart);
        $decoded = json_decode($jsonStr, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (isset($decoded['action']) && $decoded['action'] === 'file_complaint' && isset($decoded['category']) && isset($decoded['description'])) {
                $filingData = $decoded;
                
                // Insert into database
                $category = mysqli_real_escape_string($conn, $filingData['category']);
                $description = mysqli_real_escape_string($conn, $filingData['description']);
                
                $insertQuery = "INSERT INTO complaints (user_id, category, description, status, action_due) VALUES (?, ?, ?, 'Pending', DATE_ADD(CURDATE(), INTERVAL 7 DAY))";
                $stmt = mysqli_prepare($conn, $insertQuery);
                mysqli_stmt_bind_param($stmt, "iss", $user_id, $category, $description);
                
                if (mysqli_stmt_execute($stmt)) {
                    $complaint_id = mysqli_insert_id($conn);
                    $rawAnswer = json_encode([
                        'type' => 'success',
                        'message' => "Salamat po! Complaint mo ay na-file na. Reference #: {$complaint_id}. I-check mo sa My Complaints section. May update kaagad! 😊"
                    ]);
                } else {
                    error_log("Complaint insert failed: " . mysqli_error($conn));
                    $rawAnswer = json_encode([
                        'type' => 'error',
                        'message' => "Oops, may issue sa filing. Try ulit or contact support@aquasense.com. Sorry ha!"
                    ]);
                }
                mysqli_stmt_close($stmt);
            } else {
                // It's a structured response for UI (buttons, etc.)
                $structuredResponse = $decoded;
                $rawAnswer = json_encode($structuredResponse);
            }
        }
    }

    echo json_encode(['answer' => trim($rawAnswer)]);

} catch (Throwable $e) {
    $fullError = $e->getMessage();
    error_log("API request failed: $fullError | Key preview: " . substr($apiKey, 0, 10) . '...');
    
    // Handle common errors
    if (strpos($fullError, '401') !== false || strpos($fullError, 'invalid') !== false) {
        $fullError = 'Invalid API key—check your .env and OpenAI dashboard.';
    } elseif (strpos($fullError, '429') !== false) {
        $fullError = 'Rate limit hit—try again in 1 min or upgrade plan.';
    }
    
    echo json_encode(['error' => $fullError]);
}

if (isset($stmt)) mysqli_stmt_close($stmt);
mysqli_close($conn);
?>