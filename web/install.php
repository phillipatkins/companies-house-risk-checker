<?php
$checks = [];
$phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
$checks[] = ['label' => 'PHP ' . PHP_VERSION, 'ok' => $phpOk, 'note' => $phpOk ? 'Good.' : 'Need PHP 7.4+'];
$curlOk = function_exists('curl_init');
$checks[] = ['label' => 'PHP cURL', 'ok' => $curlOk, 'note' => $curlOk ? 'Enabled.' : 'Enable php-curl extension'];
$pdoOk = class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers());
$checks[] = ['label' => 'PHP PDO SQLite', 'ok' => $pdoOk, 'note' => $pdoOk ? 'Available.' : 'Enable php-sqlite3 / pdo_sqlite extension'];
$shellOk = function_exists('shell_exec');
$checks[] = ['label' => 'shell_exec', 'ok' => $shellOk, 'note' => $shellOk ? 'Enabled.' : 'Enable shell_exec in php.ini'];

// API key
$apiKey = trim($_POST['api_key'] ?? '');
$apiKeySet = !empty($apiKey);
$apiKeyOk = false;
$apiNote = 'Enter your free API key below (get one at developer.company-information.service.gov.uk)';
if ($apiKeySet) {
    // Test the key
    $ch = curl_init('https://api.company-information.service.gov.uk/search/companies?q=test&items_per_page=1');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $apiKey . ':', CURLOPT_TIMEOUT => 8]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $apiKeyOk = in_array($code, [200, 204]);
    $apiNote = $apiKeyOk ? 'API key verified ✓' : "Key test returned HTTP $code — check the key is correct";
}
$checks[] = ['label' => 'Companies House API Key', 'ok' => $apiKeyOk, 'note' => $apiNote];

// Cache DB
$cacheDir = __DIR__ . '/cache/';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
$cacheOk = is_writable($cacheDir);
$checks[] = ['label' => 'Cache folder', 'ok' => $cacheOk, 'note' => $cacheOk ? 'Writable.' : 'chmod 755 web/cache'];

$allOk = $phpOk && $curlOk && $pdoOk && $shellOk && $apiKeyOk && $cacheOk;

if ($allOk) {
    $dbPath = $cacheDir . 'risk_cache.sqlite';
    // Create DB and table
    $db = new PDO('sqlite:' . $dbPath);
    $db->exec('CREATE TABLE IF NOT EXISTS risk_cache (
        company_number TEXT PRIMARY KEY,
        company_name TEXT,
        result_json TEXT,
        cached_at TEXT
    )');
    $cfg = "<?php\ndefine('CH_API_KEY', " . var_export($apiKey, true) . ");\ndefine('CACHE_DB_PATH', " . var_export($dbPath, true) . ");\n";
    file_put_contents(__DIR__ . '/config.php', $cfg);
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Companies House Risk Checker — Setup</title><link rel="stylesheet" href="assets/style.css"></head><body>
<div class="topbar"><div><h1>Companies House Risk Checker — Setup</h1></div></div>
<div class="container" style="max-width:680px">
<div class="card">
  <h2>System Check</h2>
  <?php foreach ($checks as $c): ?>
  <div class="install-step">
    <div class="step-num <?= $c['ok'] ? 'done' : 'fail' ?>"><?= $c['ok'] ? '✓' : '✗' ?></div>
    <div class="step-content"><div class="step-title"><?= htmlspecialchars($c['label']) ?></div><div class="step-desc"><?= htmlspecialchars($c['note']) ?></div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <h2>API Key Setup</h2>
  <p class="text-muted" style="font-size:13px;margin-bottom:14px">
    Get a free API key from <a href="https://developer.company-information.service.gov.uk" target="_blank">developer.company-information.service.gov.uk</a> — takes 2 minutes, completely free.
  </p>
  <form method="POST">
    <label>Companies House API Key</label>
    <div style="display:flex;gap:10px;margin-top:6px">
      <input type="text" name="api_key" value="<?= htmlspecialchars($apiKey) ?>" placeholder="Paste your API key here" style="flex:1">
      <button type="submit" class="btn btn-primary">Test &amp; Save</button>
    </div>
  </form>
  <?php if ($allOk): ?>
  <div class="alert alert-ok" style="margin-top:14px">✓ All set. <a href="index.php">→ Open the tool</a></div>
  <?php elseif ($apiKeySet && !$apiKeyOk): ?>
  <div class="alert alert-error" style="margin-top:14px">API key check failed — make sure you copied it correctly.</div>
  <?php endif; ?>
</div>
</div></body></html>
