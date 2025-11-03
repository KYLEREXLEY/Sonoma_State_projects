<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, PUT");
header("Access-Control-Allow-Headers: Content-Type");
$FILE = __DIR__ . "/results.txt";

function read_state($file){
  if(!file_exists($file)) return ["led"=>"OFF","updated"=>gmdate("c")];
  $j = json_decode(@file_get_contents($file), true);
  return $j ?: ["led"=>"OFF","updated"=>gmdate("c")];
}
function write_state($file,$arr){
  $arr["updated"] = gmdate("c");
  $fp = fopen($file,"c+");
  if(flock($fp, LOCK_EX)){
    ftruncate($fp,0); fwrite($fp, json_encode($arr, JSON_PRETTY_PRINT));
    fflush($fp); flock($fp, LOCK_UN);
  } fclose($fp);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method==='GET' && isset($_GET['api'])) {
  header("Content-Type: application/json"); echo json_encode(read_state($FILE)); exit;
}
if ($method==='PUT') {
  $j = json_decode(file_get_contents("php://input"), true);
  if(isset($j["led"]) && ($j["led"]==="ON" || $j["led"]==="OFF")){
    write_state($FILE, ["led"=>$j["led"]]); header("Content-Type: application/json");
    echo json_encode(["ok"=>true]); exit;
  }
  http_response_code(400); echo "Bad JSON"; exit;
}
if (isset($_GET['set']) && in_array($_GET['set'],["ON","OFF"])) { write_state($FILE, ["led"=>$_GET['set']]); header("Location: led.php"); exit; }

$state = read_state($FILE);
?>
<!doctype html><html><head>
<meta charset="utf-8"><title>LED Control</title>
<style>body{font-family:system-ui;margin:24px}.btn{padding:10px 14px;border:1px solid #ccc;border-radius:10px;text-decoration:none;margin-right:8px}</style>
</head><body>
<h2>LED Control</h2>
<p>Current LED: <strong><?php echo htmlspecialchars($state["led"]); ?></strong>
  <small>(<?php echo htmlspecialchars($state["updated"]); ?> UTC)</small></p>
<p>
  <a class="btn" href="?set=ON">Turn ON</a>
  <a class="btn" href="?set=OFF">Turn OFF</a>
  <a class="btn" href="?api=1">View JSON</a>
  <a class="btn" href="index.html">Dashboard</a>
</p>
</body></html>
