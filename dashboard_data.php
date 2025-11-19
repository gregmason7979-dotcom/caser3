<?php
$serverName = "localhost";
$connectionOptions = [
  "Database" => "nextccdb",
  "Uid" => "sa",
  "PWD" => '$olidus'
];
$conn = sqlsrv_connect($serverName, $connectionOptions);
if(!$conn){ die(json_encode(["error"=>"DB connection failed"])); }

$range = $_GET['range'] ?? 'today';

switch($range){
  case 'today': $where="date_time >= CAST(GETDATE() AS DATE)"; break;
  case '1w': $where="date_time >= DATEADD(day,-7,GETDATE())"; break;
  case '1m': $where="date_time >= DATEADD(month,-1,GETDATE())"; break;
  case '3m': $where="date_time >= DATEADD(month,-3,GETDATE())"; break;
  case '6m': $where="date_time >= DATEADD(month,-6,GETDATE())"; break;
  case '12m': $where="date_time >= DATEADD(month,-12,GETDATE())"; break;
  default: $where="1=1"; break;
}

// totals
$sql="SELECT COUNT(*) as total,
       SUM(CASE WHEN status='Open' THEN 1 ELSE 0 END) as open_cases,
       SUM(CASE WHEN status='Closed' THEN 1 ELSE 0 END) as closed_cases
      FROM mwcsp_caser WHERE $where";
$res=sqlsrv_query($conn,$sql);
$row=sqlsrv_fetch_array($res,SQLSRV_FETCH_ASSOC);
$total=$row['total']??0; $open=$row['open_cases']??0; $closed=$row['closed_cases']??0;

// gender (predefined categories)
$gender=["Male"=>0,"Female"=>0,"Other"=>0,"Prefer not to say"=>0,"Not Specified"=>0];
$sql="SELECT gender, COUNT(*) as c FROM mwcsp_caser WHERE $where GROUP BY gender";
$res=sqlsrv_query($conn,$sql);
while($r=sqlsrv_fetch_array($res,SQLSRV_FETCH_ASSOC)){ 
    $label = trim($r['gender'] ?? '');
    if ($label === '' || strtolower($label) === 'null') { $label = 'Not Specified'; }
    if(!isset($gender[$label])) { $gender[$label]=0; }
    $gender[$label] += $r['c'];
}

// age groups (DOB-based)
$age=["<18"=>0,"18-30"=>0,"31-50"=>0,"50+"=>0];
$sql="SELECT
  SUM(CASE WHEN DATEDIFF(year, age, GETDATE()) < 18 THEN 1 ELSE 0 END) as under18,
  SUM(CASE WHEN DATEDIFF(year, age, GETDATE()) BETWEEN 18 AND 30 THEN 1 ELSE 0 END) as age18_30,
  SUM(CASE WHEN DATEDIFF(year, age, GETDATE()) BETWEEN 31 AND 50 THEN 1 ELSE 0 END) as age31_50,
  SUM(CASE WHEN DATEDIFF(year, age, GETDATE()) > 50 THEN 1 ELSE 0 END) as over50
FROM mwcsp_caser WHERE $where";
$res=sqlsrv_query($conn,$sql);
if($r=sqlsrv_fetch_array($res,SQLSRV_FETCH_ASSOC)){
  $age["<18"]=$r['under18']; 
  $age["18-30"]=$r['age18_30'];
  $age["31-50"]=$r['age31_50']; 
  $age["50+"]=$r['over50'];
}

// disability (predefined)
$disability=["None"=>0,"Physical"=>0,"Sensory"=>0,"Intellectual"=>0,"Mental"=>0,"Other"=>0,"Language"=>0,"Not Specified"=>0];
$sql="SELECT disability, COUNT(*) as c FROM mwcsp_caser WHERE $where GROUP BY disability";
$res=sqlsrv_query($conn,$sql);
while($r=sqlsrv_fetch_array($res,SQLSRV_FETCH_ASSOC)){ 
    $label = trim($r['disability'] ?? '');
    if ($label === '' || strtolower($label) === 'null') { $label = 'Not Specified'; }
    if(!isset($disability[$label])) { $disability[$label]=0; }
    $disability[$label] += $r['c'];
}

// language (predefined)
$language=["English"=>0,"Fijian"=>0,"Hindi"=>0,"Not Specified"=>0];
$sql="SELECT language, COUNT(*) as c FROM mwcsp_caser WHERE $where GROUP BY language";
$res=sqlsrv_query($conn,$sql);
while($r=sqlsrv_fetch_array($res,SQLSRV_FETCH_ASSOC)){ 
    $label = trim($r['language'] ?? '');
    if ($label === '' || strtolower($label) === 'null') { $label = 'Not Specified'; }
    if(!isset($language[$label])) { $language[$label]=0; }
    $language[$label] += $r['c'];
}

// user_type (predefined)
$user_type=["SP recipient"=>0,"Community member"=>0,"Non-government"=>0,"Government"=>0,"Other"=>0,"Not Specified"=>0];
$sql="SELECT user_type, COUNT(*) as c FROM mwcsp_caser WHERE $where GROUP BY user_type";
$res=sqlsrv_query($conn,$sql);
while($r=sqlsrv_fetch_array($res,SQLSRV_FETCH_ASSOC)){ 
    $label = trim($r['user_type'] ?? '');
    if ($label === '' || strtolower($label) === 'null') { $label = 'Not Specified'; }
    if(!isset($user_type[$label])) { $user_type[$label]=0; }
    $user_type[$label] += $r['c'];
}

echo json_encode([
  "total"=>$total,
  "open"=>$open,
  "closed"=>$closed,
  "gender"=>$gender,
  "age"=>$age,
  "disability"=>$disability,
  "language"=>$language,
  "user_type"=>$user_type
]);
?>
