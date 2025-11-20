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

$serverName = "localhost";
$connectionOptions = [
  "Database" => "nextccdb",
  "Uid" => "sa",
  "PWD" => '$olidus'
];
$conn = sqlsrv_connect($serverName, $connectionOptions);
if(!$conn){ die(print_r(sqlsrv_errors(), true)); }

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

$currentPage = basename($_SERVER['PHP_SELF']);

// Accept search via POST or via query-string ?term=...
$term = '';
$date_from = '';
$date_to   = '';
$search_started = false;

if ($_SERVER['REQUEST_METHOD']=='POST') {
  $term = $_POST['term'] ?? '';
  $date_from = $_POST['date_from'] ?? '';
  $date_to   = $_POST['date_to'] ?? '';
  $search_started = true;
} else {
  if (isset($_GET['term']) || isset($_GET['date_from']) || isset($_GET['date_to'])) {
    $term = $_GET['term'] ?? '';
    $date_from = $_GET['date_from'] ?? '';
    $date_to   = $_GET['date_to'] ?? '';
    $search_started = true;
  }
}

$rows = [];
if ($search_started) {
  $sql = "SELECT CONVERT(VARCHAR(19), date_time, 120) AS date_time_str, *
          FROM mwcsp_caser WHERE 1=1";
  $params = [];

  if($term!==''){
    $sql .= " AND (case_number LIKE ? OR spn LIKE ? OR first_name LIKE ? OR middle_name LIKE ? OR family_name LIKE ? OR phone_number LIKE ? OR gender LIKE ? OR disability LIKE ? OR language LIKE ? OR user_type LIKE ? OR notes LIKE ? OR status LIKE ? OR address LIKE ? OR escalation_session_id LIKE ?)";
    for($i=0;$i<14;$i++){ $params[]="%$term%"; }
  }
  if($date_from!==''){ $sql.=" AND date_time >= ?"; $params[]=$date_from." 00:00:00"; }
  if($date_to!==''){ $sql.=" AND date_time <= ?"; $params[]=$date_to." 23:59:59"; }

  $sql .= " ORDER BY 
              CASE 
                WHEN LOWER(status)='open' THEN 1
                WHEN LOWER(status)='escalated' THEN 2
                ELSE 3
              END,
            date_time ASC";
  $results = sqlsrv_query($conn, $sql, $params);
  if ($results) {
    while($r = sqlsrv_fetch_array($results, SQLSRV_FETCH_ASSOC)){
      $rows[] = $r;
    }
  }
}

$searchRedirectParams = [];
if (trim((string)$term) !== '') {
    $searchRedirectParams['term'] = $term;
}
if (trim((string)$date_from) !== '') {
    $searchRedirectParams['date_from'] = $date_from;
}
if (trim((string)$date_to) !== '') {
    $searchRedirectParams['date_to'] = $date_to;
}
if (trim((string)$agentExt) !== '') {
    $searchRedirectParams['ext'] = $agentExt;
}
$searchRedirectTarget = 'search.php';
if (!empty($searchRedirectParams)) {
    $searchRedirectTarget .= '?' . http_build_query($searchRedirectParams);
}

// Audio discovery (same as cases/form pages)
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

// Case metadata cache for details / previous cases
$casesByNumber = [];
$casesByPhone  = [];
$audioByCase   = [];

if ($conn) {
    $allStmt = sqlsrv_query($conn, "SELECT CONVERT(VARCHAR(19), date_time, 120) AS date_time_str, * FROM mwcsp_caser");
    if ($allStmt) {
        while ($row = sqlsrv_fetch_array($allStmt, SQLSRV_FETCH_ASSOC)) {
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
        sqlsrv_free_stmt($allStmt);
    }
}

$jsonOptions = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
$casesByNumberJson = json_encode($casesByNumber, $jsonOptions);
if ($casesByNumberJson === false) { $casesByNumberJson = '{}'; }
$casesByPhoneJson = json_encode($casesByPhone, $jsonOptions);
if ($casesByPhoneJson === false) { $casesByPhoneJson = '{}'; }
$audioByCaseJson = json_encode($audioByCase, $jsonOptions);
if ($audioByCaseJson === false) { $audioByCaseJson = '{}'; }

// for row colouring + pie ageing
list($serverNowObj, $serverTimezone) = fetchServerDateContext();
$now = clone $serverNowObj;

// ---- Compute mini-pie stats from current results ----
$count_open_fresh = 0;   // <2h
$count_open_2h    = 0;   // >2h & <24h
$count_open_24h   = 0;   // ≥24h
$count_escalated  = 0;
$count_closed     = 0;

foreach ($rows as $row) {
    if (empty($row['date_time_str'])) continue;
    $rowDate = new DateTime($row['date_time_str'], $serverTimezone);
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

// Before search, keep the pie visible but empty (your latest file set these to 1; keeping that behaviour)
if (!$search_started) {
    $count_open_fresh = $count_open_2h = $count_open_24h = $count_escalated = $count_closed = 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Search Cases</title>
<link rel="stylesheet" href="css/style.css">
<script src="js/chart-lite.js"></script>
<script>
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
.page-title {
     text-align: center;
     color: #0073e6;
     font-size: 22px;
     font-weight: 700;
     margin: 16px 0 10px;
}
/* Header / Navbar (match) */
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

body { font-family: Arial, sans-serif; background: #f7f9fc; padding: 0; margin:0; }

.container { max-width: 1100px; margin: 18px auto 30px; padding: 0 16px; }

/* Search header block */
.search-header {
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:12px;
}

/* Search bar inline */
.search-card { background:#fff; padding:14px 16px; border-radius:10px; box-shadow:0 5px 15px rgba(0,0,0,0.1); flex:1 1 auto; }
.search-row { display:flex; align-items:flex-end; gap:10px; flex-wrap:wrap; }
.search-row .field { display:flex; flex-direction:column; }
.search-row input[type="text"], .search-row input[type="date"] {
  padding:6px; border-radius:5px; border:1px solid #ccc; font-size:14px;
}
.btn { display:inline-block; background:#0073e6; color:#fff; padding:8px 14px; border-radius:6px; border:1px solid #005bb5; text-decoration:none; }
.btn:hover { background:#005bb5; }

/* Single, left-aligned mini status pie */
.mini-pie {
  float: left;
  width: 240px;
  padding: 12px;
  margin: 0 20px 20px 0;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  background: #fff;
  box-shadow: 0 2px 8px rgba(0,0,0,.06);
  text-align: center;
}
.mini-pie h4 { margin: 6px 0 8px; font-size: 14px; color: #005bb5; }
.mini-pie canvas { width: 150px !important; height: 150px !important; }
@media (max-width:900px){ .mini-pie{ float:none; width:100%; } }

/* Table look (match cases.php) */
table { width: 100%; border-collapse: collapse; margin-top: 16px; }
th, td { padding: 10px; border: 1px solid #ccc; text-align: left; }
thead tr { background:#0073e6; color:#fff; }
tbody tr:nth-child(even) { background:#f2f6fb; }

/* Row highlights */
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

/* notes box */
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
.notes-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:10px; }
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

/* details modal look (striped) */
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
.details-table { width: 100%; border-collapse: collapse; }
.details-table th, .details-table td {
  padding: 10px 12px;
  border-bottom: 1px solid #dbe6ff;
  text-align: left;
  font-size: 14px;
}
.details-table tbody tr:nth-child(even) { background: #eef4ff; }
.details-actions { display:flex; justify-content:flex-end; gap:10px; padding: 10px 12px; background:#f3f7ff; }

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
</style>

<script>
// --- Google Maps preview helper ---
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
</script>
</head>
<body>

<div class="header">
  <a href="<?php echo appendAgentExtToUrl('form.php', $agentExt); ?>">➕ New Case</a>
  <a href="<?php echo appendAgentExtToUrl('cases.php', $agentExt); ?>">📋 Case List</a>
  <a href="<?php echo appendAgentExtToUrl('search.php', $agentExt); ?>" class="active">🔍 Search Cases</a>
  <a href="<?php echo appendAgentExtToUrl('dashboard.php', $agentExt); ?>">📊 Dashboard</a>
</div>
<div class="page-title">Search Cases</div>

<div class="container">
  <!-- Single LEFT mini pie -->
  <div class="mini-pie">
    <h4>Status</h4>
    <canvas id="miniStatusPie"></canvas>
  </div>

  <div class="search-header">
    <div class="search-card">
      <form method="post">
        <input type="hidden" name="ext" value="<?php echo htmlspecialchars($agentExt); ?>">
        <div class="search-row">
          <div class="field">
            <label for="term">Search term</label>
            <input type="text" id="term" name="term" value="<?php echo htmlspecialchars($term); ?>" placeholder="Case #, name, phone, status, etc.">
          </div>
          <div class="field">
            <label for="date_from">From</label>
            <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
          </div>
          <div class="field">
            <label for="date_to">To</label>
            <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
          </div>
          <div class="field" style="align-self:flex-end;">
            <button type="submit" class="btn">Search</button>
          </div>
        </div>
      </form>
    </div>
    <!-- NOTE: removed the duplicate mini-pie block that created two canvases with the same id -->
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
    <?php if ($search_started && !empty($rows)): ?>
      <?php
      foreach($rows as $row){
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

        // audio link by case number
        $case_number = $row['case_number'];
        $audioLink = isset($audioByCase[(string)$case_number]) ? $audioByCase[(string)$case_number] : '';
        $fullName = trim(($row['first_name'] ?? '').' '.($row['middle_name'] ?? '').' '.($row['family_name'] ?? ''));

        echo "<tr class='$highlight'>";
        echo "<td>". ($rowDate ? $rowDate->format('Y-m-d H:i') : '') ."</td>";
        echo "<td>". htmlspecialchars((string)$case_number) ."</td>";
        echo "<td>". htmlspecialchars($fullName) ."</td>";
        echo "<td>". htmlspecialchars((string)$row['status']) ."</td>";

        echo "<td>";
        if (!empty($row['phone_number'])) {
            $safePhone = htmlspecialchars((string)$row['phone_number'], ENT_QUOTES);
            echo "<a href='javascript:void(0);' class='csta-call-link' data-csta-number='$safePhone'>$safePhone</a>";
        } else { echo "—"; }
        echo "</td>";

        $editUrl = appendAgentExtToUrl('edit_case.php?id=' . urlencode((string)$case_number), $agentExt);
        $closeUrl = 'close_case.php?case=' . urlencode((string)$case_number)
                  . '&redirect=' . rawurlencode($searchRedirectTarget);
        $escalateUrl = appendAgentExtToUrl('escalate.php?id=' . urlencode((string)$case_number), $agentExt);
        echo "<td>
                <a href='javascript:void(0);' class='view-details-btn'
                   data-case='".htmlspecialchars(json_encode($row), ENT_QUOTES)."'
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
    <?php elseif ($search_started && empty($rows)): ?>
      <tr><td colspan="6">No results.</td></tr>
    <?php else: ?>
      <!-- first load: show no rows -->
    <?php endif; ?>
    </tbody>
  </table>
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
        <tbody></tbody>
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
// Mini status pie (results only)
const miniCtx = document.getElementById('miniStatusPie');
if (miniCtx) {
  new Chart(miniCtx, {
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
      plugins: {
        legend: { display: false },
        tooltip: { enabled: true }
      }
    }
  });
}

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

// Details Modal + Previous Cases
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
    let data = {};
    try {
      data = JSON.parse(btn.getAttribute('data-case') || '{}');
    } catch (err) {
      data = {};
    }

    const caseNumber = (data.case_number || '').trim();
    const audioOverride = btn.getAttribute('data-audio') || '';

    if (caseNumber) {
      const existing = CASES_BY_NUMBER[caseNumber] || {};
      CASES_BY_NUMBER[caseNumber] = Object.assign({}, existing, data, { case_number: caseNumber });

      const phoneVal = (data.phone_number || '').trim();
      if (phoneVal) {
        if (!Array.isArray(CASES_BY_PHONE[phoneVal])) {
          CASES_BY_PHONE[phoneVal] = [];
        }
        if (!CASES_BY_PHONE[phoneVal].includes(caseNumber)) {
          CASES_BY_PHONE[phoneVal].push(caseNumber);
        }
      }

      if (audioOverride) {
        AUDIO_BY_CASE[caseNumber] = audioOverride;
      }

      detailsHistory = [];
      pendingDetailsFromLists = [];
      activeDetailsCase = null;
      openCaseDetails(caseNumber);
    }
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

// ESC key
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
