<?php
if (!file_exists(__DIR__ . '/config.php')) { header('Location: install.php'); exit; }
require __DIR__ . '/config.php';

// Load recent searches from cache DB
$recentSearches = [];
try {
    $db = new PDO('sqlite:' . CACHE_DB_PATH);
    $stmt = $db->query('SELECT company_name, company_number, cached_at FROM risk_cache ORDER BY cached_at DESC LIMIT 10');
    $recentSearches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Companies House Risk Checker</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="topbar">
  <div>
    <h1>Companies House Director Risk Checker</h1>
    <div class="sub">Check any UK company's directors for dissolved or liquidated companies in their history</div>
  </div>
  <a href="install.php" class="text-muted" style="font-size:12px">Setup</a>
</div>

<div class="container" style="max-width:820px">

  <div class="card">
    <h2>Search</h2>
    <div class="form-row">
      <div style="flex:1">
        <label>Company name</label>
        <input type="text" id="company-input" placeholder="e.g. Tesco PLC" onkeydown="if(event.key==='Enter')searchCompany()">
      </div>
      <div style="align-self:flex-end">
        <button class="btn btn-primary" onclick="searchCompany()">Search</button>
      </div>
    </div>
  </div>

  <!-- Search results picker -->
  <div class="card" id="search-results-card" style="display:none">
    <h2>Select Company</h2>
    <div id="search-results-list"></div>
  </div>

  <div class="spinner" id="spinner">⏳ Fetching director history...</div>
  <div id="results"></div>

  <?php if ($recentSearches): ?>
  <div class="card">
    <h2>Recent Searches</h2>
    <table>
      <thead><tr><th>Company</th><th>Number</th><th>Checked</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($recentSearches as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['company_name']) ?></td>
          <td class="mono text-muted"><?= htmlspecialchars($r['company_number']) ?></td>
          <td class="text-muted" style="font-size:12px"><?= date('d M Y H:i', strtotime($r['cached_at'])) ?></td>
          <td><button class="btn btn-secondary" style="padding:4px 10px;font-size:12px" onclick="loadCached('<?= htmlspecialchars($r['company_number']) ?>', '<?= htmlspecialchars($r['company_name']) ?>')">View</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div>

<script>
function searchCompany() {
  const name = document.getElementById('company-input').value.trim();
  if (!name) return;
  document.getElementById('search-results-card').style.display = 'none';
  document.getElementById('results').innerHTML = '';
  document.getElementById('spinner').classList.add('show');

  fetch('process.php?action=search&q=' + encodeURIComponent(name))
    .then(r => r.json())
    .then(data => {
      document.getElementById('spinner').classList.remove('show');
      if (data.error) { document.getElementById('results').innerHTML = `<div class="alert alert-error">${data.error}</div>`; return; }
      if (!data.results || data.results.length === 0) {
        document.getElementById('results').innerHTML = '<div class="alert alert-warn">No companies found. Try a different name.</div>'; return;
      }
      showPicker(data.results);
    })
    .catch(e => { document.getElementById('spinner').classList.remove('show'); alert('Error: ' + e.message); });
}

function showPicker(results) {
  const list = results.map(c => `
    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #2d3148">
      <div>
        <div style="font-weight:600">${c.title}</div>
        <div class="text-muted" style="font-size:12px">${c.company_number} · ${c.company_status || 'unknown status'} · ${c.company_type || ''}</div>
      </div>
      <button class="btn btn-primary" style="padding:6px 14px;font-size:12px" onclick="checkCompany('${c.company_number}', '${c.title.replace(/'/g,'')}')">Check</button>
    </div>`).join('');
  document.getElementById('search-results-list').innerHTML = list;
  document.getElementById('search-results-card').style.display = 'block';
}

function checkCompany(number, name) {
  document.getElementById('search-results-card').style.display = 'none';
  document.getElementById('results').innerHTML = '';
  document.getElementById('spinner').classList.add('show');
  fetch('process.php?action=check&number=' + encodeURIComponent(number) + '&name=' + encodeURIComponent(name))
    .then(r => r.json())
    .then(renderReport)
    .catch(e => { document.getElementById('spinner').classList.remove('show'); alert('Error: ' + e.message); });
}

function loadCached(number, name) {
  document.getElementById('results').innerHTML = '';
  document.getElementById('spinner').classList.add('show');
  fetch('process.php?action=cached&number=' + encodeURIComponent(number) + '&name=' + encodeURIComponent(name))
    .then(r => r.json())
    .then(renderReport)
    .catch(e => { document.getElementById('spinner').classList.remove('show'); alert('Error: ' + e.message); });
}

function renderReport(d) {
  document.getElementById('spinner').classList.remove('show');
  if (d.error) { document.getElementById('results').innerHTML = `<div class="alert alert-error">${d.error}</div>`; return; }

  const counts = { HIGH: 0, MEDIUM: 0, LOW: 0 };
  d.directors.forEach(dir => counts[dir.rating]++);

  let html = `<div class="stat-grid">
    <div class="stat"><div class="val">${d.directors.length}</div><div class="lbl">Directors</div></div>
    <div class="stat"><div class="val text-red">${counts.HIGH}</div><div class="lbl">HIGH Risk</div></div>
    <div class="stat"><div class="val text-amber">${counts.MEDIUM}</div><div class="lbl">MEDIUM Risk</div></div>
    <div class="stat"><div class="val text-green">${counts.LOW}</div><div class="lbl">LOW Risk</div></div>
  </div>`;

  const ratingBadge = {
    HIGH:   '<span class="badge badge-red">HIGH</span>',
    MEDIUM: '<span class="badge badge-amber">MEDIUM</span>',
    LOW:    '<span class="badge badge-green">LOW</span>',
  };

  const rows = d.directors.map(dir => {
    const flagged = (dir.flagged_companies || []).map(fc =>
      `<div style="font-size:11px;color:#f87171;margin-top:2px">↳ ${fc.name} — ${fc.status}</div>`
    ).join('');
    return `<tr class="${dir.rating === 'HIGH' ? 'row-red' : dir.rating === 'MEDIUM' ? 'row-orange' : 'row-green'}">
      <td>${ratingBadge[dir.rating] || dir.rating}</td>
      <td><div style="font-weight:600">${dir.name}</div>${flagged}</td>
      <td class="text-muted">${dir.total_appointments}</td>
      <td class="text-muted">${dir.flagged_companies ? dir.flagged_companies.length : 0}</td>
    </tr>`;
  }).join('');

  html += `<div class="card">
    <h2>${d.company_name}</h2>
    <table>
      <thead><tr><th>Risk</th><th>Director</th><th>Total Appointments</th><th>Flagged</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>
  </div>`;

  if (d.cached) {
    html += `<div class="text-muted" style="font-size:12px;text-align:right;margin-top:-12px">Cached result from ${d.cached_at}</div>`;
  }

  document.getElementById('results').innerHTML = html;
}
</script>
</body>
</html>
