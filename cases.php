<?php
session_start();

function sanitizeAgentExtension($value) {
    return preg_replace('/[^0-9*#+]/', '', (string)$value);
}

function appendAgentExtToUrl($url, $agentExtValue) {
    $agentExtValue = trim((string)$agentExtValue);
    if ($agentExtValue === '') {
        return $url;
    }
    $separator = (strpos($url, '?') === false) ? '?' : '&';
    return $url . $separator . 'ext=' . urlencode($agentExtValue);
}

$agentExt = '';
$rawExt = '';
if (isset($_GET['ext']) && $_GET['ext'] !== '') {
    $rawExt = $_GET['ext'];
} elseif (isset($_POST['ext']) && $_POST['ext'] !== '') {
    $rawExt = $_POST['ext'];
}

if ($rawExt !== '') {
    $agentExt = sanitizeAgentExtension($rawExt);
    if ($agentExt !== '') {
        $_SESSION['agent_ext'] = $agentExt;
        setcookie('agent_ext', $agentExt, time() + 31536000, '/');
    }
} elseif (!empty($_SESSION['agent_ext'])) {
    $agentExt = sanitizeAgentExtension($_SESSION['agent_ext']);
} elseif (!empty($_COOKIE['agent_ext'])) {
    $agentExt = sanitizeAgentExtension($_COOKIE['agent_ext']);
    if ($agentExt !== '') {
        $_SESSION['agent_ext'] = $agentExt;
    }
}

set_time_limit(120);

$serverName = "localhost";
$connectionOptions = array(
    "Database" => "nextccdb",
    "Uid" => "sa",
    "PWD" => '$olidus'
);
$conn = sqlsrv_connect($serverName, $connectionOptions);
if(!$conn) { die(print_r(sqlsrv_errors(), true)); }

function fetchServerDateContext() {
    $tzName = @date_default_timezone_get();
    try {
        $tz = new DateTimeZone($tzName ?: 'UTC');
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }

    $now = new DateTimeImmutable('now', $tz);
    return [$now, $tz];
}

// Close case (Open or Escalated)
if (isset($_GET['close_case'])) {
    $close_case = $_GET['close_case'];
    $update = "UPDATE mwcsp_caser SET status='Closed' WHERE case_number=?";
    sqlsrv_query($conn, $update, [$close_case]);
    header("Location: " . appendAgentExtToUrl('cases.php', $agentExt));
    exit;
}

$currentPage = basename($_SERVER['PHP_SELF']);

list($serverNowObj, $serverTimezone) = fetchServerDateContext();
$now = clone $serverNowObj;

// Fetch all rows for pie + pagination
$sqlAll = "SELECT CONVERT(VARCHAR(19), date_time, 120) AS date_time_str, * 
           FROM mwcsp_caser 
           ORDER BY 
             CASE 
               WHEN LOWER(status)='open' THEN 1
               WHEN LOWER(status)='escalated' THEN 2
               ELSE 3
             END,
             date_time ASC";
$stmtAll = sqlsrv_query($conn, $sqlAll);
$rows = [];
while($r = sqlsrv_fetch_array($stmtAll, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; }

// ---------- 5-slice pie time range ----------
$pie_range = isset($_GET['pie_range']) ? $_GET['pie_range'] : '7d';
$valid_ranges = ['today','7d','1m','3m','6m','12m','all'];
if (!in_array($pie_range, $valid_ranges)) $pie_range = '7d';

$pieStart = null;
switch ($pie_range) {
  case 'today': $pieStart = (clone $now)->setTime(0,0,0); break;
  case '7d':    $pieStart = (clone $now)->modify('-7 days'); break;
  case '1m':    $pieStart = (clone $now)->modify('-1 month'); break;
  case '3m':    $pieStart = (clone $now)->modify('-3 months'); break;
  case '6m':    $pieStart = (clone $now)->modify('-6 months'); break;
  case '12m':   $pieStart = (clone $now)->modify('-12 months'); break;
  case 'all':   $pieStart = null; break;
}

// Compute 5-slice status counts
$count_open_fresh = 0;   // <2h
$count_open_2h    = 0;   // >2h & <24h
$count_open_24h   = 0;   // ≥24h
$count_escalated  = 0;
$count_closed     = 0;

foreach ($rows as $row) {
    if (empty($row['date_time_str'])) continue;
    $rowDate = new DateTime($row['date_time_str'], $serverTimezone);
    if ($pieStart !== null && $rowDate < $pieStart) continue;
    if ($rowDate > $now) continue;

    $diff = $now->diff($rowDate);
    $ageHours = $diff->days * 24 + $diff->h;

    $status = strtolower((string)$row['status']);
    if ($status === 'closed') {
        $count_closed++;
    } elseif ($status === 'escalated') {
        $count_escalated++;
    } else {
        if ($ageHours >= 24)       $count_open_24h++;
        elseif ($ageHours > 2)     $count_open_2h++;
        else                       $count_open_fresh++;
    }
}

// ---------- Audio discovery ----------
function scanRemoteDir($url, $depth=0, $maxDepth=2, &$visited=[]) {
    $mp3_files = [];
    $url = rtrim($url,'/');

    if($depth > $maxDepth || in_array($url, $visited)) return [];
    $visited[] = $url;

    $context = stream_context_create(['http'=>['timeout'=>5]]);
    $html = @file_get_contents($url, false, $context);
    if($html === false) return [];

    preg_match_all('/href="([^"]+)"/i', $html, $matches);
    if(!empty($matches[1])){
        foreach($matches[1] as $link){
            if($link == '../' || $link == './') continue;

            if(preg_match('#^https?://#i', $link)) {
                $full_link = $link;
            } elseif(substr($link,0,1) === '/') {
                $full_link = 'http://192.168.1.154' . $link;
            } else {
                $full_link = $url . '/' . ltrim($link, '/');
            }

            if(preg_match('/\.mp3$/i', $link)){
                $mp3_files[] = $full_link;
            } elseif(substr($link,-1) == '/'){
                $mp3_files = array_merge($mp3_files, scanRemoteDir($full_link, $depth+1, $maxDepth, $visited));
            }
        }
    }
    return $mp3_files;
}
$audio_dir_url = "http://192.168.1.154/secrecord";
$mp3_files = scanRemoteDir($audio_dir_url);
$audioProxyBase = 'neo_audio.php';

// Build lookup tables for case metadata and related audio/phone mappings
$casesByNumber = [];
$casesByPhone  = [];
$audioByCase   = [];

foreach ($rows as $row) {
    $caseNumRaw = isset($row['case_number']) ? (string)$row['case_number'] : '';
    $caseNum = trim($caseNumRaw);
    if ($caseNum === '') {
        continue;
    }

    if (!isset($audioByCase[$caseNum])) {
        foreach ($mp3_files as $file) {
            if (stripos(basename($file), $caseNum) !== false) {
                $audioByCase[$caseNum] = $file;
                break;
            }
        }
    }

    if (!isset($audioByCase[$caseNum]) || $audioByCase[$caseNum] === '') {
        $audioByCase[$caseNum] = $audioProxyBase . '?case=' . rawurlencode($caseNum);
    }

    $caseData = [
        'case_number' => $caseNum,
        'date_time_str' => isset($row['date_time_str']) ? (string)$row['date_time_str'] : '',
        'status' => isset($row['status']) ? (string)$row['status'] : '',
        'spn' => isset($row['spn']) ? (string)$row['spn'] : '',
        'first_name' => isset($row['first_name']) ? (string)$row['first_name'] : '',
        'middle_name' => isset($row['middle_name']) ? (string)$row['middle_name'] : '',
        'family_name' => isset($row['family_name']) ? (string)$row['family_name'] : '',
        'phone_number' => isset($row['phone_number']) ? trim((string)$row['phone_number']) : '',
        'address' => isset($row['address']) ? (string)$row['address'] : '',
        'escalation_session_id' => isset($row['escalation_session_id']) ? (string)$row['escalation_session_id'] : '',
        'gender' => isset($row['gender']) ? (string)$row['gender'] : '',
        'disability' => isset($row['disability']) ? (string)$row['disability'] : '',
        'language' => isset($row['language']) ? (string)$row['language'] : '',
        'user_type' => isset($row['user_type']) ? (string)$row['user_type'] : '',
        'notes' => isset($row['notes']) ? (string)$row['notes'] : '',
        'informed_consent' => !empty($row['informed_consent']) ? 1 : 0,
    ];

    $casesByNumber[$caseNum] = $caseData;

    $phoneKey = $caseData['phone_number'];
    if ($phoneKey !== '') {
        if (!isset($casesByPhone[$phoneKey])) {
            $casesByPhone[$phoneKey] = [];
        }
        $casesByPhone[$phoneKey][] = $caseNum;
    }
}

// ------------- Pagination: 10 per page -------------
$per_page = 10;
$total = count($rows);
$total_pages = max(1, (int)ceil($total / $per_page));
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;
$rows_page = array_slice($rows, $offset, $per_page);

function buildPageLink($p, $pie_range, $agentExtValue) {
    return appendAgentExtToUrl('cases.php?page='.$p.'&pie_range='.urlencode($pie_range), $agentExtValue);
}

$casesReturnParams = [
    'pie_range' => $pie_range,
    'page' => $page,
];
if (trim((string)$agentExt) !== '') {
    $casesReturnParams['ext'] = $agentExt;
}
$currentCasesView = 'cases.php';
if (!empty($casesReturnParams)) {
    $currentCasesView .= '?' . http_build_query($casesReturnParams);
}

// Prepare safe JSON payloads for the front-end (avoid invalid UTF-8 failures)
$jsonOptions = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
$casesByNumberJson = json_encode($casesByNumber, $jsonOptions);
if ($casesByNumberJson === false) { $casesByNumberJson = '{}'; }
$casesByPhoneJson  = json_encode($casesByPhone, $jsonOptions);
if ($casesByPhoneJson === false) { $casesByPhoneJson = '{}'; }
$audioByCaseJson   = json_encode($audioByCase, $jsonOptions);
if ($audioByCaseJson === false) { $audioByCaseJson = '{}'; }

sqlsrv_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Case List</title>
<link rel="stylesheet" href="css/style.css">
<script src="js/chart-lite.js"></script>



<!-- A) Google Maps preview helper -->
<script>
function openMapPopup(addr){
  if(!addr) return;
  const baseUrl = "https://www.google.com/maps?q=" + encodeURIComponent(addr);
  const embedUrl = baseUrl + "&output=embed";
  openPreviewModal({
    url: embedUrl,
    externalUrl: baseUrl,
    title: addr,
    type: 'map'
  });
}
window.openMapPopup = openMapPopup;

const CASES_BY_NUMBER = <?php echo $casesByNumberJson; ?> || {};
const CASES_BY_PHONE  = <?php echo $casesByPhoneJson; ?> || {};
const AUDIO_BY_CASE   = <?php echo $audioByCaseJson; ?> || {};
const AGENT_EXT       = <?php echo json_encode($agentExt); ?> || '';
const CSTA_HELPER_URL = 'csta_makecall.php';
window.CASES_BY_NUMBER = CASES_BY_NUMBER;
window.CASES_BY_PHONE = CASES_BY_PHONE;
window.AUDIO_BY_CASE = AUDIO_BY_CASE;
window.AGENT_EXT = AGENT_EXT;
window.CSTA_HELPER_URL = CSTA_HELPER_URL;
</script>
<script src="js/csta-call.js"></script>
<script src="js/close-confirm.js"></script>

<style>
/* Header / Navbar */
.header {
    background: #0073e6;
    padding: 12px 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.header a {
    color: #fff;
    text-decoration: none;
    padding: 8px 16px;
    background: #005bb5;
    border-radius: 5px;
    font-size: 16px;
    font-weight: 500;
}
.header a.active { background: #003f7f; }
.header a:hover  { background: #003f7f; }

.page-title {
    text-align: center;
    color: #0c4ea6;
    font-size: 28px;
    font-weight: 600;
    margin: 24px 0 12px;
}

body { font-family: Arial, sans-serif; background: #f7f9fc; padding: 0; margin:0; }
.container { max-width: 1100px; margin: 0 auto 30px; padding: 14px 16px 30px; }

/* Top header block: pie LEFT, legend RIGHT */
.header-block {
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:12px;
  margin-top:10px;
  width:100%;
}

/* Pie LEFT */
.pie-side {
  flex: 0 0 220px;
  margin-right: 12px;
  display:flex;
  flex-direction:column;
  align-items:flex-start;
}
.pie-wrap { width: 180px; max-width: 180px; }
#statusPie { width: 180px !important; height: 180px !important; }

/* Period buttons above pie */
.range-bar { display:flex; justify-content:flex-start; gap:6px; flex-wrap:wrap; margin-bottom:6px; }
.range-bar a {
  background:#e6f0ff; color:#005bb5; text-decoration:none; padding:4px 8px; border-radius:6px; border:1px solid #b3d1ff; font-size:12px;
}
.range-bar a.active { background:#0073e6; color:#fff; border-color:#005bb5; }
.range-bar a:hover  { background:#005bb5; color:#fff; }

/* Legend (right) */
.legend-wrap {
  flex: 1 1 auto;
  min-width: 0;
}
.legend-wrap span { display:inline-block; margin: 0 6px 6px 0; padding:4px 8px; border:1px solid #ccc; border-radius:4px; }

/* Table + stripes + highlights */
table { width: 100%; border-collapse: collapse; margin-top: 16px; }
th, td { padding: 10px; border: 1px solid #ccc; text-align: left; }
thead tr { background:#0073e6; color:#fff; }
tbody tr:nth-child(even) { background:#f2f6fb; }

/* Ensure highlight colours override stripes */
.highlight-orange { background-color: #fff4df !important; }  /* Open >2h */
.highlight-red    { background-color: #ffd6d6 !important; }  /* Open ≥24h */
.highlight-green  { background-color: #dff5df !important; }  /* Closed */
.highlight-blue   { background-color: #d9ecff !important; }  /* Escalated */

/* Modals */
.modal {
  display: none;
  position: fixed;
  inset: 0;
  padding: 40px 12px;
  width: 100%;
  height: 100%;
  overflow-y: auto;
  background-color: rgba(0,0,0,0.4);
  z-index: 3000;
}
.modal.modal-notes { z-index: 15000; }
#detailsModal { z-index: 2000; }
#notesModal   { z-index: 15000; }

/* --- View Notes modal styling --- */
.modal-content {
  background-color: #fff;
  margin: auto;
  padding: 20px;
  border-radius: 10px;
  width: 80%;
  max-width: 640px;
  max-height: calc(100vh - 120px);
  overflow-y: auto;
  box-shadow: 0 5px 15px rgba(0,0,0,0.3);
  position: relative;
}
@media (max-width: 768px) {
  .modal-content {
    width: 94%;
    max-height: calc(100vh - 80px);
  }
}
.modal-content h3 { margin: 0 0 10px 0; color:#0073e6; }
.close { color: #aaa; position: absolute; top: 10px; right: 15px; font-size: 28px; font-weight: bold; cursor: pointer; }
.close:hover { color: #000; }

.notes-box {
  border: 1px solid #d3def5;
  background: #f3f7ff;
  padding: 12px;
  border-radius: 8px;
  max-height: 360px;
  overflow: auto;
  line-height: 1.4;
  font-size: 14px;
  white-space: pre-wrap;
}
.notes-actions {
  display:flex;
  justify-content:flex-end;
  gap:10px;
  margin-top:10px;
}
.btn { 
  display:inline-block;
  background:#0073e6;
  color:#fff !important;
  padding:8px 14px;
  border-radius:6px;
  text-decoration:none;
  border:1px solid #005bb5;
}
.btn:hover { background:#005bb5; }
#previewModal   { z-index: 3050; }
#previousCasesModal { z-index: 3100; }
.attachment-modal .modal-content {
  max-width: 900px;
  width: 90%;
  height: 80vh;
  max-height: 80vh;
  display: flex;
  flex-direction: column;
}
.attachment-modal .modal-content h3 { margin-bottom: 12px; }
.attachment-body {
  flex: 1;
  border: 1px solid #dde4f2;
  border-radius: 8px;
  background: linear-gradient(135deg, #f7f9ff, #eef2ff);
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  transition: background 0.3s ease;
}
.attachment-body.audio-active {
  background: #eef4ff;
  padding: 24px;
}
.attachment-body iframe {
  width: 100%;
  height: 100%;
  border: none;
  background: #fff;
}
.audio-player {
  width: 100%;
  max-width: 520px;
  background: #ffffff;
  border-radius: 12px;
  box-shadow: 0 18px 36px rgba(15, 23, 42, 0.18);
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.audio-player audio {
  width: 100%;
  display: block;
  background: #f2f6ff;
  border-radius: 8px;
  padding: 6px 0;
}
.audio-status {
  font-size: 0.95rem;
  color: #3b4a6b;
}
.audio-status.hint {
  color: #5a6d92;
  font-style: italic;
}
.audio-status.error {
  color: #c0392b;
  font-weight: 600;
}
.attachment-actions {
  margin-top: 15px;
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  flex-wrap: wrap;
}

/* --- View Details modal styling to match Search --- */
.details-wrap {
  border: 1px solid #d3def5;
  background: #f3f7ff;
  border-radius: 8px;
  padding: 0;
  overflow: hidden;
}
.details-header {
  background: #e6f0ff;
  color: #005bb5;
  padding: 10px 12px;
  font-weight: 600;
  border-bottom: 1px solid #c9d9ff;
}
.details-table {
  width: 100%;
  border-collapse: collapse;
}
.details-table th, .details-table td {
  padding: 10px 12px;
  border-bottom: 1px solid #dbe6ff;
  text-align: left;
  font-size: 14px;
}
.details-table tbody tr:nth-child(even) {
  background: #eef4ff;   /* zebra striping inside modal */
}
.details-actions {
  display:flex;
  justify-content:flex-end;
  gap:10px;
  padding: 10px 12px;
  background:#f3f7ff;
}

/* Previous cases modal */
.previous-cases-content {
  max-width: 680px;
  width: 95%;
}
.previous-cases-body {
  max-height: 420px;
  overflow-y: auto;
  margin-top: 6px;
}
.previous-cases-table {
  width: 100%;
  border-collapse: collapse;
  background: #fff;
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 6px 16px rgba(15, 23, 42, 0.12);
}
.previous-cases-table th,
.previous-cases-table td {
  padding: 10px 12px;
  border-bottom: 1px solid #e3eaf9;
  text-align: left;
  font-size: 14px;
}
.previous-cases-table tbody tr:nth-child(even) {
  background: #f7f9ff;
}
.previous-cases-table .history-view-btn {
  padding: 6px 12px;
  border-radius: 6px;
  background: #0073e6;
  color: #fff;
  text-decoration: none;
  border: 1px solid #005bb5;
  display: inline-block;
}
.previous-cases-table .history-view-btn:hover {
  background: #005bb5;
}

/* Pagination */
.pagination {
  display:flex; gap:6px; justify-content:center; align-items:center; margin-top:14px;
}
.pagination a, .pagination span {
  padding:6px 10px; border:1px solid #b3d1ff; border-radius:6px; text-decoration:none;
  background:#e6f0ff; color:#005bb5; font-size:13px;
}
.pagination .active {
  background:#0073e6; color:#fff; border-color:#005bb5;
}
.pagination .disabled {
  opacity:0.5; pointer-events:none;
}
</style>
</head>
<body>

<div class="header">
  <a href="<?php echo appendAgentExtToUrl('form.php', $agentExt); ?>">➕ New Case</a>
  <a href="<?php echo appendAgentExtToUrl('cases.php', $agentExt); ?>" class="active">📋 Case List</a>
  <a href="<?php echo appendAgentExtToUrl('search.php', $agentExt); ?>">🔍 Search Cases</a>
  <a href="<?php echo appendAgentExtToUrl('dashboard.php', $agentExt); ?>">📊 Dashboard</a>
</div>

<h1 class="page-title">Case List</h1>

<div class="container">

  <div class="header-block">
    <!-- Pie (LEFT) with period buttons -->
    <div class="pie-side">
      <div class="range-bar">
        <?php
          $pieRanges = [
            'today' => 'Today',
            '7d' => '7d',
            '1m' => '1m',
            '3m' => '3m',
            '6m' => '6m',
            '12m' => '12m',
            'all' => 'All'
          ];
          foreach ($pieRanges as $key => $label) {
            $cls = ($key === $pie_range) ? 'active' : '';
            $href = appendAgentExtToUrl('cases.php?pie_range=' . $key, $agentExt);
            echo "<a class='$cls' href='$href'>$label</a>";
          }
        ?>
      </div>
      <div class="pie-wrap">
        <canvas id="statusPie"></canvas>
      </div>
    </div>

    <!-- Legend (RIGHT) -->
    <div class="legend-wrap">
      <div>
        <span style="background:#fff; border-color:#ddd;">⚪ Open &lt;= 2 hours</span>
        <span style="background:#fff4df;">🔶 Open &gt; 2 hours</span>
        <span style="background:#ffd6d6;">🔴 Open ≥ 24 hours</span>
        <span style="background:#d9ecff;">🔵 Escalated</span>
        <span style="background:#dff5df;">✅ Closed</span>
      </div>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th>Date/Time</th>
        <th>Case #</th>
        <th>Full Name</th>
        <th>Status</th>
        <th>Phone</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php
    foreach($rows_page as $row) {
        $rowDate = !empty($row['date_time_str']) ? new DateTime($row['date_time_str'], $serverTimezone) : null;
        $ageHours = 0;
        if ($rowDate) {
            $diff = $now->diff($rowDate);
            $ageHours = $diff->days*24 + $diff->h;
        }

        $statusLower = strtolower((string)$row['status']);
        $highlight = '';
        if ($statusLower == 'closed') {
            $highlight = 'highlight-green';
        } elseif ($statusLower == 'escalated') {
            $highlight = 'highlight-blue';
        } elseif ($ageHours >= 24) {
            $highlight = 'highlight-red';
        } elseif ($ageHours > 2) {
            $highlight = 'highlight-orange';
        }

        // audio link by case number match from lookup
        $case_number = trim((string)$row['case_number']);
        $audioLink = isset($audioByCase[$case_number]) ? $audioByCase[$case_number] : '';

        echo "<tr class='$highlight'>";
        echo "<td>". ($rowDate ? $rowDate->format('Y-m-d H:i') : '') ."</td>";
        echo "<td>". htmlspecialchars($case_number) ."</td>";
        echo "<td>". htmlspecialchars(trim(($row['first_name'] ?? '').' '.($row['middle_name'] ?? '').' '.($row['family_name'] ?? ''))) ."</td>";
        echo "<td>". htmlspecialchars((string)$row['status']) ."</td>";

        echo "<td>";
        if (!empty($row['phone_number'])) {
            $safePhone = htmlspecialchars((string)$row['phone_number'], ENT_QUOTES);
            echo "<a href='javascript:void(0);' class='csta-call-link' data-csta-number='$safePhone'>$safePhone</a>";
        } else { echo "—"; }
        echo "</td>";

        $editUrl = appendAgentExtToUrl('edit_case.php?id=' . urlencode((string)$case_number), $agentExt);
        $closeUrl = 'close_case.php?case=' . urlencode((string)$case_number)
                  . '&redirect=' . rawurlencode($currentCasesView);
        $escalateUrl = appendAgentExtToUrl('escalate.php?id=' . urlencode((string)$case_number), $agentExt);
        echo "<td>
                <a href='javascript:void(0);' class='view-details-btn'
                   data-case-number='".htmlspecialchars((string)$case_number, ENT_QUOTES)."'
                   data-audio='".htmlspecialchars($audioLink, ENT_QUOTES)."'>View Details</a> |
                <a class='edit-link' href='".htmlspecialchars($editUrl, ENT_QUOTES)."'>Edit</a>";
        if ($statusLower == 'open') {
            echo " | <a class='edit-link close-case-link' href='" . htmlspecialchars($closeUrl, ENT_QUOTES) . "' data-close-confirm='Close this case?'>Close</a> |"
               . " <a class='edit-link' href='" . htmlspecialchars($escalateUrl, ENT_QUOTES) . "'>Escalate</a>";
        } elseif ($statusLower == 'escalated') {
            echo " | <a class='edit-link close-case-link' href='" . htmlspecialchars($closeUrl, ENT_QUOTES) . "' data-close-confirm='Close this escalated case?'>Close</a>";
        }
        echo "</td>";

        echo "</tr>";
    }
    ?>
    </tbody>
  </table>

  <!-- Pagination -->
  <div class="pagination">
    <?php
      $prev = $page - 1; $next = $page + 1;
      echo '<a class="'.($page<=1?'disabled':'').'" href="'.buildPageLink(max(1,$prev), $pie_range, $agentExt).'">Prev</a>';
      for ($p=1; $p <= $total_pages; $p++) {
          $cls = ($p==$page)?'active':'';
          echo '<a class="'.$cls.'" href="'.buildPageLink($p, $pie_range, $agentExt).'">'.$p.'</a>';
      }
      echo '<a class="'.($page>=$total_pages?'disabled':'').'" href="'.buildPageLink(min($total_pages,$next), $pie_range, $agentExt).'">Next</a>';
    ?>
  </div>
</div>

<!-- Notes Modal -->
<div id="notesModal" class="modal modal-notes">
  <div class="modal-content">
    <span class="close" aria-label="Close notes">&times;</span>
    <h3>Case Notes</h3>
    <div id="modalNotes" class="notes-box"></div>
    <div class="notes-actions">
      <a href="javascript:void(0);" class="btn" id="closeNotesBtn">Close</a>
    </div>
  </div>
</div>

<!-- Case Details Modal -->
<div id="detailsModal" class="modal">
  <div class="modal-content">
    <span class="close" aria-label="Close details">&times;</span>
    <h3>Case Details</h3>
    <div class="details-wrap">
      <div class="details-header">Overview</div>
      <table class="details-table" id="detailsTable">
        <tbody>
          <!-- injected rows -->
        </tbody>
      </table>
      <div class="details-actions">
        <a href="javascript:void(0);" class="btn" id="closeDetailsBtn">Close</a>
      </div>
    </div>
  </div>
</div>

<!-- Previous Cases Modal -->
<div id="previousCasesModal" class="modal">
  <div class="modal-content previous-cases-content">
    <span class="close" aria-label="Close previous cases">&times;</span>
    <h3>Previous Cases</h3>
    <div id="previousCasesBody" class="previous-cases-body"></div>
    <div class="details-actions">
      <a href="javascript:void(0);" class="btn" id="closePreviousCasesBtn">Close</a>
    </div>
  </div>
</div>

<!-- Preview Modal -->
<div id="previewModal" class="modal attachment-modal">
  <div class="modal-content">
    <span class="close" aria-label="Close preview">&times;</span>
    <h3 id="previewTitle">Preview</h3>
    <div class="attachment-body" id="previewBody"></div>
    <div class="attachment-actions">
      <a href="javascript:void(0);" class="btn" id="openPreviewExternal" target="_blank" rel="noopener">Open in New Tab</a>
      <a href="javascript:void(0);" class="btn" id="closePreviewBtn">Close</a>
    </div>
  </div>
</div>

<script>
const pieCtx = document.getElementById('statusPie');
new Chart(pieCtx, {
  type: 'pie',
  data: {
    labels: ['Open <2h','Open >2h','Open ≥24h','Escalated','Closed'],
    datasets: [{
      data: [
        <?= (int)$count_open_fresh ?>,
        <?= (int)$count_open_2h ?>,
        <?= (int)$count_open_24h ?>,
        <?= (int)$count_escalated ?>,
        <?= (int)$count_closed ?>
      ],
      backgroundColor: ['#ffffff','#fff4df','#ffd6d6','#d9ecff','#dff5df'],
      borderColor:     ['#dcdcdc','#e6d0a8','#d99','#99c2ff','#a6e3a6'],
      borderWidth: 1
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: true,
    plugins: { legend: { position: 'bottom' }, tooltip: { enabled: true } }
  }
});

// Notes Modal
const notesModal = document.getElementById("notesModal");
const modalNotes = document.getElementById("modalNotes");
const closeNotesIcon = notesModal.querySelector(".close");
const closeNotesBtn  = document.getElementById("closeNotesBtn");
const previewModal = document.getElementById("previewModal");
const previewBody = document.getElementById("previewBody");
const previewTitle = document.getElementById("previewTitle");
const openPreviewExternal = document.getElementById("openPreviewExternal");
const closePreviewBtn = document.getElementById("closePreviewBtn");
const closePreviewIcon = previewModal.querySelector(".close");

function buildIframe(url, title) {
  const iframe = document.createElement('iframe');
  iframe.src = url;
  iframe.title = title;
  iframe.loading = 'lazy';
  iframe.allow = 'autoplay';
  return iframe;
}

function openPreviewModal({ url, title, type = 'attachment', externalUrl = '' }) {
  if (!url) return;
  const safeTitle = title && title.trim()
    ? title.trim()
    : (type === 'audio'
        ? 'Audio Preview'
        : type === 'map'
          ? 'Map Preview'
          : 'Attachment Preview');
  previewTitle.textContent = safeTitle;
  previewBody.innerHTML = '';
  previewBody.classList.remove('audio-active');

  const external = externalUrl && externalUrl.trim() ? externalUrl : url;
  if (external) {
    openPreviewExternal.href = external;
    openPreviewExternal.style.display = 'inline-block';
  } else {
    openPreviewExternal.removeAttribute('href');
    openPreviewExternal.style.display = 'none';
  }

  if (type === 'audio') {
    previewBody.classList.add('audio-active');

    const player = document.createElement('div');
    player.className = 'audio-player';

    const status = document.createElement('div');
    status.className = 'audio-status';
    status.textContent = 'Loading audio preview…';
    player.appendChild(status);

    const audio = document.createElement('audio');
    audio.controls = true;
    audio.preload = 'auto';
    audio.src = url;
    audio.setAttribute('aria-label', safeTitle);
    player.appendChild(audio);

    audio.addEventListener('loadeddata', () => {
      status.textContent = 'Press play to listen.';
      status.classList.add('hint');
    });

    audio.addEventListener('play', () => {
      status.remove();
    }, { once: true });

    audio.addEventListener('error', () => {
      status.textContent = 'We could not load the audio preview. Use "Open in New Tab".';
      status.classList.remove('hint');
      status.classList.add('error');
      if (audio.parentNode === player) {
        player.removeChild(audio);
      }
    });

    previewBody.appendChild(player);

    requestAnimationFrame(() => {
      try { audio.load(); } catch (err) { console.warn('Audio preview load failed', err); }
    });
  } else {
    const iframe = buildIframe(url, safeTitle);
    previewBody.appendChild(iframe);
  }

  previewModal.style.display = "block";
}

// Ensure other scripts (like the head-level Google Maps helper) can find this
// even if execution order differs between browsers.
window.openPreviewModal = openPreviewModal;

function closePreviewModal() {
  const activeAudio = previewBody.querySelector('audio');
  if (activeAudio && typeof activeAudio.pause === 'function') {
    try { activeAudio.pause(); } catch (_) {}
  }
  previewModal.style.display = "none";
  previewBody.innerHTML = '';
  previewBody.classList.remove('audio-active');
}

window.closePreviewModal = closePreviewModal;

// Bind any main-table notes-button if present (kept for compatibility)
document.querySelectorAll(".view-notes-btn").forEach(btn => {
  btn.addEventListener("click", () => {
    showNotesModal(btn.getAttribute("data-notes") || '');
  });
});
closeNotesIcon.onclick = () => { hideNotesModal(); };
closeNotesBtn.onclick  = () => { hideNotesModal(); };
closePreviewIcon.onclick = () => { closePreviewModal(); };
closePreviewBtn.onclick  = () => { closePreviewModal(); };

if (modalNotes) {
  modalNotes.addEventListener('click', (event) => {
    const link = event.target.closest('a');
    if (!link) return;
    const hrefAttr = link.getAttribute('href') || '';
    const absoluteHref = link.href || hrefAttr;
    if (!hrefAttr && !absoluteHref) return;
    const isAttachmentLink = link.classList.contains('attachment-link') || (absoluteHref && absoluteHref.includes('/uploads/'));
    if (!isAttachmentLink) return;
    event.preventDefault();
    const filename = link.getAttribute('data-filename') || link.textContent || '';
    const targetUrl = hrefAttr || absoluteHref;
    openPreviewModal({ url: targetUrl, title: filename, type: 'attachment' });
  });
}

// Details Modal and Previous Cases
const detailsModal = document.getElementById("detailsModal");
const detailsCloseIcon = detailsModal.querySelector(".close");
const closeDetailsBtn  = document.getElementById("closeDetailsBtn");
const detailsTableBody = document.querySelector("#detailsTable tbody");
const previousCasesModal = document.getElementById("previousCasesModal");
const previousCasesBody  = document.getElementById("previousCasesBody");
const previousCasesCloseIcon = previousCasesModal.querySelector(".close");
const closePreviousCasesBtn = document.getElementById("closePreviousCasesBtn");

const stackedModalOrder = [];
const suppressedForNotes = [];
const STACK_BASE_Z = 2000;
const STACK_STEP_Z = 80;
const NOTES_MODAL_TOP = 120000;

function showNotesModal(contentHtml) {
  modalNotes.innerHTML = contentHtml || '';

  if (!notesModal.dataset.appendedToBody) {
    document.body.appendChild(notesModal);
    notesModal.dataset.appendedToBody = 'true';
  }

  notesModal.classList.add('notes-modal-active');
  suppressedForNotes.length = 0;

  const snapshot = stackedModalOrder.slice();
  snapshot.forEach(modal => {
    if (!modal || modal === notesModal) {
      return;
    }
    suppressedForNotes.unshift({ modal, viaStack: true });
    hideStackedModal(modal);
  });

  document.querySelectorAll('.modal').forEach(modal => {
    if (!modal || modal === notesModal) {
      return;
    }
    const alreadyTracked = suppressedForNotes.some(entry => entry.modal === modal);
    if (alreadyTracked) {
      return;
    }
    const computed = window.getComputedStyle ? window.getComputedStyle(modal) : null;
    const visible = computed ? computed.display !== 'none' : modal.style.display !== 'none';
    if (!visible) {
      return;
    }
    suppressedForNotes.unshift({ modal, viaStack: false, previousDisplay: modal.style.display || '' });
    modal.style.display = 'none';
  });

  showStackedModal(notesModal);
  notesModal.style.zIndex = String(NOTES_MODAL_TOP);
}

function hideNotesModal() {
  hideStackedModal(notesModal);
  notesModal.classList.remove('notes-modal-active');
  modalNotes.innerHTML = '';

  const toRestore = suppressedForNotes.slice();
  suppressedForNotes.length = 0;

  toRestore.forEach(entry => {
    if (!entry || !entry.modal) {
      return;
    }
    if (entry.viaStack) {
      showStackedModal(entry.modal);
    } else {
      entry.modal.style.display = entry.previousDisplay || '';
    }
  });
}

function syncStackedModalZ() {
  stackedModalOrder.forEach((modal, index) => {
    if (!modal) {
      return;
    }
    if (modal === notesModal) {
      modal.style.zIndex = String(NOTES_MODAL_TOP);
    } else {
      modal.style.zIndex = String(STACK_BASE_Z + index * STACK_STEP_Z);
    }
  });
}

function showStackedModal(modal) {
  if (!modal) return;
  const existingIndex = stackedModalOrder.indexOf(modal);
  if (existingIndex !== -1) {
    stackedModalOrder.splice(existingIndex, 1);
  }
  stackedModalOrder.push(modal);
  modal.style.display = 'block';
  syncStackedModalZ();
}

function hideStackedModal(modal) {
  if (!modal) return;
  const existingIndex = stackedModalOrder.indexOf(modal);
  if (existingIndex !== -1) {
    stackedModalOrder.splice(existingIndex, 1);
  }
  modal.style.display = 'none';
  modal.style.zIndex = '';
  syncStackedModalZ();
}

function isStackedModalOpen(modal) {
  return stackedModalOrder.indexOf(modal) !== -1;
}

let detailsHistory = [];
let pendingDetailsFromLists = [];
let activeDetailsCase = null;

const htmlEscape = (value) => {
  return value === null || value === undefined
    ? ''
    : String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};

const attrEscape = (value) => {
  return value === null || value === undefined
    ? ''
    : String(value)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
};

function addRow(label, valueHtml) {
  const tr = document.createElement('tr');
  const th = document.createElement('th');
  const td = document.createElement('td');
  th.textContent = label;
  td.innerHTML   = valueHtml || '—';
  tr.appendChild(th);
  tr.appendChild(td);
  detailsTableBody.appendChild(tr);
}

function closePreviousCasesModal() {
  hideStackedModal(previousCasesModal);

  if (pendingDetailsFromLists.length > 0) {
    const caseToRestore = pendingDetailsFromLists.pop();
    if (caseToRestore) {
      openCaseDetails(caseToRestore, { pushHistory: false });
    }
  }
}

function openPreviousCasesList(phone, currentCaseNumber) {
  if (!phone || !CASES_BY_PHONE || !Array.isArray(CASES_BY_PHONE[phone])) {
    return;
  }

  const relatedNumbers = CASES_BY_PHONE[phone].filter(num => num !== currentCaseNumber);
  if (!relatedNumbers.length) {
    return;
  }

  const records = relatedNumbers
    .map(num => CASES_BY_NUMBER[num])
    .filter(Boolean)
    .sort((a, b) => {
      const aDate = a.date_time_str || '';
      const bDate = b.date_time_str || '';
      return bDate.localeCompare(aDate);
    });

  if (!records.length) {
    return;
  }

  let tableHtml = '<table class="previous-cases-table">';
  tableHtml += '<thead><tr><th>Date/Time</th><th>Case #</th><th>Status</th><th></th></tr></thead><tbody>';
  records.forEach(record => {
    const caseNum = record.case_number || '';
    tableHtml += '<tr>' +
      '<td>' + htmlEscape(record.date_time_str || '') + '</td>' +
      '<td>' + htmlEscape(caseNum) + '</td>' +
      '<td>' + htmlEscape(record.status || '') + '</td>' +
      '<td><a href="javascript:void(0);" class="history-view-btn" data-case="' + attrEscape(caseNum) + '">View Case</a></td>' +
      '</tr>';
  });
  tableHtml += '</tbody></table>';

  previousCasesBody.innerHTML = tableHtml;
  showStackedModal(previousCasesModal);

  previousCasesBody.querySelectorAll('.history-view-btn').forEach(link => {
    link.addEventListener('click', () => {
      const caseNum = link.getAttribute('data-case');
      openCaseDetails(caseNum, { fromPreviousList: true });
    });
  });
}

function openCaseDetails(caseNumber, options = {}) {
  if (!caseNumber || !CASES_BY_NUMBER || !CASES_BY_NUMBER[caseNumber]) {
    return;
  }

  const pushHistory = options.pushHistory !== false;
  const fromPreviousList = !!options.fromPreviousList;

  if (pushHistory && isStackedModalOpen(detailsModal) && activeDetailsCase && activeDetailsCase !== caseNumber) {
    detailsHistory.push({
      caseNumber: activeDetailsCase,
      reopenPreviousList: fromPreviousList
    });
  } else if (pushHistory && !isStackedModalOpen(detailsModal)) {
    detailsHistory = [];
  }

  activeDetailsCase = caseNumber;
  const data = CASES_BY_NUMBER[caseNumber];

  detailsTableBody.innerHTML = '';

  addRow('Date/Time', htmlEscape(data.date_time_str || ''));
  addRow('Case #', htmlEscape(data.case_number || ''));
  addRow('Status', htmlEscape(data.status || ''));
  addRow('SPN', htmlEscape(data.spn || ''));

  const fullName = `${data.first_name || ''} ${data.middle_name || ''} ${data.family_name || ''}`.trim();
  addRow('Name', htmlEscape(fullName));

  const phone = data.phone_number || '';
  const phoneMarkup = phone
    ? `<a href="javascript:void(0);" class="csta-call-link" data-csta-number="${attrEscape(phone)}">${htmlEscape(phone)}</a>`
    : '—';
  addRow('Phone', phoneMarkup);

  const addressText = data.address || '';
  addRow('Address', addressText && addressText.trim() !== ''
    ? `<a href="javascript:void(0);" class="map-preview-link" data-address="${attrEscape(addressText)}">${htmlEscape(addressText)}</a>`
    : '—'
  );

  addRow('Escalation Session ID', htmlEscape(data.escalation_session_id || '—'));
  addRow('Gender', htmlEscape(data.gender || ''));
  addRow('Disability', htmlEscape(data.disability || ''));
  addRow('Language', htmlEscape(data.language || ''));
  addRow('User Type', htmlEscape(data.user_type || ''));

  const notesText = data.notes || '';
  addRow('Notes', notesText
    ? `<a href="javascript:void(0);" class="view-notes-btn" data-notes="${attrEscape(notesText)}">View Notes</a>`
    : 'No Notes');

  const audioUrl = AUDIO_BY_CASE[caseNumber] || '';
  if (audioUrl) {
    addRow('Audio', `<a href="javascript:void(0);" class="audio-preview-link" data-audio="${attrEscape(audioUrl)}">Play Audio</a>`);
  }

  const phoneGroup = (phone && CASES_BY_PHONE && Array.isArray(CASES_BY_PHONE[phone])) ? CASES_BY_PHONE[phone] : [];
  const related = phoneGroup.length
    ? phoneGroup.filter(num => num !== caseNumber)
    : [];
  const previousHtml = related.length
    ? `<a href="javascript:void(0);" class="previous-cases-link" data-phone="${attrEscape(phone)}" data-current="${attrEscape(caseNumber)}">Previous Cases (${related.length})</a>`
    : 'No previous cases';
  addRow('Previous Cases', previousHtml);

  addRow('Informed Consent', data.informed_consent ? 'Yes' : 'No');

  detailsTableBody.querySelectorAll('.view-notes-btn').forEach(nbtn => {
    nbtn.addEventListener('click', () => {
      showNotesModal(nbtn.getAttribute('data-notes') || '');
    });
  });

  detailsTableBody.querySelectorAll('.audio-preview-link').forEach(link => {
    link.addEventListener('click', () => {
      const audioLink = link.getAttribute('data-audio') || '';
      openPreviewModal({ url: audioLink, type: 'audio', title: 'Audio Preview', externalUrl: audioLink });
    });
  });

  detailsTableBody.querySelectorAll('.map-preview-link').forEach(link => {
    link.addEventListener('click', () => {
      const addr = link.getAttribute('data-address') || '';
      openMapPopup(addr);
    });
  });

  detailsTableBody.querySelectorAll('.previous-cases-link').forEach(link => {
    link.addEventListener('click', () => {
      const phoneValue = link.getAttribute('data-phone');
      const current = link.getAttribute('data-current');
      openPreviousCasesList(phoneValue, current);
    });
  });

  if (typeof attachCstaLinks === 'function') {
    attachCstaLinks(detailsTableBody);
  }

  showStackedModal(detailsModal);
}

document.querySelectorAll('.view-details-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const caseNumber = btn.getAttribute('data-case-number');
    const audioOverride = btn.getAttribute('data-audio') || '';
    if (caseNumber && audioOverride && !AUDIO_BY_CASE[caseNumber]) {
      AUDIO_BY_CASE[caseNumber] = audioOverride;
    }
    detailsHistory = [];
    pendingDetailsFromLists = [];
    activeDetailsCase = null;
    openCaseDetails(caseNumber);
  });
});

function hideDetailsModal() {
  if (detailsHistory.length > 0) {
    const previousContext = detailsHistory.pop();
    if (previousContext && previousContext.reopenPreviousList) {
      pendingDetailsFromLists.push(previousContext.caseNumber);
      hideStackedModal(detailsModal);
      activeDetailsCase = null;
      showStackedModal(previousCasesModal);
    } else if (previousContext) {
      openCaseDetails(previousContext.caseNumber, { pushHistory: false });
    }
  } else {
    hideStackedModal(detailsModal);
    activeDetailsCase = null;
  }
}

detailsCloseIcon.onclick = hideDetailsModal;
closeDetailsBtn.onclick  = hideDetailsModal;
previousCasesCloseIcon.onclick = closePreviousCasesModal;
closePreviousCasesBtn.onclick  = closePreviousCasesModal;

// Close modals when clicking outside
window.onclick = e => {
  if(e.target == notesModal) hideNotesModal();
  if(e.target == detailsModal) hideDetailsModal();
  if(e.target == previousCasesModal) closePreviousCasesModal();
  if(e.target == previewModal) closePreviewModal();
};

// Close modals with ESC key
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") {
    hideNotesModal();
    hideDetailsModal();
    closePreviousCasesModal();
    closePreviewModal();
  }
});
</script>

</body>
</html>
