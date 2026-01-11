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
    if (!$conn) throw new Exception('DB connection failed');

    // === DYNAMIC GREETING ===
    $hour = (int)date('H');
    $greeting = $hour < 12 ? 'Magandang umaga' : ($hour < 18 ? 'Magandang hapon' : 'Magandang gabi');
    $engGreeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

    // === FETCH ALL ORGANIZATIONAL DATA FROM DB (people, positions, assignments) ===
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
    if (!$orgResult) throw new Exception('Org query failed: ' . mysqli_error($conn));
    
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

    // === FETCH ALL STATIC CONTENT FROM DB ===
    $staticQuery = "SELECT content_key, title, content FROM static_content WHERE language = 'en'";
    $staticResult = mysqli_query($conn, $staticQuery);
    if (!$staticResult) throw new Exception('Static content query failed: ' . mysqli_error($conn));
    $staticMap = [];
    while ($row = mysqli_fetch_assoc($staticResult)) {
        $staticMap[strtolower($row['content_key'])] = [
            'title' => $row['title'],
            'content' => nl2br($row['content'])  // Convert \n to <br> for HTML display
        ];
    }

    // Build static HTML (simple)
    $staticHtml = '';
    foreach ($staticMap as $key => $data) {
        $staticHtml .= "<strong>{$data['title']}:</strong> {$data['content']}<br><br>";
    }

    // === FETCH PUBLIC AGGREGATE DATA (e.g., from reports, complaints, feedback - anonymized stats) ===
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

    // Build stats HTML (simple list)
    $statsHtml = "<p>Narito ang ilang <strong>public statistics</strong>:</p><p style=\"margin:12px 0; line-height:1.8;\">
                    Total Complaints: <strong>$totalComplaints</strong><br>
                    Resolved: <strong>$resolvedComplaints</strong><br>
                    Avg Resolution Time: <strong>$avgResolutionDays days</strong><br><br>
                    Feedback: Positive <strong>{$feedbackStats['positive']}</strong> | Negative <strong>{$feedbackStats['negative']}</strong> | Neutral <strong>{$feedbackStats['neutral']}</strong> (Total: {$feedbackStats['total_feedback']})
                  </p>";

    // === MESSAGES & USER MESSAGE ===
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = file_get_contents('php://input');
    $messagesJson = null;
    if (strpos($contentType, 'application/json') !== false) {
        $data = json_decode($input, true);
        $messagesJson = $data['messages'] ?? null;
    } else {
        parse_str($input, $data);
        $messagesJson = $data['messages'] ?? $_POST['messages'] ?? null;
    }
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
    $lang = 'tl';  // Default Tagalog
    if ($userMessage) {
        $englishWords = ['who','what','general','manager','chairperson','secretary','treasurer','department','head','bill','account','register','create','sign up','where','location','office','address','statistics','stats','feedback','complaints','history','mission','vision','values'];
        $lowercase = strtolower($userMessage);
        foreach ($englishWords as $word) {
            if (strpos($lowercase, $word) !== false) { $isEnglish = true; break; }
        }
        if (!$isEnglish && preg_match('/^[a-zA-Z0-9\s\.\,\!\?]+$/', $userMessage)) $isEnglish = true;
        $lang = $isEnglish ? 'en' : 'tl';
    }
    $userLower = mb_strtolower($userMessage, 'UTF-8');

    // === NEW: CITIZEN'S CHARTER QUERY ===
    // Define patterns for each service (slug-based, flexible matching)
    $charterPatterns = [
        'estimate' => '/\b(estimate|halaga|application for estimate|filing of application for estimate|humiling ng estimate|proseso ng estimate)\b/iu',
        'connection' => '/\b(payment of application for new water service connection)\b/iu',
        'complaint' => '/\b(filing of complaint|reklamo|mag-file ng complaint|mag-report ng problema|filing of complaint or request)\b/iu',
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

    if ($matchedService) {
        usleep(1500000 + rand(0, 1000000));

        // Special handling for Water Rates (fetch fees from service_id=6 directly) - FIXED REGEX FOR METER EXTRACTION
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

            // Build Rates Tables (separate for Category A and B)
            $ratesHtml = "<h3 style=\"color:#1f2937; font-size:18px; margin-bottom:10px;\">$serviceTitle</h3>";
            if (!empty($fees)) {
                $ratesHtml .= "<p style=\"color:#6b7280; font-size:14px;\">Official Document - Calamba Water District. <a href=\"https://cwd.com.ph/water_rates.html\" target=\"_blank\" style=\"color:#2563eb;\">View Official Rates on CWD Website</a><br>Note: These are historical rates. Check CWD office for current updates.</p>";

                // Group fees by category and meter size - FIXED REGEX
                $groupedFees = ['Category A: Service Areas (Residential / Government)' => [], 'Category B: Service Areas (NHA, VLP, VPB, Major Homes)' => []];
                foreach ($fees as $fee) {
                    $cat = strpos($fee['fee_category'], 'NHA') !== false ? 'Category B: Service Areas (NHA, VLP, VPB, Major Homes)' : 'Category A: Service Areas (Residential / Government)';
                    $particular = $fee['particular'];
                    // FIXED: Better regex to handle '1 1/2"' and '1/2"'
                    if (preg_match('/^((?:\d+\s+)?\d+(?:\/\d+)?")?\s*(.*)$/u', $particular, $matches)) {
                        $meter = $matches[1] ?? '';
                        $desc = trim($matches[2]);
                        if ($meter) {  // Only if meter extracted
                            $groupedFees[$cat][$meter][$desc] = $fee['amount'];
                        }
                    }
                }

                // Meters order - with space for 1 1/2"
                $meters = ['1/2"', '3/4"', '1"', '1 1/2"', '2"'];

                foreach ($groupedFees as $category => $metersData) {
                    $ratesHtml .= "<div style=\"margin:20px 0;\"><h4 style=\"color:#1e40af; font-size:16px; margin-bottom:5px;\">$category</h4>";
                    $ratesHtml .= "<table style=\"width:100%; border-collapse:collapse; margin:10px 0; font-size:14px;\">";
                    $ratesHtml .= "<tr style=\"background:#f3f4f6;\"><th style=\"border:1px solid #d1d5db; padding:8px;\">Meter Size</th><th style=\"border:1px solid #d1d5db; padding:8px;\">Min Charge (1-10 cu.m.)</th><th style=\"border:1px solid #d1d5db; padding:8px;\">11-20 cu.m. (per cu.m.)</th><th style=\"border:1px solid #d1d5db; padding:8px;\">21-30 cu.m. (per cu.m.)</th><th style=\"border:1px solid #d1d5db; padding:8px;\">31-40 cu.m. (per cu.m.)</th><th style=\"border:1px solid #d1d5db; padding:8px;\">41+ cu.m. (per cu.m.)</th></tr>";

                    foreach ($meters as $meter) {
                        if (isset($metersData[$meter])) {
                            $row = "<tr><td style=\"border:1px solid #d1d5db; padding:8px; font-weight:bold;\">" . htmlspecialchars($meter) . "</td>";
                            $row .= "<td style=\"border:1px solid #d1d5db; padding:8px; text-align:right;\">₱" . number_format($metersData[$meter]['Minimum Charge (1-10 m³)'] ?? 0, 2) . "</td>";
                            $row .= "<td style=\"border:1px solid #d1d5db; padding:8px; text-align:right;\">₱" . number_format($metersData[$meter]['11-20 m³ (per m³)'] ?? 0, 2) . "</td>";
                            $row .= "<td style=\"border:1px solid #d1d5db; padding:8px; text-align:right;\">₱" . number_format($metersData[$meter]['21-30 m³ (per m³)'] ?? 0, 2) . "</td>";
                            $row .= "<td style=\"border:1px solid #d1d5db; padding:8px; text-align:right;\">₱" . number_format($metersData[$meter]['31-40 m³ (per m³)'] ?? 0, 2) . "</td>";
                            $row .= "<td style=\"border:1px solid #d1d5db; padding:8px; text-align:right;\">₱" . number_format($metersData[$meter]['41+ m³ (per m³)'] ?? 0, 2) . "</td></tr>";
                            $ratesHtml .= $row;
                        }
                    }
                    $ratesHtml .= "</table><p style=\"text-align:right; font-size:12px; color:#9ca3af;\">* All figures in Philippine Peso (PHP)</p></div>";
                }
            } else {
                $ratesHtml .= "<p>No rates data available right now. Please visit the CWD office for the latest.</p>";
            }

            // Disclaimer
            $disclaimer = "<div style=\"background:#fffbeb; border-left:4px solid #f59e0b; padding:12px; margin:15px 0; border-radius:4px;\"><p style=\"margin:0; color:#92400e; font-size:14px;\"><strong>DISCLAIMER:</strong> These rates are subject to adjustments based on national government mandates. For official billing concerns, please coordinate with the <strong>Calamba Water District Billing Department</strong>.</p><p style=\"margin:5px 0 0 0; color:#92400e; font-size:14px;\">Last verified: As per official CWD website (July 2010 rates still in effect).</p></div>";

            $engResponse = "$engGreeting! Here's the <strong>Water Rates</strong> info:<br><br>$ratesHtml$disclaimer<br><br>Need help calculating your bill or anything else?";
            $tlResponse = "$greeting! Narito ang <strong>Mga Rate ng Tubig</strong>:<br><br>$ratesHtml$disclaimer<br><br>Kailangan mo ba ng tulong sa pagkalkula ng bill mo o iba pa?";
            $response = $isEnglish ? $engResponse : $tlResponse;

            echo json_encode(['response' => $response]);
            mysqli_close($conn);
            exit;
        }

        // Existing logic for other services (unchanged) - but now includes violations-penalties via general fetch
        // Fetch service details
        $serviceQuery = "SELECT * FROM citizen_charter_services WHERE slug = ?";
        $stmt = mysqli_prepare($conn, $serviceQuery);
        mysqli_stmt_bind_param($stmt, 's', $matchedService);
        mysqli_stmt_execute($stmt);
        $serviceResult = mysqli_stmt_get_result($stmt);
        $service = mysqli_fetch_assoc($serviceResult);
        if (!$service) {
            throw new Exception('Service not found');
        }

        // Special handling for Violations and Penalties (fetch from violations_penalties table)
        if ($matchedService === 'violations-penalties') {
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

            // Build Penalties Table
            $charterHtml = "<h3 style=\"color:#1f2937; font-size:18px; margin-bottom:10px;\">$serviceTitle</h3>";
            if (!empty($penalties)) {
                $charterHtml .= "<p style=\"color:#6b7280; font-size:14px;\">Official Document - Calamba Water District. <a href=\"https://cwd.com.ph/water_rates.html\" target=\"_blank\" style=\"color:#2563eb;\">View Official Policies on CWD Website</a></p>";
                $charterHtml .= "<table style=\"width:100%; border-collapse:collapse; margin:10px 0; font-size:14px;\">";
                $charterHtml .= "<tr style=\"background:#f3f4f6;\"><th style=\"border:1px solid #d1d5db; padding:8px;\">Offenses</th><th colspan=\"3\" style=\"border:1px solid #d1d5db; padding:8px;\">Residential</th><th colspan=\"3\" style=\"border:1px solid #d1d5db; padding:8px;\">Commercial</th></tr>";
                $charterHtml .= "<tr style=\"background:#e5e7eb;\"><th style=\"border:1px solid #d1d5db; padding:8px;\"></th><th style=\"border:1px solid #d1d5db; padding:8px;\">1st</th><th style=\"border:1px solid #d1d5db; padding:8px;\">2nd</th><th style=\"border:1px solid #d1d5db; padding:8px;\">3rd</th><th style=\"border:1px solid #d1d5db; padding:8px;\">1st</th><th style=\"border:1px solid #d1d5db; padding:8px;\">2nd</th><th style=\"border:1px solid #d1d5db; padding:8px;\">3rd</th></tr>";

                $currentMainOffense = '';
                foreach ($penalties as $penalty) {
                    $fullOffense = $penalty['sub_offense'] ? $penalty['offense'] . ': ' . $penalty['sub_offense'] : $penalty['offense'];
                    if ($fullOffense !== $currentMainOffense) {
                        if ($currentMainOffense && $penalty['sub_offense']) {
                            $charterHtml .= "<tr style=\"background:#fef2f2;\"><td colspan=\"7\" style=\"border:1px solid #d1d5db; padding:8px; text-align:center; font-style:italic; color:#dc2626;\">Meter Tampering</td></tr>";
                        }
                        $charterHtml .= "<tr><th style=\"border:1px solid #d1d5db; padding:8px; text-align:left; font-weight:bold;\">" . htmlspecialchars($fullOffense) . "</th>";
                        $charterHtml .= "<td style=\"border:1px solid #d1d5db; padding:8px; text-align:right;\">₱" . number_format($penalty['residential_1st'], 2) . "</td>";
                        $charterHtml .= "<td style=\"border:1px solid #d1d5db; padding:8px; text-align:right;\">₱" . number_format($penalty['residential_2nd'], 2) . "</td>";
                        $charterHtml .= "<td style=\"border:1px solid #d1d5db; padding:8px; text-align:right;\">₱" . number_format($penalty['residential_3rd'], 2) . "</td>";
                        $charterHtml .= "<td style=\"border:1px solid #d1d5db; padding:8px; text-align:right;\">₱" . number_format($penalty['commercial_1st'], 2) . "</td>";
                        $charterHtml .= "<td style=\"border:1px solid #d1d5db; padding:8px; text-align:right;\">₱" . number_format($penalty['commercial_2nd'], 2) . "</td>";
                        $charterHtml .= "<td style=\"border:1px solid #d1d5db; padding:8px; text-align:right;\">₱" . number_format($penalty['commercial_3rd'], 2) . "</td></tr>";
                        if ($penalty['notes']) {
                            $charterHtml .= "<tr><td colspan=\"7\" style=\"border:1px solid #d1d5db; padding:8px; text-align:center; font-style:italic; color:#059669;\">" . htmlspecialchars($penalty['notes']) . "</td></tr>";
                        }
                        $currentMainOffense = $fullOffense;
                    }
                }
                $charterHtml .= "</table><p style=\"text-align:right; font-size:12px; color:#9ca3af;\">* All figures in Philippine Peso (PHP)</p>";
            } else {
                $charterHtml .= "<p>No penalties data available right now. Please visit the CWD office for the latest.</p>";
            }

            // Disclaimer
            $disclaimer = "<div style=\"background:#fffbeb; border-left:4px solid #f59e0b; padding:12px; margin:15px 0; border-radius:4px;\"><p style=\"margin:0; color:#92400e; font-size:14px;\"><strong>DISCLAIMER:</strong> These penalties are subject to enforcement policies and may be adjusted based on national government mandates. For official inquiries or concerns, please coordinate with the <strong>Calamba Water District Enforcement Department</strong>.</p><p style=\"margin:5px 0 0 0; color:#92400e; font-size:14px;\">Last verified: As per official CWD website.</p></div>";

            $engResponse = "$engGreeting! Here's the details for <strong>Violations and Penalties</strong>:<br><br>$charterHtml$disclaimer<br><br>Need help with anything else?";
            $tlResponse = "$greeting! Narito ang detalye para sa <strong>Mga Paglabag at Parusa</strong>:<br><br>$charterHtml$disclaimer<br><br>Kailangan mo ba ng tulong sa iba pa?";
            $response = $isEnglish ? $engResponse : $tlResponse;

            echo json_encode(['response' => $response]);
            mysqli_close($conn);
            exit;
        }

        // Fetch Requirements (for non-special services)
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

        // Build formatted HTML response
        $charterHtml = "<h3 style=\"color:#1f2937; font-size:18px; margin-bottom:10px;\">" . htmlspecialchars($service['main_title']) . "</h3>";
        if ($service['subtitle']) $charterHtml .= "<p style=\"color:#6b7280; font-style:italic;\">" . htmlspecialchars($service['subtitle']) . "</p>";

        // Requirements Table (simple HTML table)
        if (!empty($requirements)) {
            $charterHtml .= "<p><strong>Requirements:</strong></p><table style=\"width:100%; border-collapse:collapse; margin:10px 0; font-size:14px;\">";
            $charterHtml .= "<tr style=\"background:#f3f4f6;\"><th style=\"border:1px solid #d1d5db; padding:8px; text-align:left;\">Item</th></tr>";
            foreach ($requirements as $req) {
                $section = $req['section_title'] ? "<strong>" . htmlspecialchars($req['section_title']) . ":</strong><br>" : '';
                $charterHtml .= "<tr><td style=\"border:1px solid #d1d5db; padding:8px;\">" . $section . htmlspecialchars($req['requirement_text']) . "</td></tr>";
            }
            $charterHtml .= "</table>";
        }

        // Procedures Table
        if (!empty($procedures)) {
            $charterHtml .= "<p><strong>Procedure:</strong></p><table style=\"width:100%; border-collapse:collapse; margin:10px 0; font-size:14px;\">";
            $charterHtml .= "<tr style=\"background:#f3f4f6;\"><th style=\"border:1px solid #d1d5db; padding:8px;\">Step</th><th style=\"border:1px solid #d1d5db; padding:8px;\">Description</th><th style=\"border:1px solid #d1d5db; padding:8px;\">Time/Fee</th><th style=\"border:1px solid #d1d5db; padding:8px;\">Responsible</th><th style=\"border:1px solid #d1d5db; padding:8px;\">Location</th></tr>";
            foreach ($procedures as $proc) {
                $timeFee = ($proc['processing_time'] ? htmlspecialchars($proc['processing_time']) : '') . ($proc['fee'] ? '<br>₱' . number_format($proc['fee'], 2) : '');
                $charterHtml .= "<tr><td style=\"border:1px solid #d1d5db; padding:8px;\">" . htmlspecialchars($proc['step_number']) . "</td><td style=\"border:1px solid #d1d5db; padding:8px;\">" . htmlspecialchars($proc['description']) . "</td><td style=\"border:1px solid #d1d5db; padding:8px; text-align:center;\">$timeFee</td><td style=\"border:1px solid #d1d5db; padding:8px;\">" . htmlspecialchars($proc['responsible']) . "</td><td style=\"border:1px solid #d1d5db; padding:8px;\">" . htmlspecialchars($proc['location']) . "</td></tr>";
            }
            if ($service['total_time']) $charterHtml .= "<tr style=\"background:#e5e7eb; font-weight:bold;\"><td colspan=\"2\">Total Time:</td><td>" . htmlspecialchars($service['total_time']) . "</td><td colspan=\"2\"></td></tr>";
            if ($service['total_fee']) $charterHtml .= "<tr style=\"background:#e5e7eb; font-weight:bold;\"><td colspan=\"2\">Total Fee:</td><td>₱" . number_format($service['total_fee'], 2) . "</td><td colspan=\"2\"></td></tr>";
            $charterHtml .= "</table>";
        }

        // Fees Table (if any)
        if (!empty($fees)) {
            $charterHtml .= "<p><strong>Fees:</strong></p><table style=\"width:100%; border-collapse:collapse; margin:10px 0; font-size:14px;\">";
            $charterHtml .= "<tr style=\"background:#f3f4f6;\"><th style=\"border:1px solid #d1d5db; padding:8px;\">Category</th><th style=\"border:1px solid #d1d5db; padding:8px;\">Particular</th><th style=\"border:1px solid #d1d5db; padding:8px;\">Amount (₱)</th></tr>";
            foreach ($fees as $fee) {
                $charterHtml .= "<tr><td style=\"border:1px solid #d1d5db; padding:8px;\">" . ($fee['fee_category'] ? htmlspecialchars($fee['fee_category']) : '') . "</td><td style=\"border:1px solid #d1d5db; padding:8px;\">" . htmlspecialchars($fee['particular']) . "</td><td style=\"border:1px solid #d1d5db; padding:8px; text-align:right;\">" . ($fee['amount'] ? number_format($fee['amount'], 2) : 'Varies') . "</td></tr>";
            }
            $charterHtml .= "</table>";
        }

        // Remarks
        if (!empty($remarks)) {
            $charterHtml .= "<p><strong>Remarks:</strong></p><ul style=\"margin:10px 0; padding-left:20px;\">";
            foreach ($remarks as $remark) {
                $charterHtml .= "<li style=\"margin:5px 0; line-height:1.5;\">" . htmlspecialchars($remark) . "</li>";
            }
            $charterHtml .= "</ul>";
        }

        $engResponse = "$engGreeting! Here's the details for the <strong>Citizen's Charter: " . htmlspecialchars($service['sidebar_title']) . "</strong>:<br><br>$charterHtml<br><br>Need help with anything else?";
        $tlResponse = "$greeting! Narito ang detalye para sa <strong>Citizen's Charter: " . htmlspecialchars($service['sidebar_title']) . "</strong>:<br><br>$charterHtml<br><br>Kailangan mo ba ng tulong sa iba pa?";
        $response = $isEnglish ? $engResponse : $tlResponse;

        echo json_encode(['response' => $response]);
        mysqli_close($conn);
        exit;
    }

    // === 1. CREATE ACCOUNT ===
    if (preg_match('/\b(create|gumawa|gagawa|mag[- ]?register|mag[- ]?rehistro|sign\s*up|register|rehistro)\b/iu', $userLower)) {
        $linkText = $isEnglish ? 'Register Here!' : 'Mag-register Dito!';
        $registerLink = '<a href="register.php" style="color:#2563eb; text-decoration:underline; font-weight:700;" target="_blank">'.$linkText.'</a>';

        $response = $isEnglish
            ? "$engGreeting! To create an account, just click this → <strong>$registerLink</strong><br><br>After registering, you can file complaints, view your bill, pay online, and more!<br><br>Want me to guide you?"
            : "$greeting! Para makagawa ka ng account, i-click mo lang ‘to → <strong>$registerLink</strong><br><br>Pagkatapos mag-register, pwede ka nang mag-file ng reklamo, tingnan ang bill mo, magbayad online, at marami pang iba!<br><br>Gusto mo bang gabayan kita?";

        usleep(1600000 + rand(0, 900000));
        echo json_encode(['response' => $response]);
        mysqli_close($conn);
        exit;
    }

    // === ENHANCED: FULL ORG CHART QUERY ===
    if (preg_match('/\b(organization|org chart|structure|taas puno|organisasyon|staff|personnel|employees|management team|board members|lahat ng manager|full list)\b/iu', $userLower)) {
        usleep(1500000 + rand(0, 1000000));
        $response = $isEnglish
            ? "$engGreeting! Here's the full <strong>Organizational Structure</strong> of CWD:<br><br>$boardHtml$managementHtml<br><br>Need more details on anyone?"
            : "$greeting! Narito ang buong <strong>Organizational Structure</strong> ng CWD:<br><br>$boardHtml$managementHtml<br><br>Kailangan mo ba ng higit pang detalye sa sino man?";
        echo json_encode(['response' => $response]); mysqli_close($conn); exit;
    }

    // === ENHANCED: STATS QUERY ===
    if (preg_match('/\b(stats|statistics|report|data|bilang|count|feedback|complaints|resolved|resolution time)\b/iu', $userLower)) {
        usleep(1200000 + rand(0, 800000));
        $response = $isEnglish
            ? "$engGreeting! Here's some <strong>public statistics</strong> from CWD:<br><br>$statsHtml<br><br>Want the latest reports or more info?"
            : "$greeting! Narito ang ilang <strong>public statistics</strong> mula sa CWD:<br><br>$statsHtml<br><br>Gusto mo ba ng latest reports o higit pang info?";
        echo json_encode(['response' => $response]); mysqli_close($conn); exit;
    }

    // === NEW: HISTORY ===
    if (preg_match('/\b(history|kasaysayan|about|background|origin|simula)\b/iu', $userLower)) {
        usleep(1000000 + rand(0, 500000));
        if (isset($staticMap['history'])) {
            $title = $staticMap['history']['title'];
            $content = $staticMap['history']['content'];
            $response = $isEnglish
                ? "$engGreeting! Here is the <strong>$title</strong> of Calamba Water District:<br><br>$content<br><br>Do you have any other questions?"
                : "$greeting! Narito ang <strong>$title</strong> ng Calamba Water District:<br><br>$content<br><br>May iba ka pa bang itatanong?";
        } else {
            $response = $isEnglish ? "$engGreeting! Sorry, I can't find the history right now. Try again later!" : "$greeting! Sorry po, hindi ko mahanap ang history ngayon. Subukan mo ulit mamaya!";
        }
        echo json_encode(['response' => $response]); mysqli_close($conn); exit;
    }

    // === NEW: MISSION ===
    if (preg_match('/\b(mission|layunin|purpose|hangarin)\b/iu', $userLower)) {
        usleep(900000 + rand(0, 400000));
        if (isset($staticMap['mission'])) {
            $title = $staticMap['mission']['title'];
            $content = $staticMap['mission']['content'];
            $response = $isEnglish
                ? "$engGreeting! Here is our <strong>$title</strong>:<br><br>$content<br><br>Do you have any other questions?"
                : "$greeting! Narito ang aming <strong>$title</strong>:<br><br>$content<br><br>May iba ka pa bang itatanong?";
        } else {
            $response = $isEnglish ? "$engGreeting! Sorry, I can't find the mission right now. Try again later!" : "$greeting! Sorry po, hindi ko mahanap ang mission ngayon. Subukan mo ulit mamaya!";
        }
        echo json_encode(['response' => $response]); mysqli_close($conn); exit;
    }

    // === NEW: VISION ===
    if (preg_match('/\b(vision|pannananagutan|paningin|layunin)\b/iu', $userLower)) {
        usleep(900000 + rand(0, 400000));
        if (isset($staticMap['vision'])) {
            $title = $staticMap['vision']['title'];
            $content = $staticMap['vision']['content'];
            $response = $isEnglish
                ? "$engGreeting! Here is our <strong>$title</strong>:<br><br>$content<br><br>Do you have any other questions?"
                : "$greeting! Narito ang aming <strong>$title</strong>:<br><br>$content<br><br>May iba ka pa bang itatanong?";
        } else {
            $response = $isEnglish ? "$engGreeting! Sorry, I can't find the vision right now. Try again later!" : "$greeting! Sorry po, hindi ko mahanap ang vision ngayon. Subukan mo ulit mamaya!";
        }
        echo json_encode(['response' => $response]); mysqli_close($conn); exit;
    }

    // === NEW: CORE VALUES ===
    if (preg_match('/\b(core values|mga halaga|values|halaga|paniniwala)\b/iu', $userLower)) {
        usleep(900000 + rand(0, 400000));
        if (isset($staticMap['core_values'])) {
            $title = $staticMap['core_values']['title'];
            $content = $staticMap['core_values']['content'];
            $response = $isEnglish
                ? "$engGreeting! Here are our <strong>$title</strong>:<br><br>$content<br><br>Do you have any other questions?"
                : "$greeting! Narito ang aming <strong>$title</strong>:<br><br>$content<br><br>May iba ka pa bang itatanong?";
        } else {
            $response = $isEnglish ? "$engGreeting! Sorry, I can't find the core values right now. Try again later!" : "$greeting! Sorry po, hindi ko mahanap ang core values ngayon. Subukan mo ulit mamaya!";
        }
        echo json_encode(['response' => $response]); mysqli_close($conn); exit;
    }

    // === NEW: QUALITY POLICY ===
    if (preg_match('/\b(quality policy|patakaran sa kalidad|quality|kalidad|policy|patakaran)\b/iu', $userLower)) {
        usleep(900000 + rand(0, 400000));
        if (isset($staticMap['quality_policy'])) {
            $title = $staticMap['quality_policy']['title'];
            $content = $staticMap['quality_policy']['content'];
            $response = $isEnglish
                ? "$engGreeting! Here is our <strong>$title</strong>:<br><br>$content<br><br>Do you have any other questions?"
                : "$greeting! Narito ang aming <strong>$title</strong>:<br><br>$content<br><br>May iba ka pa bang itatanong?";
        } else {
            $response = $isEnglish ? "$engGreeting! Sorry, I can't find the quality policy right now. Try again later!" : "$greeting! Sorry po, hindi ko mahanap ang quality policy ngayon. Subukan mo ulit mamaya!";
        }
        echo json_encode(['response' => $response]); mysqli_close($conn); exit;
    }

    // === 2. GENERAL MANAGER (now part of full org, but keep for direct query) ===
    if (preg_match('/\b(general manager|gm|sino ang gm|pangkalahatang tagapamahala)\b/iu', $userLower)) {
        usleep(800000 + rand(0, 700000));
        // Extract GM from full org
        $generalManager = 'Vacant';
        foreach ($fullOrgMap['management'] as $positions) {
            foreach ($positions as $pos) {
                if (stripos($pos['title'], 'General Manager') !== false) {
                    $generalManager = $pos['name'];
                    break 2;
                }
            }
        }
        $response = $isEnglish
            ? "$engGreeting! The <strong>General Manager</strong> of CWD is <span style=\"color:#2563eb;font-weight:700;\">$generalManager</span>. Any other questions?"
            : "$greeting! Ang <strong>General Manager</strong> ng CWD ay si <span style=\"color:#2563eb;font-weight:700;\">$generalManager</span>. May iba ka pa bang itatanong?";
        echo json_encode(['response' => $response]); mysqli_close($conn); exit;
    }

    // === 3. BOARD MEMBERS (enhanced to use full org) ===
    $boardPatterns = [
        'chairperson' => 'chairperson|tserman|chairman',
        'vice-chairperson' => 'vice|bise|vice[ -]chair',
        'corporate secretary' => 'secretary|sekretarya|corporate secretary',
        'treasurer' => 'treasurer|tagapag-ingat',
        'p.r.o.' => 'ppro|p\.r\.o\.|pro|p\.p\.r\.o\.|public relations'
    ];
    foreach ($boardPatterns as $title => $pat) {
        if (preg_match("/\b(sino ang )?($pat)\b/iu", $userLower)) {
            $name = 'Vacant';
            foreach ($fullOrgMap['board'] as $section => $positions) {
                if (stripos($section, $title) !== false) {
                    if (!empty($positions)) {
                        $name = $positions[0]['name'];
                    }
                    break;
                }
            }
            $displayTitle = ucwords(str_replace('_', ' ', $title));
            $response = $isEnglish
                ? "$engGreeting! The <strong>$displayTitle</strong> is <span style=\"color:#2563eb;font-weight:700;\">$name</span>. Any other questions?"
                : "$greeting! Ang <strong>$displayTitle</strong> ay si <span style=\"color:#2563eb;font-weight:700;\">$name</span>. May iba ka pa bang itatanong?";
            usleep(900000 + rand(0, 600000));
            echo json_encode(['response' => $response]); mysqli_close($conn); exit;
        }
    }

    // === 4. DEPARTMENT/DIVISION MANAGERS (enhanced to use full org) ===
    $deptPatterns = [
        'administrative' => 'administrative|admin|personnel|hr',
        'finance' => 'finance|accounting|budget',
        'commercial' => 'commercial|billing|customer|accounts|care',
        'technical services' => 'technical|engineering|services|pipeline|maintenance',
        'operations' => 'operations|production'
    ];
    foreach ($deptPatterns as $deptKey => $pat) {
        if (preg_match("/\b(manager|head|tagapamahala|oic).*\b($pat)\b/iu", $userLower)) {
            $name = 'Vacant';
            $displayDept = ucwords(str_replace('_', ' ', $deptKey)) . ' Manager';
            foreach ($fullOrgMap['management'] as $section => $positions) {
                if (stripos($section, $deptKey) !== false) {
                    if (!empty($positions)) {
                        $name = $positions[0]['name'];  // Assume first is head
                    }
                    break;
                }
            }
            $response = $isEnglish
                ? "$engGreeting! The <strong>$displayDept</strong> is <span style=\"color:#2563eb;font-weight:700;\">$name</span>. Any other questions?"
                : "$greeting! Ang <strong>$displayDept</strong> ay si <span style=\"color:#2563eb;font-weight:700;\">$name</span>. May iba ka pa bang itatanong?";
            usleep(900000 + rand(0, 600000));
            echo json_encode(['response' => $response]); mysqli_close($conn); exit;
        }
    }

    // === 5. COMPLAINT DETECTION ===
    $complaintKeywords = ['reklamo','complaint','problema','sira','sirang','putok','leak','tagas','walang tubig','brown tubig','mabaho','amoy','lasang','maanta','mataas ang bill','hindi tumutulo','mababa ang presyon','basang meter','hindi accurate','issue','concern','barado'];
    $isComplaint = false;
    foreach ($complaintKeywords as $kw) if (mb_strpos($userLower, $kw) !== false) { $isComplaint = true; break; }
    if ($isComplaint) {
        $registerLink = '<a href="register.php" style="color:#2563eb; text-decoration:underline; font-weight:700;" target="_blank">register here</a>';
        $loginLink    = '<a href="login.php" style="color:#2563eb; text-decoration:underline; font-weight:700;" target="_blank">log in</a>';
        $response = $isEnglish
            ? "$engGreeting! Got a water issue? Maybe you'd like to <strong>submit an official complaint</strong> to get it fixed right away?<br><br>No account yet? <strong>$registerLink</strong><br>Have an account? <strong>$loginLink</strong> → File Complaint<br><br>Want me to help you?"
            : "$greeting! Parang may problema ka sa tubig ah! Baka gusto mo nang <strong>mag-submit ng opisyal na reklamo</strong> para maayos agad?<br><br>Wala ka pang account → <strong>$registerLink</strong><br>May account ka na → <strong>$loginLink</strong> tapos mag-file ng reklamo<br><br>Gusto mo bang gabayan kita?";
        usleep(1200000 + rand(0, 800000));
        echo json_encode(['response' => $response]); mysqli_close($conn); exit;
    }

    // === 6. OFFICE LOCATION + GOOGLE MAP EMBED ===
    if (preg_match('/\b(saan|nasaan|located|address|location|office|map|direksyon|punta|cwd office|saan ang cwd)\b/iu', $userLower)) {
        usleep(1300000 + rand(0, 700000));
        $gmapEmbed = '<iframe src="https://www.google.com/maps/embed?pb=!1m14!1m8!1m3!1d15472.411686808355!2d121.1576812!3d14.1887497!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x33bd63dde4221d71%3A0x2b48f46c8c2e3e91!2sCalamba%20Water%20District!5e0!3m2!1sen!2sph!4v1763387982920!5m2!1sen!2sph" width="100%" height="420" style="border:0; border-radius:12px; margin:15px 0; box-shadow:0 4px 12px rgba(0,0,0,0.15);" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>';

        $response = $isEnglish
            ? "$engGreeting! The Calamba Water District main office is located at:<br><br>
               <strong>Lake View Subd., St Paul St, Calamba, 4027 Laguna</strong><br><br>
               Here’s the exact location on Google Maps:<br>$gmapEmbed<br>
               Open Monday–Friday, 8:00 AM – 5:00 PM"
            : "$greeting! Narito po ang <strong>Calamba Water District Main Office</strong>:<br><br>
               <strong>Lake View Subd., St Paul St, Calamba, 4027 Laguna</strong><br><br>
               Eto po ang exact location sa Google Maps:<br>$gmapEmbed<br>
               Bukas po Lunes hanggang Biyernes, 8:00 AM – 5:00 PM";

        echo json_encode(['response' => $response]);
        mysqli_close($conn);
        exit;
    }

    // === OPENAI FALLBACK (now with full org, stats, and static) ===
    $systemPrompt = [
        'role' => 'system',
        'content' => "You are Kuya Daloy — friendly CWD AquaSense assistant sa Calamba, Laguna.\n\nFull Org Structure:\n$boardHtml\n$managementHtml\nPublic Stats:\n$statsHtml\nStatic Info:\n$staticHtml\nReply in ".($isEnglish?"English":"Tagalog o Taglish").". Keep answers short, warm, at helpful. Use simple HTML like <strong> for bold, <br> for lines. Always end with a question."
    ];

    if (empty($messages) || $messages[0]['role'] !== 'system') {
        array_unshift($messages, $systemPrompt);
    } else {
        $messages[0] = $systemPrompt;
    }

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

    $botResponse = json_decode($response, true)['choices'][0]['message']['content'] ?? 'Sorry po, hindi ko po gets.';
    echo json_encode(['response' => trim($botResponse)]);

} catch (Exception $e) {
    error_log('Chatbot Error from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

if (isset($conn)) mysqli_close($conn);
?>