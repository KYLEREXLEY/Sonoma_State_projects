<?php

/* ==== DB CREDS ==== */
$dbHost = "localhost";
$dbName = "name";
$dbUser = "user";
$dbPass = "password";

// === HELPERS ===
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function pdo():PDO{
  global $dbHost,$dbName,$dbUser,$dbPass;
  return new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",$dbUser,$dbPass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
}
function json_out($data,$code=200){http_response_code($code);header('Content-Type:application/json');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}

try{
  $pdo=pdo();

  /* === INSERT MODE (ESP POSTS DATA) === */
  $node=$_GET['node_name']??$_POST['node_name']??null;
  $temp=$_GET['temperature']??$_POST['temperature']??null;
  $hum=$_GET['humidity']??$_POST['humidity']??null;
  $time=$_GET['time_received']??$_POST['time_received']??null;
  $tz=$_GET['tz']??$_POST['tz']??null;

  if($node && ($temp!==null || $hum!==null)){
    header('Content-Type:application/json;charset=utf-8');
    if(!$time || !$tz) json_out(['status'=>400,'message'=>'time_received and tz required'],400);
    $temp=(strtolower($temp)==='nan'||$temp==='')?null:$temp;
    $hum =(strtolower($hum )==='nan'||$hum ==='')?null:$hum;

    try{
      $chk=$pdo->prepare("SELECT active FROM sensor_register WHERE node_name=?");
      $chk->execute([$node]);
      $reg=$chk->fetch();
      if(!$reg) json_out(['status'=>409,'message'=>'node not registered'],409);
      if(!(int)$reg['active']) json_out(['status'=>403,'message'=>'node inactive'],403);

      $pdo->beginTransaction();
      $pdo->prepare("INSERT INTO sensor_data(node_name,time_received,tz,temperature,humidity)
                     VALUES(?,?,?,?,?)")->execute([$node,$time,$tz,$temp,$hum]);
      $pdo->prepare("INSERT INTO sensor_activity(node_name,count)VALUES(?,1)
                     ON DUPLICATE KEY UPDATE count=count+1")->execute([$node]);
      $pdo->commit();
      json_out(['status'=>200,'message'=>'Inserted','node_name'=>$node]);
    }catch(PDOException $e){
      if($pdo->inTransaction())$pdo->rollBack();
      if($e->getCode()==23000) json_out(['status'=>409,'message'=>'DUPLICATE'],409);
      json_out(['status'=>500,'message'=>$e->getMessage()],500);
    }
  }

  /* === PAGE MODE === */
  $registered=$pdo->query("SELECT node_name,manufacturer,longitude,latitude,active FROM sensor_register ORDER BY node_name")->fetchAll();
  // UPDATED: include tz in the query
  $readings=$pdo->query("SELECT node_name,time_received,tz,temperature,humidity FROM sensor_data ORDER BY node_name,time_received DESC LIMIT 100")->fetchAll();
  $counts=$pdo->query("SELECT sr.node_name,COALESCE(sa.count,0) AS cnt FROM sensor_register sr LEFT JOIN sensor_activity sa USING(node_name) ORDER BY sr.node_name")->fetchAll();

  // Averages
  $avg=$pdo->query("
    SELECT 
      AVG(CASE WHEN node_name='node_2' THEN temperature END) AS avg_temp,
      AVG(CASE WHEN node_name='node_1' THEN humidity END) AS avg_hum
    FROM sensor_data
  ")->fetch();

  $avgTemp=$avg['avg_temp']!==null?number_format($avg['avg_temp'],2):'—';
  $avgHum =$avg['avg_hum']!==null?number_format($avg['avg_hum'],2):'—';
}catch(Throwable $e){
  echo "<h1>Database Error</h1><pre>".h($e->getMessage())."</pre>";exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Sensor Database</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root{--accent:#6aa84f;--stripe:#f7fbf4;--border:#dfe9d8;--text:#222;--muted:#6b7280;}
body{font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:var(--text);
     margin:1rem auto;max-width:960px;line-height:1.45;padding:0 1rem;}
h1{text-align:center;margin:.4rem 0 0.3rem;}
.sub{text-align:center;color:var(--muted);margin-bottom:1rem;}
.card{border:1px solid var(--border);border-radius:10px;box-shadow:0 3px 8px rgba(0,0,0,.05);overflow:hidden;margin:0 0 20px;}
.card h2{margin:0;padding:.8rem 1rem;background:#eef6e9;border-bottom:1px solid var(--border);font-size:1.1rem;}
.card .body{padding:1rem;}
table{width:100%;border-collapse:collapse;table-layout:fixed;}
thead th{background:var(--accent);color:#fff;padding:.55rem .65rem;text-align:left;}
td{border-bottom:1px solid var(--border);padding:.5rem .65rem;word-wrap:break-word;}
tbody tr:nth-child(even) td{background:var(--stripe);}
.kpi{margin:.4rem 0;}
select{padding:.35rem .5rem;}
.graphBox{width:100%;max-width:900px;margin:0 auto;}
</style>
</head>
<body>
<h1>Sensor Database</h1>
<p class="sub">Registered nodes, averages, and live temperature/humidity charts.</p>

<!-- Dropdown Menu -->
<div class="card">
  <h2>Select Node</h2>
  <div class="body">
    <label for="nodeSelect"><b>Choose Node:</b></label>
    <select id="nodeSelect" onchange="scrollToNode(this.value)">
      <option value="">-- Select Node --</option>
      <?php foreach($registered as $r): ?>
        <option value="<?=h($r['node_name'])?>"><?=h($r['node_name'])?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>

<!-- Register Table -->
<div class="card" id="registerCard">
  <h2>Sensor Register Table</h2>
  <div class="body">
    <table><thead><tr><th>Name</th><th>Manufacturer</th><th>Lon</th><th>Lat</th><th>Active</th></tr></thead><tbody>
    <?php foreach($registered as $r):?>
      <tr><td><?=h($r['node_name'])?></td>
          <td><?=h($r['manufacturer'])?></td>
          <td><?=h($r['longitude'])?></td>
          <td><?=h($r['latitude'])?></td>
          <td><?=((int)$r['active'])?'Yes':'No'?></td></tr>
    <?php endforeach;?>
    </tbody></table>
  </div>
</div>

<!-- Data Table -->
<div class="card" id="dataCard">
  <h2>Sensor Data Table</h2>
  <div class="body">
    <!-- UPDATED: added TZ column -->
    <table>
      <thead>
        <tr><th>Node</th><th>Time</th><th>TZ</th><th>Temp</th><th>Hum</th></tr>
      </thead>
      <tbody>
      <?php foreach($readings as $r):?>
        <tr>
          <td><?=h($r['node_name'])?></td>
          <td><?=h((new DateTime($r['time_received']))->format('Y-m-d H:i:s'))?></td>
          <td><?=h($r['tz']??'')?></td>
          <td><?=is_null($r['temperature'])?'—':h(number_format((float)$r['temperature'],2))?></td>
          <td><?=is_null($r['humidity'])?'—':h(number_format((float)$r['humidity'],2))?></td>
        </tr>
      <?php endforeach;?>
      </tbody>
    </table>

    <!-- Averages and Counts -->
    <div class="kpi"><b>Average Temperature (node_2):</b> <?=h($avgTemp)?> °C</div>
    <div class="kpi"><b>Average Humidity (node_1):</b> <?=h($avgHum)?> %</div>
    <div class="kpi">
      <b>Counts (unique accepted):</b>
      <?php foreach($counts as $c):?>
        <span style="margin-right:10px;"><?=h($c['node_name'])?>:<b><?=h($c['cnt'])?></b></span>
      <?php endforeach;?>
    </div>
  </div>
</div>

<!-- Graphs -->
<div class="card graphBox" id="humBox">
  <h2>Node 1 – Humidity Over Time</h2>
  <div class="body"><canvas id="humChart" height="140"></canvas></div>
</div>

<div class="card graphBox" id="tempBox">
  <h2>Node 2 – Temperature Over Time</h2>
  <div class="body"><canvas id="tempChart" height="140"></canvas></div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="Chartjs/sensorGraphDual.js?v=3"></script>
<script>
function scrollToNode(node){
  if(node==='node_1'){document.getElementById('humBox').scrollIntoView({behavior:'smooth'});}
  else if(node==='node_2'){document.getElementById('tempBox').scrollIntoView({behavior:'smooth'});}
  else{window.scrollTo({top:0,behavior:'smooth'});}
}

const select=document.getElementById('nodeSelect');
select.addEventListener('change',function(){
  const node=this.value,humBox=document.getElementById('humBox'),tempBox=document.getElementById('tempBox');
  if(node===''){humBox.style.display='';tempBox.style.display='';}
  else if(node==='node_1'){humBox.style.display='';tempBox.style.display='none';}
  else if(node==='node_2'){humBox.style.display='none';tempBox.style.display='';}
  else{humBox.style.display='none';tempBox.style.display='none';}
});
</script>
</body>
</html>
