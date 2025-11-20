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

$currentPage = basename($_SERVER['PHP_SELF']);

// period filter
$period = $_GET['period'] ?? '30d';
$from = null; $label='';
$tzName = @date_default_timezone_get();
try {
  $tz = new DateTimeZone($tzName ?: 'UTC');
} catch (Exception $e) {
  $tz = new DateTimeZone('UTC');
}
switch ($period) {
  case 'today': $from=(new DateTime('today',$tz))->format('Y-m-d 00:00:00'); $label='Today'; break;
  case '7d':    $from=(new DateTime('-7 days',$tz))->format('Y-m-d H:i:s'); $label='Last 7 days'; break;
  case '30d':   $from=(new DateTime('-30 days',$tz))->format('Y-m-d H:i:s'); $label='Last 30 days'; break;
  case '90d':   $from=(new DateTime('-90 days',$tz))->format('Y-m-d H:i:s'); $label='Last 90 days'; break;
  case '180d':  $from=(new DateTime('-180 days',$tz))->format('Y-m-d H:i:s'); $label='Last 180 days'; break;
  case '365d':  $from=(new DateTime('-365 days',$tz))->format('Y-m-d H:i:s'); $label='Last 365 days'; break;
  default:      $from=null; $label='All time';
}

// Build WHERE
$where = " WHERE 1=1";
$params = [];
if($from){ $where .= " AND date_time >= ?"; $params[]=$from; }

// quick counts (status bucketization)
$now = new DateTime('now', $tz);

// Fetch all rows once (for computing composite statuses + pies)
$sql = "SELECT status, CONVERT(VARCHAR(19), date_time, 120) as dtstr, gender,
        COALESCE(NULLIF(LTRIM(RTRIM(disability)),''), 'Not Specified') as disability_lbl,
        COALESCE(NULLIF(LTRIM(RTRIM(language))  ,''), 'Not Specified') as language_lbl,
        COALESCE(NULLIF(LTRIM(RTRIM(user_type)) ,''), 'Not Specified') as user_type_lbl
        FROM mwcsp_caser $where";
$st = sqlsrv_query($conn, $sql, $params);

$countsStatus = ['Open_lt2h'=>0,'Open_gt2h'=>0,'Open_gt24'=>0,'Escalated'=>0,'Closed'=>0];
$genderC = [];
$disabC  = [];
$langC   = [];
$userC   = [];

while($row = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)){
  // status composite
  $status = strtolower($row['status'] ?? '');
  $rowDate = new DateTime($row['dtstr'], $tz);
  $diff = $now->diff($rowDate);
  $ageHours = $diff->days*24 + $diff->h;

  if ($status==='escalated') { $countsStatus['Escalated']++; }
  elseif ($status==='closed') { $countsStatus['Closed']++; }
  else {
    if ($ageHours >= 24) $countsStatus['Open_gt24']++;
    elseif ($ageHours > 2) $countsStatus['Open_gt2h']++;
    else $countsStatus['Open_lt2h']++;
  }

  // gender
  $g = trim($row['gender'] ?? '');
  if ($g==='') $g='Not Specified';
  $genderC[$g] = ($genderC[$g]??0)+1;

  // disab
  $d = $row['disability_lbl'];
  $disabC[$d] = ($disabC[$d]??0)+1;

  // lang
  $l = $row['language_lbl'];
  $langC[$l] = ($langC[$l]??0)+1;

  // user
  $u = $row['user_type_lbl'];
  $userC[$u] = ($userC[$u]??0)+1;
}

// Top row KPIs
$totalCases = array_sum($countsStatus);
$totalOpen  = $countsStatus['Open_lt2h'] + $countsStatus['Open_gt2h'] + $countsStatus['Open_gt24'];
$totalEsc   = $countsStatus['Escalated'];
$totalClosed= $countsStatus['Closed'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dashboard</title>
<link rel="stylesheet" href="css/style.css">
<script src="js/chart-lite.js"></script>
<style>
/* Header */
.header {
  background:#0073e6; padding:12px 20px; display:flex; gap:10px; align-items:center;
}
.header a{
  color:#fff; text-decoration:none; padding:8px 16px; background:#005bb5; border-radius:5px; font-size:16px; font-weight:500;
}
.header a.active{ background:#003f7f; }
.header a:hover{ background:#003f7f; }
body{ font-family: Arial, sans-serif; background:#f7f9fc; margin:0; padding:0; }
.container { max-width: 1200px; margin: 20px auto; background:#fff; padding:20px; border-radius:10px; box-shadow:0 5px 15px rgba(0,0,0,0.1); }

/* Period buttons */
.periods { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; justify-content:center; }
.periods a{ padding:6px 10px; border-radius:5px; text-decoration:none; border:1px solid #0073e6; color:#0073e6; background:#fff; }
.periods a.active, .periods a:hover{ background:#0073e6; color:#fff; }

/* KPIs row */
.kpis{ display:grid; grid-template-columns: repeat(4,1fr); gap:12px; margin-bottom:18px; }
.kpi{ padding:14px; border-radius:8px; color:#000; text-align:center; border:1px solid #ddd; background:#fff; }
.kpi .n{ font-size:22px; font-weight:700; }
.kpi.open     { background:#ffffff; }
.kpi.orange   { background:#fff7e6; }
.kpi.red      { background:#ffcccc; }
.kpi.blue     { background:#cfe8ff; }
.kpi.green    { background:#ccffcc; }

/* Charts grid: 3 x 2 on wide screens */
.charts{ display:grid; grid-template-columns: repeat(3, 1fr); gap:16px; }
.chart-card{ background:#fff; border:1px solid #ddd; border-radius:10px; padding:12px; }
.chart-card h4{ margin:6px 0 10px; text-align:center; }

/* canvas sizing */
.chart-card canvas{ width:100% !important; height:280px !important; }
</style>
</head>
<body>

<div class="header">
  <a href="<?php echo appendAgentExtToUrl('form.php', $agentExt); ?>">➕ New Case</a>
  <a href="<?php echo appendAgentExtToUrl('cases.php', $agentExt); ?>">📋 Case List</a>
  <a href="<?php echo appendAgentExtToUrl('search.php', $agentExt); ?>">🔍 Search Cases</a>
  <a href="<?php echo appendAgentExtToUrl('dashboard.php', $agentExt); ?>" class="active">📊 Dashboard</a>
</div>

<div class="container">
  <h2 style="text-align:center; color:#0073e6; margin-top:2px;">Dashboard</h2>

  <div class="periods">
    <?php
      $ps = ['today'=>'Today','7d'=>'7d','30d'=>'30d','90d'=>'90d','180d'=>'180d','365d'=>'365d','all'=>'All'];
      foreach($ps as $k=>$lab){
        $active = (($period===$k) || ($period==='' && $k==='30d')) ? 'active' : '';
        $url = 'dashboard.php'.($k!=='all' ? ('?period='.$k):'');
        $href = appendAgentExtToUrl($url, $agentExt);
        echo "<a class='$active' href='$href'>$lab</a>";
      }
    ?>
  </div>

  <!-- KPI row uses the case-list colors -->
  <div class="kpis">
    <div class="kpi open"><div>Total Cases</div><div class="n"><?php echo $totalCases; ?></div></div>
    <div class="kpi blue"><div>Escalated</div><div class="n"><?php echo $totalEsc; ?></div></div>
    <div class="kpi green"><div>Closed</div><div class="n"><?php echo $totalClosed; ?></div></div>
    <div class="kpi orange"><div>Open (all)</div><div class="n"><?php echo $totalOpen; ?></div></div>
  </div>

  <div class="charts">
    <!-- Status pie (Open<2h, Open>2h, Open≥24h, Escalated, Closed) -->
    <div class="chart-card">
      <h4>Status Mix <?php echo $label? "– $label":""; ?></h4>
      <canvas id="pieStatus"></canvas>
    </div>

    <div class="chart-card">
      <h4>Gender</h4>
      <canvas id="pieGender"></canvas>
    </div>

    <div class="chart-card">
      <h4>Disability</h4>
      <canvas id="pieDisab"></canvas>
    </div>

    <div class="chart-card">
      <h4>Language</h4>
      <canvas id="pieLang"></canvas>
    </div>

    <div class="chart-card">
      <h4>User Type</h4>
      <canvas id="pieUser"></canvas>
    </div>

    <!-- Mini status for match with cases page (a bit larger than before) -->
    <div class="chart-card">
      <h4>Status (Mini)</h4>
      <canvas id="pieMini"></canvas>
    </div>
  </div>
</div>

<script>
const countsStatus = <?php echo json_encode($countsStatus); ?>;
const genderC = <?php echo json_encode($genderC); ?>;
const disabC  = <?php echo json_encode($disabC); ?>;
const langC   = <?php echo json_encode($langC); ?>;
const userC   = <?php echo json_encode($userC); ?>;

function buildPie(canvasId, labelsObj, colors){
  const el = document.getElementById(canvasId);
  if(!el) return;

  let labels = Object.keys(labelsObj);
  let data   = Object.values(labelsObj);

  // If everything zero / empty, show “No data”
  const total = data.reduce((a,b)=>a+b,0);
  if (total===0){
    labels = ['No data'];
    data   = [1];
    colors = ['#e9ecef'];
  }

  new Chart(el, {
    type: 'pie',
    data: { labels, datasets:[{ data, backgroundColor: colors, borderColor:'#ddd' }] },
    options: {
      plugins: {
        legend: { position:'bottom' },
        tooltip: { enabled:true }
      }
    }
  });
}

// Status pies (two sizes)
const statusLabels = ['Open <2h','Open >2h','Open ≥24h','Escalated','Closed'];
const statusData = [
  countsStatus.Open_lt2h||0,
  countsStatus.Open_gt2h||0,
  countsStatus.Open_gt24||0,
  countsStatus.Escalated||0,
  countsStatus.Closed||0
];
const statusObj = {}; statusLabels.forEach((k,i)=>statusObj[k]=statusData[i]);
const colorsStatus = ['#ffffff','#fff1c7','#ffb3b3','#cfe0ff','#ccffcc'];

buildPie('pieStatus', statusObj, colorsStatus);
buildPie('pieMini',   statusObj, ['#ffffff','#fff1c7','#ffb3b3','#cfe0ff','#ccffcc']);

// Others
function toObj(map){
  // ensure “Not Specified” if missing
  if (Object.keys(map).length===0) return {'No data':0};
  return map;
}

buildPie('pieGender', toObj(genderC), ['#7db3ff','#a1d6ff','#cfe0ff','#e8f1ff','#d0e8ff','#b8d8ff']);
buildPie('pieDisab',  toObj(disabC),  ['#a0e7a0','#c4f1c4','#d9f7d9','#e7fbe7','#b8efb8','#92e092','#77d477']);
buildPie('pieLang',   toObj(langC),   ['#ffd59e','#ffe8c6','#fff3df','#ffe0a8','#ffcc80','#ffb74d']);
buildPie('pieUser',   toObj(userC),   ['#b39ddb','#d1c4e9','#c5cae9','#90caf9','#80cbc4','#ffab91']);
</script>
</body>
</html>
