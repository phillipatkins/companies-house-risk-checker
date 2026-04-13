<?php
header('Content-Type: application/json');
if (!file_exists(__DIR__ . '/config.php')) { echo json_encode(['error' => 'Run install.php first']); exit; }
require __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';

// Init DB
try {
    $db = new PDO('sqlite:' . CACHE_DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]); exit;
}

$baseUrl = 'https://api.company-information.service.gov.uk';
$apiKey  = CH_API_KEY;

function ch_get(string $path, string $apiKey, array $params = []): ?array {
    global $baseUrl;
    $url = $baseUrl . $path;
    if ($params) $url .= '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $apiKey . ':',
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 429) { echo json_encode(['error' => 'Rate limited — wait a moment and try again']); exit; }
    if ($code === 404) return null;
    return $body ? json_decode($body, true) : null;
}

if ($action === 'search') {
    $q = trim($_GET['q'] ?? '');
    if (!$q) { echo json_encode(['error' => 'No query']); exit; }
    $data = ch_get('/search/companies', $apiKey, ['q' => $q, 'items_per_page' => 5]);
    $items = $data['items'] ?? [];
    $results = array_map(fn($c) => [
        'title'          => $c['title'] ?? '',
        'company_number' => $c['company_number'] ?? '',
        'company_status' => $c['company_status'] ?? '',
        'company_type'   => $c['company_type'] ?? '',
    ], $items);
    echo json_encode(['results' => $results]);
    exit;
}

if ($action === 'cached') {
    $number = trim($_GET['number'] ?? '');
    $name   = trim($_GET['name'] ?? '');
    $stmt = $db->prepare('SELECT result_json, cached_at FROM risk_cache WHERE company_number = ?');
    $stmt->execute([$number]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $data = json_decode($row['result_json'], true);
        $data['cached'] = true;
        $data['cached_at'] = date('d M Y H:i', strtotime($row['cached_at']));
        echo json_encode($data);
    } else {
        echo json_encode(['error' => 'No cached result found']);
    }
    exit;
}

if ($action === 'check') {
    $number = trim($_GET['number'] ?? '');
    $name   = trim($_GET['name'] ?? '');
    if (!$number) { echo json_encode(['error' => 'No company number']); exit; }

    // Check cache (1 hour TTL)
    $stmt = $db->prepare('SELECT result_json, cached_at FROM risk_cache WHERE company_number = ? AND cached_at > datetime("now", "-1 hour")');
    $stmt->execute([$number]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cached) {
        $data = json_decode($cached['result_json'], true);
        $data['cached'] = true;
        $data['cached_at'] = date('d M Y H:i', strtotime($cached['cached_at']));
        echo json_encode($data);
        exit;
    }

    // Fetch officers
    $officersData = ch_get('/company/' . urlencode($number) . '/officers', $apiKey);
    $officers = array_filter($officersData['items'] ?? [], fn($o) =>
        str_contains(strtolower($o['officer_role'] ?? ''), 'director') && empty($o['resigned_on'])
    );

    $badStatuses = ['dissolved', 'liquidation', 'administration', 'receivership', 'voluntary-arrangement'];
    $heavy = ['liquidation', 'administration', 'receivership'];

    $directors = [];
    foreach ($officers as $officer) {
        $dirName = $officer['name'] ?? 'Unknown';
        $links = $officer['links']['officer']['appointments'] ?? '';
        $officerId = '';
        if (preg_match('/\/officers\/([^\/]+)\//', $links, $m)) {
            $officerId = $m[1];
        }
        if (!$officerId) continue;

        $appts = ch_get('/officers/' . urlencode($officerId) . '/appointments', $apiKey);
        $appointments = $appts['items'] ?? [];

        $score = 0;
        $flagged = [];
        foreach ($appointments as $appt) {
            $status = strtolower(str_replace(' ', '-', $appt['appointed_to']['company_status'] ?? ''));
            $matched = null;
            foreach ($badStatuses as $bs) {
                if (str_contains($status, $bs)) { $matched = $bs; break; }
            }
            if ($matched) {
                $weight = in_array($matched, $heavy) ? 2 : 1;
                $score += $weight;
                $flagged[] = [
                    'name'   => $appt['appointed_to']['company_name'] ?? 'Unknown',
                    'number' => $appt['appointed_to']['company_number'] ?? '',
                    'status' => $matched,
                ];
            }
        }

        $rating = $score === 0 ? 'LOW' : ($score <= 1 ? 'MEDIUM' : 'HIGH');
        $directors[] = [
            'name'               => $dirName,
            'rating'             => $rating,
            'total_appointments' => count($appointments),
            'flagged_companies'  => $flagged,
        ];
    }

    usort($directors, fn($a, $b) => ['HIGH' => 0, 'MEDIUM' => 1, 'LOW' => 2][$a['rating']] <=> ['HIGH' => 0, 'MEDIUM' => 1, 'LOW' => 2][$b['rating']]);

    $result = ['company_name' => $name ?: $number, 'company_number' => $number, 'directors' => $directors];

    // Cache result
    $stmt = $db->prepare('INSERT OR REPLACE INTO risk_cache (company_number, company_name, result_json, cached_at) VALUES (?, ?, ?, datetime("now"))');
    $stmt->execute([$number, $name, json_encode($result)]);

    echo json_encode($result);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
