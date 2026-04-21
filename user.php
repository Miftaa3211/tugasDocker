<?php
session_start();
define('CHECKDATA2_DIR', '/home/checkdata2/');
if (isset($_GET['logout'])) { session_destroy(); header('Location: /user.php'); exit; }
$error = '';

// Handle AJAX renew
if (isset($_POST['action']) && $_POST['action'] === 'renew' && isset($_SESSION['user_logged_in'])) {
    header('Content-Type: application/json');
    $voucher = strtoupper(trim($_POST['voucher'] ?? ''));
    $paket = $_POST['paket'] ?? 'bulanan';
    $email = $_SESSION['user_data'][2] ?? '';

    $voucher_file = '/usr/lib/cgi-bin/vouchers.txt';
    $vouchers = array_filter(explode("\n", trim(file_get_contents($voucher_file))));
    if (!in_array($voucher, $vouchers)) {
        echo json_encode(['success'=>false,'msg'=>'Voucher tidak valid atau sudah digunakan']);
        exit;
    }

    $file = CHECKDATA2_DIR . $email;
    if (!file_exists($file)) { echo json_encode(['success'=>false,'msg'=>'User tidak ditemukan']); exit; }
    $data = trim(file_get_contents($file));
    $parts = array_map('trim', explode(',', rtrim($data, '.')));
    $durasi = ($paket === 'tahunan') ? 365 : 30;
    $cur_exp = $parts[7] ?? date('d-m-Y');
    $exp_parts = explode('-', $cur_exp);
    $cur_ts = (count($exp_parts) === 3) ? mktime(0,0,0,(int)$exp_parts[1],(int)$exp_parts[0],(int)$exp_parts[2]) : time();
    $base = ($cur_ts > time()) ? $cur_ts : time();
    $new_expired = date('d-m-Y', $base + ($durasi * 86400));
    $parts[6] = $paket;
    $parts[7] = $new_expired;
    $new_data = implode(', ', $parts) . '.';
    file_put_contents($file, $new_data);

    $vouchers = array_filter($vouchers, fn($v) => $v !== $voucher);
    file_put_contents($voucher_file, implode("\n", array_values($vouchers)));

    $_SESSION['user_data'][6] = $paket;
    $_SESSION['user_data'][7] = $new_expired;

    echo json_encode(['success'=>true,'msg'=>"VPS diperpanjang hingga $new_expired"]);
    exit;
}
if (isset($_POST['login'])) {
    $username = strtolower(trim($_POST['username'] ?? ''));
    $password = trim($_POST['password'] ?? '');
    $found = false;
    foreach (glob(CHECKDATA2_DIR . '*') as $file) {
        if (basename($file) === 'locked') continue;
        $data = trim(file_get_contents($file));
        $parts = array_map('trim', explode(',', rtrim($data, '.')));
        if (($parts[0] ?? '') === $username && ($parts[1] ?? '') === $password) {
            $_SESSION['user_logged_in'] = true;
            $_SESSION['user_data'] = $parts;
            $found = true; break;
        }
    }
    if (!$found) $error = 'Username atau password salah!';
}
$is_logged_in = isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'];
$u = $_SESSION['user_data'] ?? [];
$username=$u[0]??'';$password=$u[1]??'';$email=$u[2]??'';$wa=$u[3]??'';
$tgl_daftar=$u[4]??'';$port=$u[5]??'';$paket=$u[6]??'bulanan';
$expired=$u[7]??'-';$container=rtrim($u[8]??'-','.');
$server_ip='103.175.225.238';$web_port='';$container_status='stopped';
if ($container && $container !== '-') {
    // Cek status real-time
    $running = shell_exec("docker ps --format '{{.Names}}' 2>/dev/null");
    $container_status = (strpos($running, $container) !== false) ? 'running' : 'stopped';
    $inspect = shell_exec("docker inspect $container 2>/dev/null");
    $data = json_decode($inspect, true);
    if ($data && isset($data[0])) {
        $ports = $data[0]['HostConfig']['PortBindings'] ?? [];
        $web_port = $ports['80/tcp'][0]['HostPort'] ?? '-';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>XcodeHoster — User Panel</title>
<style>
:root{--bg:#0a0e1a;--bg2:#111827;--bg3:#1a2235;--border:rgba(99,179,237,0.12);--border2:rgba(99,179,237,0.25);--text:#e2e8f0;--text2:#94a3b8;--text3:#64748b}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:14px}
.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center}
.login-box{background:var(--bg2);border:1px solid var(--border2);border-radius:20px;padding:40px;width:100%;max-width:420px}
.logo{text-align:center;margin-bottom:28px}
.logo-icon{width:52px;height:52px;background:linear-gradient(135deg,#38bdf8,#a78bfa);border-radius:14px;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;color:#0a0e1a;margin-bottom:12px}
.logo h1{font-size:22px;font-weight:700}
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:12px;color:var(--text3);margin-bottom:6px;text-transform:uppercase}
.form-input{width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:10px 14px;color:var(--text);font-size:14px;outline:none}
.form-input:focus{border-color:#38bdf8}
.btn{display:block;width:100%;padding:11px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;border:none;background:#38bdf8;color:#0a0e1a}
.err{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);border-radius:8px;padding:10px;font-size:12px;color:#f87171;margin-bottom:16px;text-align:center}
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:0 28px;height:60px;display:flex;align-items:center;justify-content:space-between}
.content{padding:28px;max-width:900px;margin:0 auto}
.welcome{background:linear-gradient(135deg,rgba(56,189,248,.15),rgba(167,139,250,.15));border:1px solid var(--border2);border-radius:14px;padding:24px;margin-bottom:24px}
.welcome h2{font-size:20px;font-weight:700;margin-bottom:6px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:20px}
.card-title{font-size:13px;font-weight:600;color:var(--text2);margin-bottom:14px}
.row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px}
.row:last-child{border-bottom:none}
.key{color:var(--text3)}
.val{color:var(--text);font-family:monospace;font-size:12px}
.ssh-box{background:#060d16;border:1px solid var(--border);border-radius:14px;padding:16px;font-family:monospace;font-size:13px;color:#8fa3bf;margin-bottom:24px}
.copy-btn{background:none;border:1px solid var(--border);border-radius:6px;color:var(--text3);cursor:pointer;padding:2px 8px;font-size:11px}
.badge-run{background:rgba(52,211,153,.12);color:#34d399;border:1px solid rgba(52,211,153,.2);padding:3px 9px;border-radius:20px;font-size:11px}
.logout-btn{background:transparent;border:1px solid var(--border2);color:var(--text2);padding:6px 14px;border-radius:10px;cursor:pointer;font-size:13px}
@media(max-width:640px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php if (!$is_logged_in): ?>
<div class="login-wrap">
  <div class="login-box">
    <div class="logo">
      <div class="logo-icon">XC</div>
      <h1>User Panel</h1>
      <p style="font-size:12px;color:var(--text3);margin-top:4px">XcodeHoster VPS</p>
    </div>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
      <div class="form-group">
        <label class="form-label">Username VPS</label>
        <input type="text" name="username" class="form-input" placeholder="username" required>
      </div>
      <div class="form-group">
        <label class="form-label">Password VPS</label>
        <input type="password" name="password" class="form-input" placeholder="password" required>
      </div>
      <button type="submit" name="login" class="btn">Masuk ke Panel</button>
    </form>
  </div>
</div>
<?php else: ?>
<div class="topbar">
  <div style="display:flex;align-items:center;gap:10px">
    <div class="logo-icon" style="width:34px;height:34px;font-size:13px;border-radius:8px;background:linear-gradient(135deg,#38bdf8,#a78bfa);display:inline-flex;align-items:center;justify-content:center;font-weight:700;color:#0a0e1a">XC</div>
    <span style="font-weight:700">XcodeHoster</span>
  </div>
  <div style="display:flex;align-items:center;gap:12px">
    <span style="font-size:12px;color:var(--text3)">👤 <?= htmlspecialchars($username) ?></span>
    <a href="?logout=1"><button class="logout-btn">Logout</button></a>
  </div>
</div>
<div class="content">
  <div class="welcome">
    <h2>👋 Selamat datang, <?= htmlspecialchars($username) ?>!</h2>
    <p style="color:var(--text2);font-size:13px">Berikut informasi VPS Anda.</p>
  </div>
  <div class="grid">
    <div class="card">
      <div class="card-title">🖥️ Info VPS</div>
      <div class="row"><span class="key">Container</span><span class="val"><?= htmlspecialchars($container) ?></span></div>
      <div class="row"><span class="key">Status</span><span class="val"><span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;<?= $container_status==='running'?'background:rgba(52,211,153,.12);color:#34d399;border:1px solid rgba(52,211,153,.2)':'background:rgba(248,113,113,.12);color:#f87171;border:1px solid rgba(248,113,113,.2)' ?>"><span style="width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block"></span><?= ucfirst($container_status) ?></span></span></div>

      <div class="row"><span class="key">SSH Host</span><span class="val"><?= $server_ip ?> <button class="copy-btn" onclick="cp('<?= $server_ip ?>')">copy</button></span></div>
      <div class="row"><span class="key">SSH Port</span><span class="val"><?= htmlspecialchars($port) ?> <button class="copy-btn" onclick="cp('<?= htmlspecialchars($port) ?>')">copy</button></span></div>
      <div class="row"><span class="key">Password SSH</span><span class="val"><span id="pw">••••••••</span> <button class="copy-btn" onclick="tp('<?= htmlspecialchars($password) ?>')">lihat</button></span></div>
      <div class="row"><span class="key">Web Port</span><span class="val"><?= htmlspecialchars($web_port ?: '-') ?></span></div>
    </div>
    <div class="card">
      <div class="card-title">📋 Info Akun</div>
      <div class="row"><span class="key">Email</span><span class="val"><?= htmlspecialchars($email) ?></span></div>
      <div class="row"><span class="key">WhatsApp</span><span class="val"><?= htmlspecialchars($wa) ?></span></div>
      <div class="row"><span class="key">Paket</span><span class="val"><?= htmlspecialchars($paket) ?></span></div>
      <div class="row"><span class="key">Tgl Daftar</span><span class="val"><?= htmlspecialchars($tgl_daftar) ?></span></div>
      <div class="row"><span class="key">Expired</span><span class="val"><?= htmlspecialchars($expired) ?></span></div>
      <div class="row"><span class="key">Domain</span><span class="val"><?= htmlspecialchars($username) ?>.tugaspkl.my.id</span></div>
    </div>
  </div>
  <div class="ssh-box">
    <span style="color:#38bdf8">$</span> ssh root@<?= $server_ip ?> -p <?= htmlspecialchars($port) ?>
    <button class="copy-btn" onclick="cp('ssh root@<?= $server_ip ?> -p <?= htmlspecialchars($port) ?>')">copy</button>
  </div>
  <div style="text-align:center;margin-top:16px">
    <button onclick="openRenew()" style="background:#38bdf8;color:#0a0e1a;border:none;padding:10px 24px;border-radius:10px;cursor:pointer;font-size:14px;font-weight:600">&#x1F504; Perpanjang VPS</button>
  </div>
</div>
<?php endif; ?>
<script>
function cp(t){navigator.clipboard.writeText(t).then(()=>{const e=document.createElement('div');e.style.cssText='position:fixed;bottom:24px;right:24px;background:#111827;border:1px solid rgba(99,179,237,0.25);border-radius:10px;padding:12px 20px;font-size:13px;color:#e2e8f0;z-index:9999';e.textContent='✓ Disalin: '+t;document.body.appendChild(e);setTimeout(()=>e.remove(),2500)})}
let ps=false;function tp(p){const e=document.getElementById('pw');ps=!ps;e.textContent=ps?p:'••••••••'}
</script>

<!-- MODAL PERPANJANG -->
<div id="modal-renew" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#111827;border:1px solid rgba(99,179,237,0.25);border-radius:14px;padding:28px;width:100%;max-width:420px">
    <h3 style="font-size:16px;font-weight:600;margin-bottom:20px">🔄 Perpanjang VPS</h3>
    <div style="margin-bottom:16px">
      <label style="display:block;font-size:12px;color:#64748b;margin-bottom:6px;text-transform:uppercase">Paket</label>
      <select id="renew-paket" style="width:100%;background:#1a2235;border:1px solid rgba(99,179,237,0.12);border-radius:10px;padding:9px 12px;color:#e2e8f0;font-size:13.5px;outline:none">
        <option value="bulanan">Bulanan (+30 hari)</option>
        <option value="tahunan">Tahunan (+365 hari)</option>
      </select>
    </div>
    <div style="margin-bottom:20px">
      <label style="display:block;font-size:12px;color:#64748b;margin-bottom:6px;text-transform:uppercase">Kode Voucher</label>
      <input type="text" id="renew-voucher" placeholder="ABCD1234" style="width:100%;background:#1a2235;border:1px solid rgba(99,179,237,0.12);border-radius:10px;padding:9px 12px;color:#e2e8f0;font-size:13.5px;outline:none;text-transform:uppercase">
    </div>
    <div id="renew-msg" style="display:none;padding:10px;border-radius:8px;font-size:12px;margin-bottom:16px;text-align:center"></div>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button onclick="document.getElementById('modal-renew').style.display='none'" style="background:transparent;border:1px solid rgba(99,179,237,0.25);color:#94a3b8;padding:7px 14px;border-radius:10px;cursor:pointer;font-size:13px">Batal</button>
      <button onclick="doRenew()" style="background:#38bdf8;color:#0a0e1a;border:none;padding:7px 14px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:600">✅ Perpanjang</button>
    </div>
  </div>
</div>

<script>
function openRenew() {
  document.getElementById('modal-renew').style.display = 'flex';
}

async function doRenew() {
  const voucher = document.getElementById('renew-voucher').value.toUpperCase();
  const paket = document.getElementById('renew-paket').value;
  const msg = document.getElementById('renew-msg');
  if (!voucher) { showMsg('Masukkan kode voucher!', 'error'); return; }
  
  const fd = new FormData();
  fd.append('action', 'renew');
  fd.append('voucher', voucher);
  fd.append('paket', paket);
  
  const res = await fetch('/user.php', { method: 'POST', body: fd });
  const data = await res.json();
  
  if (data.success) {
    showMsg('✅ ' + data.msg, 'success');
    setTimeout(() => location.reload(), 2000);
  } else {
    showMsg('❌ ' + data.msg, 'error');
  }
}

function showMsg(text, type) {
  const el = document.getElementById('renew-msg');
  el.style.display = 'block';
  el.style.background = type === 'success' ? 'rgba(52,211,153,.1)' : 'rgba(248,113,113,.1)';
  el.style.border = type === 'success' ? '1px solid rgba(52,211,153,.2)' : '1px solid rgba(248,113,113,.2)';
  el.style.color = type === 'success' ? '#34d399' : '#f87171';
  el.textContent = text;
}
</script>

<!-- MODAL PERPANJANG -->
<div id="modal-renew" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#111827;border:1px solid rgba(99,179,237,0.25);border-radius:14px;padding:28px;width:100%;max-width:420px">
    <h3 style="font-size:16px;font-weight:600;margin-bottom:20px">🔄 Perpanjang VPS</h3>
    <div style="margin-bottom:16px">
      <label style="display:block;font-size:12px;color:#64748b;margin-bottom:6px;text-transform:uppercase">Paket</label>
      <select id="renew-paket" style="width:100%;background:#1a2235;border:1px solid rgba(99,179,237,0.12);border-radius:10px;padding:9px 12px;color:#e2e8f0;font-size:13.5px;outline:none">
        <option value="bulanan">Bulanan (+30 hari)</option>
        <option value="tahunan">Tahunan (+365 hari)</option>
      </select>
    </div>
    <div style="margin-bottom:20px">
      <label style="display:block;font-size:12px;color:#64748b;margin-bottom:6px;text-transform:uppercase">Kode Voucher</label>
      <input type="text" id="renew-voucher" placeholder="ABCD1234" style="width:100%;background:#1a2235;border:1px solid rgba(99,179,237,0.12);border-radius:10px;padding:9px 12px;color:#e2e8f0;font-size:13.5px;outline:none;text-transform:uppercase">
    </div>
    <div id="renew-msg" style="display:none;padding:10px;border-radius:8px;font-size:12px;margin-bottom:16px;text-align:center"></div>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button onclick="document.getElementById('modal-renew').style.display='none'" style="background:transparent;border:1px solid rgba(99,179,237,0.25);color:#94a3b8;padding:7px 14px;border-radius:10px;cursor:pointer;font-size:13px">Batal</button>
      <button onclick="doRenew()" style="background:#38bdf8;color:#0a0e1a;border:none;padding:7px 14px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:600">✅ Perpanjang</button>
    </div>
  </div>
</div>

<script>
function openRenew() {
  document.getElementById('modal-renew').style.display = 'flex';
}

async function doRenew() {
  const voucher = document.getElementById('renew-voucher').value.toUpperCase();
  const paket = document.getElementById('renew-paket').value;
  const msg = document.getElementById('renew-msg');
  if (!voucher) { showMsg('Masukkan kode voucher!', 'error'); return; }
  
  const fd = new FormData();
  fd.append('action', 'renew');
  fd.append('voucher', voucher);
  fd.append('paket', paket);
  
  const res = await fetch('/user.php', { method: 'POST', body: fd });
  const data = await res.json();
  
  if (data.success) {
    showMsg('✅ ' + data.msg, 'success');
    setTimeout(() => location.reload(), 2000);
  } else {
    showMsg('❌ ' + data.msg, 'error');
  }
}

function showMsg(text, type) {
  const el = document.getElementById('renew-msg');
  el.style.display = 'block';
  el.style.background = type === 'success' ? 'rgba(52,211,153,.1)' : 'rgba(248,113,113,.1)';
  el.style.border = type === 'success' ? '1px solid rgba(52,211,153,.2)' : '1px solid rgba(248,113,113,.2)';
  el.style.color = type === 'success' ? '#34d399' : '#f87171';
  el.textContent = text;
}
</script>
</body>
</html>
