<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");
$FILE = __DIR__ . "/rgb.txt";

function read_rgb($f){
  if(!file_exists($f)) return ["r"=>0,"g"=>0,"b"=>0,"updated"=>gmdate("c")];
  $j = json_decode(@file_get_contents($f), true);
  return $j ?: ["r"=>0,"g"=>0,"b"=>0,"updated"=>gmdate("c")];
}
function write_rgb($f,$rgb){
  $rgb["updated"] = gmdate("c");
  $fp = fopen($f,"c+");
  if(flock($fp, LOCK_EX)){
    ftruncate($fp,0); fwrite($fp, json_encode($rgb, JSON_PRETTY_PRINT));
    fflush($fp); flock($fp, LOCK_UN);
  } fclose($fp);
}

$rgb = read_rgb($FILE);

if ($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['api'])) { header("Content-Type: application/json"); echo json_encode($rgb); exit; }
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $r = max(0, min(255, intval($_POST['r'] ?? $rgb['r'])));
  $g = max(0, min(255, intval($_POST['g'] ?? $rgb['g'])));
  $b = max(0, min(255, intval($_POST['b'] ?? $rgb['b'])));
  write_rgb($FILE, ["r"=>$r,"g"=>$g,"b"=>$b]); header("Location: rgb.php"); exit;
}
$rgb = read_rgb($FILE);
?>
<!doctype html><html><head>
<meta charset="utf-8"><title>RGB Control</title>
<style>
  body{font-family:system-ui;margin:24px}
  .row{display:flex;gap:16px;align-items:center;margin:10px 0}
  input[type=range]{width:280px}
  .chip{padding:6px 10px;border:1px solid #ddd;border-radius:999px}
  .swatch{width:36px;height:24px;border-radius:6px;border:1px solid #ccc;display:inline-block;vertical-align:middle}
  .btn{padding:8px 12px;border:1px solid #ccc;border-radius:10px;background:#fff;text-decoration:none}
</style>
</head><body>
<h2>RGB Control</h2>
<p>Current: <span class="chip">R <?php echo $rgb['r'];?> G <?php echo $rgb['g'];?> B <?php echo $rgb['b'];?></span>
  <span class="swatch" style="background: rgb(<?php echo $rgb['r'];?>,<?php echo $rgb['g'];?>,<?php echo $rgb['b'];?>)"></span>
  <small>(<?php echo htmlspecialchars($rgb["updated"]);?> UTC)</small></p>

<form method="post">
  <div class="row">R <input type="range" min="0" max="255" name="r" value="<?php echo $rgb['r'];?>"> <output><?php echo $rgb['r'];?></output></div>
  <div class="row">G <input type="range" min="0" max="255" name="g" value="<?php echo $rgb['g'];?>"> <output><?php echo $rgb['g'];?></output></div>
  <div class="row">B <input type="range" min="0" max="255" name="b" value="<?php echo $rgb['b'];?>"> <output><?php echo $rgb['b'];?></output></div>
  <button class="btn" type="submit">Save</button>
  <a class="btn" href="?api=1">View JSON</a>
  <a class="btn" href="index.html">Dashboard</a>
</form>

<script>
  document.querySelectorAll('input[type=range]').forEach(r=>{
    const out = r.parentElement.querySelector('output');
    r.addEventListener('input', ()=>out.value=r.value);
  });
</script>
</body></html>
