<?php
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
session_start();

// ============================================================
// KONFIGURASI - Sesuaikan dengan server Anda
// ============================================================
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'admin123'); // Ganti password ini!
define('BASE_PORT', 12100);       // Port awal SSH
define('BASE_WEB_PORT', 21100);   // Port awal Web
define('PORT_FILE', '/usr/lib/cgi-bin/port.txt');
define('PORTWEB_FILE', '/usr/lib/cgi-bin/portweb.txt');
define('VOUCHER_FILE', '/usr/lib/cgi-bin/vouchers.txt');
define('IP_FILE', '/usr/lib/cgi-bin/ip.txt');
define('CHECKDATA_DIR', '/home/checkdata/');
define('CHECKDATA2_DIR', '/home/checkdata2/');
define('DATAUSER_DIR', '/home/datauser/');
define('RAMBUTAN_DIR', '/home/rambutan/');
define('PAKET_DIR', '/home/paket/');
define('BILLING_DIR', '/home/billing/');
define('TRANSAKSI_DIR', '/home/transaksi/');

// ============================================================
// HELPERS
// ============================================================
function getDockerContainers() {
    $out = shell_exec("docker ps -a --format '{{.Names}}|{{.Status}}|{{.Ports}}' 2>/dev/null");
    $containers = [];
    if ($out) {
        foreach (explode("\n", trim($out)) as $line) {
            if (!$line) continue;
            $parts = explode('|', $line);
            $name = $parts[0] ?? '';
            $status = $parts[1] ?? '';
            $ports = $parts[2] ?? '';
            $running = stripos($status, 'Up') === 0;
            // Parse port dari string ports docker
            $ssh_port = '-';
            $web_port = '-';
            if (preg_match('/0\.0\.0\.0:(\d+)->22/', $ports, $m)) {
                $ssh_port = $m[1];
            }
            if (preg_match('/0\.0\.0\.0:(\d+)->80/', $ports, $m)) {
                $web_port = $m[1];
            }
            $containers[] = [
                'id' => $name,
                'user' => str_replace('server', '', $name),
                'ssh' => $ssh_port,
                'web' => $web_port,
                'status' => $running ? 'running' : 'stopped',
                'cpu' => rand(5, 85),
                'date' => date('d-m-Y'),
            ];
        }
    }
    return $containers;
}

function getUserList() {
    $users = [];
    $dir = CHECKDATA2_DIR;
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..' || $f === 'locked') continue;
            $data = trim(file_get_contents($dir . $f));
            $parts = array_map('trim', explode(',', $data));
            $users[] = [
                'username' => $parts[0] ?? '',
                'password' => $parts[1] ?? '',
                'email'    => $parts[2] ?? $f,
                'wa'       => $parts[3] ?? '',
                'date'     => $parts[4] ?? '',
                'paket'    => $parts[6] ?? 'bulanan',
                'expired'  => $parts[7] ?? '-',
                'container'=> $parts[8] ?? '-',
            ];
        }
    }
    return $users;
}

function getVoucherList() {
    if (!file_exists(VOUCHER_FILE)) return [];
    return array_filter(explode("\n", trim(file_get_contents(VOUCHER_FILE))));
}

function getCurrentPort() {
    return (int)(file_exists(PORT_FILE) ? trim(file_get_contents(PORT_FILE)) : BASE_PORT);
}

function getCurrentWebPort() {
    return (int)(file_exists(PORTWEB_FILE) ? trim(file_get_contents(PORTWEB_FILE)) : BASE_WEB_PORT);
}

function getServerIP() {
    return file_exists(IP_FILE) ? trim(file_get_contents(IP_FILE)) : '0.0.0.0';
}

function dockerCommand($cmd) {
    return shell_exec("docker $cmd 2>&1");
}

// ============================================================
// HANDLE AJAX / POST ACTIONS
// ============================================================
if (isset($_POST['action']) && isset($_SESSION['logged_in'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'create_vps') {
        $user = preg_replace('/[^a-z0-9]/', '', strtolower($_POST['username'] ?? ''));
        $pass = preg_replace('/[^a-z0-9]/', '', $_POST['password'] ?? '');
        $email = $_POST['email'] ?? '';
        $wa = $_POST['wa'] ?? '-';
        $paket = $_POST['paket'] ?? 'bulanan';
        $durasi = ($paket === 'tahunan') ? 365 : 30;
        $expired = date('d-m-Y', strtotime("+{$durasi} days"));
        if (!$user || !$pass || !$email) {
            echo json_encode(['success' => false, 'msg' => 'Lengkapi semua field']);
            exit;
        }
        $port = getCurrentPort();
        $webport = getCurrentWebPort();
        $cmd = "docker run -d --name server{$port} -e ROOT_PASSWORD={$pass} -p {$port}:22 -p {$webport}:80 --restart=always xcodedata 2>&1";
        $out = shell_exec($cmd);
        // Update port files
        file_put_contents(PORT_FILE, $port + 1);
        file_put_contents(PORTWEB_FILE, $webport + 1);
        // Simpan data user
        $tanggal = date('d-m-Y');
        @file_put_contents(CHECKDATA2_DIR . $email, "$user, $pass, $email, $wa, $tanggal, $port, $paket, $expired, server{$port}.");
        @file_put_contents(CHECKDATA_DIR . $email, $email);
        @file_put_contents(RAMBUTAN_DIR . "$user.$tanggal", "$user, $pass, $email, $wa, $tanggal, $port, $paket, $expired, server{$port}.");
        // Update portmap untuk wildcard subdomain
        $portmap_file = '/etc/apache2/portmap.txt';
        $portmap_entry = "\n{$user} {$webport}";
        $portmap_content = file_get_contents($portmap_file);
        if (strpos($portmap_content, "\n{$user} ") === false) {
            file_put_contents($portmap_file, $portmap_content . $portmap_entry);
            shell_exec('sudo systemctl reload apache2 2>/dev/null');
        }
        echo json_encode(['success' => true, 'msg' => "VPS server{$port} berhasil dibuat", 'output' => $out, 'port' => $port, 'webport' => $webport]);
        exit;
    }

    if ($action === 'stop_vps') {
        $id = $_POST['id'] ?? '';
        $out = dockerCommand("stop $id");
        echo json_encode(['success' => true, 'msg' => "Container $id dihentikan", 'output' => $out]);
        exit;
    }

    if ($action === 'start_vps') {
        $id = $_POST['id'] ?? '';
        $out = dockerCommand("start $id");
        echo json_encode(['success' => true, 'msg' => "Container $id dijalankan", 'output' => $out]);
        exit;
    }

    if ($action === 'restart_vps') {
        $id = $_POST['id'] ?? '';
        $out = dockerCommand("restart $id");
        echo json_encode(['success' => true, 'msg' => "Container $id di-restart", 'output' => $out]);
        exit;
    }

    if ($action === 'delete_vps') {
        $id = $_POST['id'] ?? '';
        dockerCommand("stop $id");
        $out = dockerCommand("rm $id");
        // Hapus file user di checkdata2 yang containernya = $id
        $checkdata2_dir = '/home/checkdata2/';
        foreach (glob($checkdata2_dir . '*') as $file) {
            if (basename($file) === 'locked') continue;
            $data = trim(file_get_contents($file));
            $parts = array_map('trim', explode(',', rtrim($data, '.')));
            $container = $parts[8] ?? '';
            if (rtrim($container, '.') === $id) {
                unlink($file);
                break;
            }
        }
        echo json_encode(['success' => true, 'msg' => "Container $id dan user dihapus", 'output' => $out]);
        exit;
    }

    if ($action === 'run_command') {
        $cmd = $_POST['cmd'] ?? '';
        // Keamanan: hanya izinkan docker commands
        if (!preg_match('/^docker\s/', $cmd)) {
            echo json_encode(['success' => false, 'msg' => 'Hanya perintah docker yang diizinkan']);
            exit;
        }
        $out = shell_exec("$cmd 2>&1");
        echo json_encode(['success' => true, 'output' => htmlspecialchars($out)]);
        exit;
    }

    if ($action === 'generate_vouchers') {
        $count = (int)($_POST['count'] ?? 100);
        $vouchers = [];
        for ($i = 0; $i < $count; $i++) {
            $vouchers[] = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        }
        file_put_contents(VOUCHER_FILE, implode("\n", $vouchers));
        echo json_encode(['success' => true, 'msg' => "$count voucher baru digenerate", 'count' => $count]);
        exit;
    }

    if ($action === 'delete_voucher') {
        $code = $_POST['code'] ?? '';
        $vouchers = getVoucherList();
        $vouchers = array_filter($vouchers, fn($v) => $v !== $code);
        file_put_contents(VOUCHER_FILE, implode("\n", $vouchers));
        echo json_encode(['success' => true, 'msg' => "Voucher $code dihapus"]);
        exit;
    }

    if ($action === 'get_detail') {
        $id = $_POST['id'] ?? '';
        $info = shell_exec("docker inspect $id 2>/dev/null");
        $data = json_decode($info, true);
        if ($data && isset($data[0])) {
            $c = $data[0];
            $ports = $c['HostConfig']['PortBindings'] ?? [];
            $ssh_port = $ports['22/tcp'][0]['HostPort'] ?? '-';
            $web_port = $ports['80/tcp'][0]['HostPort'] ?? '-';
            $env = $c['Config']['Env'] ?? [];
            $pass = '';
            foreach ($env as $e) {
                if (strpos($e, 'ROOT_PASSWORD=') === 0) {
                    $pass = substr($e, 14);
                }
            }
            echo json_encode([
                'success' => true,
                'id' => $id,
                'status' => $c['State']['Status'] ?? '-',
                'ssh_port' => $ssh_port,
                'web_port' => $web_port,
                'password' => $pass,
                'created' => date('d-m-Y H:i', strtotime($c['Created'] ?? '')),
                'ip' => getServerIP(),
            ]);
        } else {
            echo json_encode(['success' => false, 'msg' => 'Container tidak ditemukan']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'msg' => 'Action tidak dikenal']);
    exit;
}

// ============================================================
// LOGIN / LOGOUT
// ============================================================
$login_error = '';
if (isset($_POST['login'])) {
    if ($_POST['username'] === ADMIN_USER && $_POST['password'] === ADMIN_PASS) {
        $_SESSION['logged_in'] = true;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    $login_error = 'Username atau password salah!';
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'];

// Data untuk dashboard
$containers = $is_logged_in ? getDockerContainers() : [];
$users = $is_logged_in ? getUserList() : [];
$vouchers = $is_logged_in ? getVoucherList() : [];
$server_ip = $is_logged_in ? getServerIP() : '';
$running_count = count(array_filter($containers, fn($c) => $c['status'] === 'running'));
$stopped_count = count($containers) - $running_count;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>XcodeHoster — VPS Panel</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#0a0e1a; --bg2:#111827; --bg3:#1a2235; --bg4:#1e2d45;
  --border:rgba(99,179,237,0.12); --border2:rgba(99,179,237,0.25);
  --accent:#38bdf8; --accent2:#0ea5e9; --accent3:#7dd3fc;
  --green:#34d399; --red:#f87171; --yellow:#fbbf24; --purple:#a78bfa;
  --text:#e2e8f0; --text2:#94a3b8; --text3:#64748b;
  --mono:'JetBrains Mono',monospace; --sans:'Sora',sans-serif;
  --r:10px; --r2:14px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--sans);background:var(--bg);color:var(--text);min-height:100vh;display:flex;font-size:14px}

/* LOGIN */
.login-wrap{min-height:100vh;width:100%;display:flex;align-items:center;justify-content:center;background:var(--bg)}
.login-box{background:var(--bg2);border:1px solid var(--border2);border-radius:20px;padding:40px;width:100%;max-width:400px;animation:fadeUp .4s ease}
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.login-logo{text-align:center;margin-bottom:32px}
.login-logo .logo-icon{width:48px;height:48px;background:linear-gradient(135deg,var(--accent),var(--purple));border-radius:12px;display:inline-flex;align-items:center;justify-content:center;font-family:var(--mono);font-weight:700;font-size:18px;color:#0a0e1a;margin-bottom:12px}
.login-logo h1{font-size:22px;font-weight:700;color:var(--text)}
.login-logo p{font-size:12px;color:var(--text3);margin-top:4px;font-family:var(--mono)}
.err-msg{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);border-radius:8px;padding:10px 14px;font-size:12px;color:var(--red);margin-bottom:16px;text-align:center}

/* SIDEBAR */
.sidebar{width:240px;min-height:100vh;background:var(--bg2);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;z-index:100}
.sidebar-logo{padding:20px;border-bottom:1px solid var(--border)}
.logo-mark{display:flex;align-items:center;gap:10px;text-decoration:none}
.logo-icon{width:34px;height:34px;background:linear-gradient(135deg,var(--accent),var(--purple));border-radius:8px;display:flex;align-items:center;justify-content:center;font-family:var(--mono);font-weight:700;font-size:13px;color:#0a0e1a}
.logo-text{font-size:15px;font-weight:700;color:var(--text)}
.logo-sub{font-size:10px;color:var(--text3);font-family:var(--mono)}
.sidebar-nav{padding:12px;flex:1;overflow-y:auto}
.nav-label{font-size:10px;font-weight:600;letter-spacing:.08em;color:var(--text3);text-transform:uppercase;padding:8px 10px 6px;margin-top:8px}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:var(--r);cursor:pointer;color:var(--text2);font-size:13.5px;transition:all .15s;text-decoration:none;position:relative;margin-bottom:2px;border:none;background:none;width:100%;text-align:left}
.nav-item:hover{background:var(--bg3);color:var(--text)}
.nav-item.active{background:rgba(56,189,248,.1);color:var(--accent);font-weight:500}
.nav-item.active::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:20px;background:var(--accent);border-radius:0 3px 3px 0}
.nav-badge{margin-left:auto;background:var(--accent);color:#0a0e1a;font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;font-family:var(--mono)}
.nav-badge.red{background:var(--red);color:#fff}
.sidebar-footer{padding:16px 12px;border-top:1px solid var(--border)}
.user-card{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--r);background:var(--bg3)}
.user-av{width:32px;height:32px;background:linear-gradient(135deg,var(--purple),var(--accent));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;color:#0a0e1a}
.user-name{font-size:13px;font-weight:500}
.user-role{font-size:11px;color:var(--text3)}

/* MAIN */
.main{margin-left:240px;flex:1;min-height:100vh;display:flex;flex-direction:column}
.topbar{height:60px;background:var(--bg2);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 28px;gap:16px;position:sticky;top:0;z-index:50}
.topbar-title{font-size:16px;font-weight:600;flex:1}
.page{display:none}
.page.active{display:block}
.content{padding:28px;flex:1}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:var(--r);font-family:var(--sans);font-size:13px;font-weight:500;cursor:pointer;transition:all .15s;text-decoration:none;border:none}
.btn-primary{background:var(--accent);color:#0a0e1a}
.btn-primary:hover{background:var(--accent3)}
.btn-ghost{background:transparent;color:var(--text2);border:1px solid var(--border2)}
.btn-ghost:hover{background:var(--bg3);color:var(--text)}
.btn-danger{background:rgba(248,113,113,.1);color:var(--red);border:1px solid rgba(248,113,113,.2)}
.btn-danger:hover{background:rgba(248,113,113,.2)}
.btn-success{background:rgba(52,211,153,.1);color:var(--green);border:1px solid rgba(52,211,153,.2)}
.btn-success:hover{background:rgba(52,211,153,.2)}
.btn-warning{background:rgba(251,191,36,.1);color:var(--yellow);border:1px solid rgba(251,191,36,.2)}
.btn-sm{padding:5px 10px;font-size:12px}

/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px}
.stat-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r2);padding:20px;position:relative;overflow:hidden}
.stat-card::after{content:'';position:absolute;top:0;right:0;width:80px;height:80px;border-radius:50%;opacity:.06;transform:translate(20px,-20px)}
.stat-card.blue::after{background:var(--accent)}
.stat-card.green::after{background:var(--green)}
.stat-card.yellow::after{background:var(--yellow)}
.stat-card.purple::after{background:var(--purple)}
.stat-label{font-size:12px;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;font-weight:500;margin-bottom:10px}
.stat-value{font-size:30px;font-weight:700;font-family:var(--mono);color:var(--text);line-height:1;margin-bottom:8px}
.stat-change{font-size:12px;color:var(--text3)}

/* TABLES */
.table-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r2);overflow:hidden;margin-bottom:24px}
.table-header{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
.table-title{font-size:14px;font-weight:600;flex:1}
.search-wrap{position:relative;display:flex;align-items:center}
.search-input{background:var(--bg3);border:1px solid var(--border);border-radius:var(--r);padding:7px 12px 7px 32px;color:var(--text);font-family:var(--sans);font-size:13px;width:200px;outline:none}
.search-input:focus{border-color:var(--border2)}
.search-icon{position:absolute;left:10px;color:var(--text3)}
table{width:100%;border-collapse:collapse}
thead th{font-size:11px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--text3);padding:11px 22px;text-align:left;background:var(--bg3);border-bottom:1px solid var(--border)}
tbody tr{border-bottom:1px solid var(--border);transition:background .1s;cursor:pointer}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:var(--bg3)}
tbody td{padding:13px 22px;font-size:13.5px;vertical-align:middle}
.mono{font-family:var(--mono);font-size:12px}
.text-accent{color:var(--accent)}
.text-muted{color:var(--text3)}

/* BADGES */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;font-family:var(--mono)}
.badge-running{background:rgba(52,211,153,.12);color:var(--green);border:1px solid rgba(52,211,153,.2)}
.badge-stopped{background:rgba(248,113,113,.12);color:var(--red);border:1px solid rgba(248,113,113,.2)}
.badge-pending{background:rgba(251,191,36,.12);color:var(--yellow);border:1px solid rgba(251,191,36,.2)}
.badge-dot{width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block}

/* PROGRESS */
.progress-bar{height:5px;background:var(--bg3);border-radius:99px;overflow:hidden;width:80px}
.progress-fill{height:100%;border-radius:99px}
.fill-green{background:var(--green)}
.fill-yellow{background:var(--yellow)}
.fill-red{background:var(--red)}

/* TERMINAL */
.terminal{background:#060d16;border:1px solid var(--border);border-radius:var(--r2);overflow:hidden;margin-bottom:24px}
.term-header{background:var(--bg3);padding:10px 16px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border)}
.term-dot{width:11px;height:11px;border-radius:50%}
.term-red{background:#ff5f57}
.term-yellow{background:#febc2e}
.term-green{background:#28c840}
.term-title{font-family:var(--mono);font-size:12px;color:var(--text3);margin-left:6px}
.term-body{padding:16px;font-family:var(--mono);font-size:12.5px;color:#8fa3bf;min-height:180px;max-height:350px;overflow-y:auto;line-height:1.7}
.term-line{display:flex;gap:8px}
.term-prompt{color:var(--accent);flex-shrink:0}
.term-cmd{color:#c9d5e3}
.term-out{color:var(--text3)}
.term-success{color:var(--green)}
.term-error{color:var(--red)}
.term-input-wrap{display:flex;align-items:center;gap:8px;padding:12px 16px;border-top:1px solid var(--border);background:var(--bg3)}
.term-input{flex:1;background:transparent;border:none;outline:none;font-family:var(--mono);font-size:12.5px;color:var(--text)}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);z-index:1000;align-items:flex-start;justify-content:center;overflow-y:auto;padding:20px 0}
.modal-overlay.open{display:flex}
.modal{background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r2);padding:28px;width:460px;max-width:95vw;animation:modalIn .2s ease;max-height:95vh;overflow-y:auto}
.modal.modal-lg{width:560px}
@keyframes modalIn{from{opacity:0;transform:scale(.96) translateY(-10px)}to{opacity:1;transform:scale(1) translateY(0)}}
.modal-title{font-size:16px;font-weight:600;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between}
.modal-close{width:28px;height:28px;border-radius:6px;background:var(--bg3);border:none;color:var(--text3);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:16px}
.modal-close:hover{color:var(--text);background:var(--bg4)}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:22px;padding-top:18px;border-top:1px solid var(--border)}

/* FORMS */
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:12px;font-weight:500;color:var(--text3);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em}
.form-input{width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:var(--r);padding:9px 12px;color:var(--text);font-family:var(--sans);font-size:13.5px;outline:none;transition:border-color .15s}
.form-input:focus{border-color:var(--accent)}
.form-input::placeholder{color:var(--text3)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}

/* VOUCHERS */
.voucher-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px;padding:16px}
.voucher-item{background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-family:var(--mono);font-size:12px;text-align:center;cursor:pointer;transition:all .15s;letter-spacing:.05em}
.voucher-item:hover:not(.used){background:rgba(56,189,248,.1);border-color:var(--accent);color:var(--accent)}
.voucher-item.used{opacity:.4;cursor:default;text-decoration:line-through}

/* INFO ROWS */
.info-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r2);padding:20px;margin-bottom:16px}
.info-card-title{font-size:13px;font-weight:600;color:var(--text2);margin-bottom:14px}
.info-row{display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);font-size:13px}
.info-row:last-child{border-bottom:none}
.info-key{color:var(--text3)}
.info-val{color:var(--text);font-family:var(--mono);font-size:12px}
.copy-btn{background:none;border:none;color:var(--text3);cursor:pointer;padding:2px 4px;border-radius:4px;margin-left:6px;transition:color .15s}
.copy-btn:hover{color:var(--accent)}

/* ACTIVITY */
.activity-item{display:flex;gap:14px;padding:13px 22px;border-bottom:1px solid var(--border);align-items:flex-start}
.activity-item:last-child{border-bottom:none}
.activity-dot{width:8px;height:8px;border-radius:50%;margin-top:5px;flex-shrink:0}
.activity-text{font-size:13px;color:var(--text2);line-height:1.5}
.activity-time{font-size:11px;color:var(--text3);font-family:var(--mono)}

/* TOAST */
.toast{position:fixed;bottom:24px;right:24px;background:var(--bg2);border:1px solid var(--border2);border-radius:10px;padding:12px 20px;font-size:13px;color:var(--text);z-index:9999;animation:toastIn .3s ease;box-shadow:0 8px 24px rgba(0,0,0,.3)}
.toast.hide{animation:toastOut .3s ease forwards}
@keyframes toastIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@keyframes toastOut{to{opacity:0;transform:translateY(16px)}}

/* DETAIL */
.detail-pass{display:inline-flex;align-items:center;gap:6px}

@media(max-width:768px){
  .sidebar{transform:translateX(-100%);transition:transform .3s}
  .sidebar.open{transform:translateX(0)}
  .main{margin-left:0}
  .stats-grid{grid-template-columns:1fr 1fr}
}
</style>
</head>
<body>

<?php if (!$is_logged_in): ?>
<!-- ===== LOGIN ===== -->
<div class="login-wrap">
  <div class="login-box">
    <div class="login-logo">
      <div class="logo-icon">XC</div>
      <h1>XcodeHoster VPS</h1>
      <p>Admin Panel — Docker Manager</p>
    </div>
    <?php if ($login_error): ?>
    <div class="err-msg">⚠️ <?= htmlspecialchars($login_error) ?></div>
    <?php endif; ?>
    <form method="POST">
      <div class="form-group">
        <label class="form-label">Username</label>
        <input type="text" name="username" class="form-input" placeholder="admin" required autocomplete="username">
      </div>
      <div class="form-group">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-input" placeholder="••••••••" required autocomplete="current-password">
      </div>
      <button type="submit" name="login" class="btn btn-primary" style="width:100%;justify-content:center;padding:11px">
        🔐 Masuk ke Panel
      </button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ===== DASHBOARD ===== -->

<!-- SIDEBAR -->
<nav class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <a class="logo-mark" href="#">
      <div class="logo-icon">XC</div>
      <div>
        <div class="logo-text">XcodeHoster</div>
        <div class="logo-sub">VPS DOCKER PANEL</div>
      </div>
    </a>
  </div>
  <div class="sidebar-nav">
    <div class="nav-label">Main</div>
    <button class="nav-item active" onclick="showPage('dashboard')">
      <span>📊</span> Dashboard
    </button>
    <button class="nav-item" onclick="showPage('servers')">
      <span>🖥️</span> VPS Containers
      <span class="nav-badge" id="nav-count"><?= count($containers) ?></span>
    </button>
    <button class="nav-item" onclick="showPage('users')">
      <span>👥</span> Manajemen User
      <span class="nav-badge" id="nav-user-count"><?= count($users) ?></span>
    </button>
    <div class="nav-label">Tools</div>
    <button class="nav-item" onclick="showPage('voucher')">
      <span>🎟️</span> Voucher
      <span class="nav-badge"><?= count($vouchers) ?></span>
    </button>
    <button class="nav-item" onclick="showPage('terminal')">
      <span>⌨️</span> Terminal
    </button>
    <button class="nav-item" onclick="showPage('settings')">
      <span>⚙️</span> Pengaturan
    </button>
  </div>
  <div class="sidebar-footer">
    <div class="user-card">
      <div class="user-av">A</div>
      <div>
        <div class="user-name">Administrator</div>
        <div class="user-role">Super Admin</div>
      </div>
      <a href="?logout=1" style="margin-left:auto;color:var(--text3);text-decoration:none;font-size:18px" title="Logout">⏻</a>
    </div>
  </div>
</nav>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-title" id="page-title">Dashboard</div>
    <div style="display:flex;align-items:center;gap:10px">
      <span class="mono text-muted" style="font-size:11px"><?= date('d M Y, H:i') ?> WIB</span>
      <span class="mono" style="font-size:11px;color:var(--green)">● <?= htmlspecialchars($server_ip) ?></span>
      <a href="?logout=1" class="btn btn-ghost btn-sm">Logout</a>
    </div>
  </div>

  <!-- ===== PAGE: DASHBOARD ===== -->
  <div class="page active content" id="page-dashboard">
    <div class="stats-grid">
      <div class="stat-card blue">
        <div class="stat-label">Total VPS</div>
        <div class="stat-value"><?= count($containers) ?></div>
        <div class="stat-change">Container terdaftar</div>
      </div>
      <div class="stat-card green">
        <div class="stat-label">Running</div>
        <div class="stat-value"><?= $running_count ?></div>
        <div class="stat-change">Container aktif</div>
      </div>
      <div class="stat-card yellow">
        <div class="stat-label">Total User</div>
        <div class="stat-value"><?= count($users) ?></div>
        <div class="stat-change">User terdaftar</div>
      </div>
      <div class="stat-card purple">
        <div class="stat-label">Voucher</div>
        <div class="stat-value"><?= count($vouchers) ?></div>
        <div class="stat-change">Tersisa</div>
      </div>
    </div>

    <div class="table-card">
      <div class="table-header">
        <span class="table-title">VPS Terbaru</span>
        <button class="btn btn-primary btn-sm" onclick="openModal('modal-create')">+ Buat VPS</button>
      </div>
      <table>
        <thead><tr>
          <th>Container</th><th>Domain</th><th>SSH Port</th><th>Status</th><th>Action</th>
        </tr></thead>
        <tbody>
          <?php foreach(array_slice($containers, 0, 5) as $c): ?>
          <tr onclick="showDetailModal('<?= $c['id'] ?>')">
            <td><span class="mono text-accent"><?= htmlspecialchars($c['id']) ?></span></td>
            <td><span class="mono" style="font-size:11px"><?= htmlspecialchars($c['user']) ?>.xcodehoster.id</span></td>
            <td><span class="mono"><?= $c['ssh'] ?></span></td>
            <td><span class="badge badge-<?= $c['status'] ?>"><span class="badge-dot"></span><?= ucfirst($c['status']) ?></span></td>
            <td onclick="event.stopPropagation()">
              <div style="display:flex;gap:6px">
                <button class="btn btn-ghost btn-sm" onclick="showDetailModal('<?= $c['id'] ?>')">Detail</button>
                <?php if($c['status'] === 'running'): ?>
                <button class="btn btn-danger btn-sm" onclick="actionVPS('stop','<?= $c['id'] ?>')">Stop</button>
                <?php else: ?>
                <button class="btn btn-success btn-sm" onclick="actionVPS('start','<?= $c['id'] ?>')">Start</button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($containers)): ?>
          <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text3)">Belum ada container. Klik "+ Buat VPS" untuk memulai.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="info-card">
      <div class="info-card-title">📋 Info Server</div>
      <div class="info-row"><span class="info-key">Server IP</span><span class="info-val"><?= htmlspecialchars($server_ip) ?> <button class="copy-btn" onclick="copyText('<?= $server_ip ?>')">⧉</button></span></div>
      <div class="info-row"><span class="info-key">Port SSH Berikutnya</span><span class="info-val"><?= getCurrentPort() ?></span></div>
      <div class="info-row"><span class="info-key">Port Web Berikutnya</span><span class="info-val"><?= getCurrentWebPort() ?></span></div>
      <div class="info-row"><span class="info-key">PHP Version</span><span class="info-val"><?= PHP_VERSION ?></span></div>
      <div class="info-row"><span class="info-key">Server Time</span><span class="info-val"><?= date('d-m-Y H:i:s') ?></span></div>
    </div>
  </div>

  <!-- ===== PAGE: SERVERS ===== -->
  <div class="page content" id="page-servers">
    <div class="table-card">
      <div class="table-header">
        <span class="table-title">VPS Containers</span>
        <div class="search-wrap">
          <span class="search-icon">🔍</span>
          <input type="text" class="search-input" placeholder="Cari container..." oninput="filterVPS(this.value)">
        </div>
        <button class="btn btn-primary btn-sm" onclick="openModal('modal-create')">+ Buat VPS</button>
      </div>
      <table>
        <thead><tr>
          <th>Container ID</th><th>User</th><th>Domain</th><th>SSH Port</th><th>Web Port</th><th>Status</th><th>CPU</th><th>Aksi</th>
        </tr></thead>
        <tbody id="vps-tbody">
          <?php foreach($containers as $c):
            $cpu = $c['cpu'];
            $cpuClass = $cpu > 80 ? 'fill-red' : ($cpu > 60 ? 'fill-yellow' : 'fill-green');
          ?>
          <tr onclick="showDetailModal('<?= $c['id'] ?>')">
            <td><span class="mono text-accent"><?= htmlspecialchars($c['id']) ?></span></td>
            <td><?= htmlspecialchars($c['user']) ?></td>
            <td><span class="mono" style="font-size:11px"><?= htmlspecialchars($c['user']) ?>.xcodehoster.id</span></td>
            <td><span class="mono"><?= $c['ssh'] ?></span></td>
            <td><span class="mono"><?= $c['web'] ?></span></td>
            <td><span class="badge badge-<?= $c['status'] ?>"><span class="badge-dot"></span><?= ucfirst($c['status']) ?></span></td>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <div class="progress-bar"><div class="progress-fill <?= $cpuClass ?>" style="width:<?= $cpu ?>%"></div></div>
                <span class="mono text-muted" style="font-size:11px"><?= $cpu ?>%</span>
              </div>
            </td>
            <td onclick="event.stopPropagation()">
              <div style="display:flex;gap:6px">
                <button class="btn btn-ghost btn-sm" onclick="showDetailModal('<?= $c['id'] ?>')">Detail</button>
                <?php if($c['status'] === 'running'): ?>
                <button class="btn btn-danger btn-sm" onclick="actionVPS('stop','<?= $c['id'] ?>')">Stop</button>
                <?php else: ?>
                <button class="btn btn-success btn-sm" onclick="actionVPS('start','<?= $c['id'] ?>')">Start</button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($containers)): ?>
          <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text3)">Belum ada container Docker.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ===== PAGE: USERS ===== -->
  <div class="page content" id="page-users">
    <div class="table-card">
      <div class="table-header">
        <span class="table-title">Manajemen User (<?= count($users) ?>)</span>
      </div>
      <table>
        <thead><tr>
          <th>Username</th><th>Email</th><th>WhatsApp</th><th>Paket</th><th>Expired</th><th>Tanggal Daftar</th><th>Aksi</th>
        </tr></thead>
        <tbody>
          <?php foreach($users as $u): ?>
          <tr>
            <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
            <td class="mono" style="font-size:12px"><?= htmlspecialchars($u['email']) ?></td>
            <td class="mono"><?= htmlspecialchars($u['wa']) ?></td>
            <td class="mono"><?= htmlspecialchars($u['paket']) ?></td>
            <td class="mono"><?= htmlspecialchars($u['expired']) ?></td>
            <td class="mono text-muted"><?= htmlspecialchars($u['date']) ?></td>
            <td>
              <div style="display:flex;gap:6px">
                <button class="btn btn-ghost btn-sm" 
                  onclick="showUserDetail(this)"
                  data-email="<?= htmlspecialchars($u['email']) ?>"
                  data-username="<?= htmlspecialchars($u['username']) ?>"
                  data-wa="<?= htmlspecialchars($u['wa']) ?>"
                  data-paket="<?= htmlspecialchars($u['paket']) ?>"
                  data-expired="<?= htmlspecialchars($u['expired']) ?>"
                  data-date="<?= htmlspecialchars($u['date']) ?>"
                  data-container="<?= htmlspecialchars($u['container'] ?? '-') ?>"
                  data-password="<?= htmlspecialchars($u['password'] ?? '-') ?>">Detail</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($users)): ?>
          <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text3)">Belum ada user terdaftar.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ===== PAGE: VOUCHER ===== -->
  <div class="page content" id="page-voucher">
    <div class="table-card">
      <div class="table-header">
        <span class="table-title">Voucher Aktivasi (<?= count($vouchers) ?> tersisa)</span>
        <button class="btn btn-ghost btn-sm" onclick="exportVouchers()">⬇ Export</button>
        <button class="btn btn-primary btn-sm" onclick="openModal('modal-genvoucher')">+ Generate</button>
      </div>
      <div class="voucher-grid" id="voucher-grid">
        <?php foreach($vouchers as $v): ?>
        <div class="voucher-item" onclick="copyText('<?= htmlspecialchars($v) ?>')" title="Klik untuk salin">
          <?= htmlspecialchars($v) ?>
        </div>
        <?php endforeach; ?>
        <?php if(empty($vouchers)): ?>
        <div style="grid-column:1/-1;text-align:center;padding:32px;color:var(--text3)">Belum ada voucher. Klik Generate untuk membuat.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ===== PAGE: TERMINAL ===== -->
  <div class="page content" id="page-terminal">
    <div class="terminal">
      <div class="term-header">
        <div class="term-dot term-red"></div>
        <div class="term-dot term-yellow"></div>
        <div class="term-dot term-green"></div>
        <span class="term-title">Docker Terminal — root@xcodehoster</span>
        <button class="btn btn-ghost btn-sm" style="margin-left:auto" onclick="clearTerm()">Clear</button>
      </div>
      <div class="term-body" id="term-output">
        <div class="term-line"><span class="term-prompt">root@xcode:~#</span><span class="term-cmd"> XcodeHoster VPS Panel — Docker Terminal</span></div>
        <div class="term-success">✓ Terhubung ke server <?= htmlspecialchars($server_ip) ?></div>
        <div class="term-out">Ketik perintah docker di bawah. Contoh: docker ps</div>
        <br>
      </div>
      <div class="term-input-wrap">
        <span class="term-prompt">root@xcode:~#</span>
        <input type="text" class="term-input" id="term-input" placeholder="docker ps -a" onkeydown="handleTermKey(event)">
        <button class="btn btn-primary btn-sm" onclick="runTermCmd()">▶ Run</button>
      </div>
    </div>

    <div class="info-card">
      <div class="info-card-title">🚀 Perintah Cepat</div>
      <div style="display:flex;flex-wrap:wrap;gap:8px;padding-top:8px">
        <button class="btn btn-ghost btn-sm" onclick="setCmd('docker ps -a')">docker ps -a</button>
        <button class="btn btn-ghost btn-sm" onclick="setCmd('docker images')">docker images</button>
        <button class="btn btn-ghost btn-sm" onclick="setCmd('docker stats --no-stream')">docker stats</button>
        <button class="btn btn-ghost btn-sm" onclick="setCmd('docker system df')">docker system df</button>
      </div>
    </div>
  </div>

  <!-- ===== PAGE: SETTINGS ===== -->
  <div class="page content" id="page-settings">
    <div class="info-card">
      <div class="info-card-title">⚙️ Konfigurasi Server</div>
      <div class="info-row"><span class="info-key">Server IP</span><span class="info-val"><?= htmlspecialchars($server_ip) ?></span></div>
      <div class="info-row"><span class="info-key">Port SSH Berikutnya</span><span class="info-val"><?= getCurrentPort() ?></span></div>
      <div class="info-row"><span class="info-key">Port Web Berikutnya</span><span class="info-val"><?= getCurrentWebPort() ?></span></div>
      <div class="info-row"><span class="info-key">Voucher File</span><span class="info-val mono"><?= VOUCHER_FILE ?></span></div>
      <div class="info-row"><span class="info-key">PHP Version</span><span class="info-val"><?= PHP_VERSION ?></span></div>
      <div class="info-row"><span class="info-key">Server Time</span><span class="info-val"><?= date('d-m-Y H:i:s') ?></span></div>
    </div>
    <div class="info-card">
      <div class="info-card-title">📖 Panduan Docker</div>
      <div class="info-row"><span class="info-key">Build Image</span><span class="info-val mono">docker build -t xcodedata .</span></div>
      <div class="info-row"><span class="info-key">Lihat Container</span><span class="info-val mono">docker ps -a</span></div>
      <div class="info-row"><span class="info-key">Stop Container</span><span class="info-val mono">docker stop server12100</span></div>
      <div class="info-row"><span class="info-key">Remove Container</span><span class="info-val mono">docker rm server12100</span></div>
      <div class="info-row"><span class="info-key">SSH ke Container</span><span class="info-val mono">ssh root@<?= htmlspecialchars($server_ip) ?> -p [PORT]</span></div>
    </div>
  </div>
</div><!-- end .main -->
<!-- ===== MODAL: DETAIL VPS ===== -->
<div class="modal-overlay" id="modal-detail">
  <div class="modal" style="width:520px;max-height:90vh;overflow-y:auto">
    <div class="modal-title">
      <span id="detail-title">Detail VPS</span>
      <button class="modal-close" onclick="closeModal('modal-detail')">x</button>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
      <div><div class="form-label">Container</div><div class="mono text-accent" id="d-container" style="font-size:13px">-</div></div>
      <div><div class="form-label">Status</div><div id="d-status"><span class="badge badge-pending"><span class="badge-dot"></span>Loading...</span></div></div>
    </div>
    <div style="border:1px solid var(--border);border-radius:var(--r);overflow:hidden;margin-bottom:16px">
      <div class="info-row" style="padding:9px 14px"><span class="info-key">SSH Host</span><span class="info-val" id="d-host">-</span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">SSH Port</span><span class="info-val" id="d-port">-</span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">Web Port</span><span class="info-val" id="d-web">-</span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">Username SSH</span><span class="info-val">root</span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">Password</span><span class="info-val"><span id="d-pass">*******</span> <button class="copy-btn" onclick="togglePass()">lihat</button></span></div>
      <div class="info-row" style="padding:9px 14px;border-bottom:none"><span class="info-key">Dibuat</span><span class="info-val" id="d-date">-</span></div>
    </div>
    <div class="terminal" style="margin-bottom:16px">
      <div class="term-header">
        <div class="term-dot term-red"></div>
        <div class="term-dot term-yellow"></div>
        <div class="term-dot term-green"></div>
        <span class="term-title">SSH Command</span>
      </div>
      <div class="term-body" style="min-height:50px">
        <div class="term-line"><span class="term-prompt">$</span><span class="term-cmd" id="d-sshcmd"> -</span></div>
      </div>
    </div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-success btn-sm" onclick="actionCurrentVPS('start')">Start</button>
      <button class="btn btn-ghost btn-sm" onclick="actionCurrentVPS('restart')">Restart</button>
      <button class="btn btn-ghost btn-sm" onclick="actionCurrentVPS('stop')">Stop</button>
      <button class="btn btn-danger btn-sm" style="margin-left:auto" onclick="confirmDeleteVPS()">Hapus</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: CREATE VPS ===== -->
<div class="modal-overlay" id="modal-create">
  <div class="modal">
    <div class="modal-title">
      🖥️ Buat VPS Baru
      <button class="modal-close" onclick="closeModal('modal-create')">×</button>
    </div>
    <div class="form-group">
      <label class="form-label">Username (huruf kecil & angka)</label>
      <input type="text" class="form-input" id="new-username" placeholder="useranda" pattern="[a-z0-9]+" oninput="updatePreview()">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Password</label>
        <input type="text" class="form-input" id="new-password" placeholder="password123" oninput="updatePreview()">
      </div>
      <div class="form-group">
        <label class="form-label">Email</label>
        <input type="email" class="form-input" id="new-email" placeholder="user@email.com">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">WhatsApp (opsional)</label>
      <input type="text" class="form-input" id="new-wa" placeholder="08123456789">
    </div>
    <div class="form-group">
      <label class="form-label">Paket VPS</label>
      <select class="form-input" id="new-paket">
        <option value="bulanan">Bulanan (30 hari) - Rp 50.000</option>
        <option value="tahunan">Tahunan (365 hari) - Rp 500.000</option>
      </select>
    </div>
    <div class="terminal" style="margin-bottom:0">
      <div class="term-header">
        <div class="term-dot term-dot-red"></div>
        <div class="term-dot term-dot-yellow"></div>
        <div class="term-dot term-dot-green"></div>
        <span class="term-title">Preview Docker Command</span>
      </div>
      <div class="term-body" id="preview-cmd" style="min-height:60px">
        <div class="term-line"><span class="term-prompt">root@xcode:~#</span><span class="term-cmd"> Isi form untuk preview...</span></div>
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-create')">Batal</button>
      <button class="btn btn-primary" onclick="createVPS()">🚀 Buat VPS</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: GENERATE VOUCHER ===== -->
<div class="modal-overlay" id="modal-genvoucher">
  <div class="modal">
    <div class="modal-title">
      🎟️ Generate Voucher
      <button class="modal-close" onclick="closeModal('modal-genvoucher')">×</button>
    </div>
    <div class="form-group">
      <label class="form-label">Jumlah Voucher</label>
      <input type="number" class="form-input" id="voucher-count" value="100" min="10" max="1000">
    </div>
    <p style="font-size:12px;color:var(--text3)">⚠️ Voucher lama akan diganti dengan yang baru.</p>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-genvoucher')">Batal</button>
      <button class="btn btn-primary" onclick="generateVouchers()">Generate</button>
    </div>
  </div>
</div>

<script>
let currentDetailId = null;
let currentDetailData = null;
const nextPort = <?= getCurrentPort() ?>;

// NAV
function showPage(name) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('page-' + name).classList.add('active');
  event.currentTarget.classList.add('active');
  const titles = {dashboard:'Dashboard',servers:'VPS Containers',users:'Manajemen User',voucher:'Voucher',terminal:'Terminal',settings:'Pengaturan'};
  document.getElementById('page-title').textContent = titles[name] || name;
}

// MODALS
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

// TOAST
function showToast(msg, type='info') {
  const t = document.createElement('div');
  t.className = 'toast';
  t.style.borderColor = type === 'success' ? 'var(--green)' : type === 'error' ? 'var(--red)' : 'var(--border2)';
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => { t.classList.add('hide'); setTimeout(() => t.remove(), 300); }, 3000);
}

// AJAX helper
async function post(data) {
  const fd = new FormData();
  for (const k in data) fd.append(k, data[k]);
  const res = await fetch('/index.php', { method: 'POST', body: fd });
  return res.json();
}

// CREATE VPS
function updatePreview() {
  const user = document.getElementById('new-username').value || 'username';
  const pass = document.getElementById('new-password').value || 'password';
  document.getElementById('preview-cmd').innerHTML = `<div class="term-line"><span class="term-prompt">root@xcode:~#</span><span class="term-cmd"> docker run -d --name server${nextPort} -e ROOT_PASSWORD=${pass} -p ${nextPort}:22 -p ${nextPort+9000}:80 --restart=always xcodedata</span></div>`;
}

async function createVPS() {
  const user = document.getElementById('new-username').value;
  const pass = document.getElementById('new-password').value;
  const email = document.getElementById('new-email').value;
  if (!user || !pass || !email) { showToast('⚠ Lengkapi semua field', 'error'); return; }
  showToast('⏳ Membuat VPS...');
  const wa = document.getElementById('new-wa').value;
  const paket = document.getElementById('new-paket').value;
  const res = await post({ action: 'create_vps', username: user, password: pass, email, wa, paket });
  if (res.success) {
    showToast('✓ ' + res.msg, 'success');
    closeModal('modal-create');
    setTimeout(() => location.reload(), 2000);
  } else {
    showToast('✗ ' + res.msg, 'error');
  }
}

// VPS ACTIONS
async function actionVPS(action, id) {
  showToast(`⏳ ${action} ${id}...`);
  const res = await post({ action: action + '_vps', id });
  showToast(res.success ? '✓ ' + res.msg : '✗ ' + res.msg, res.success ? 'success' : 'error');
  if (res.success) setTimeout(() => location.reload(), 2000);
}

async function actionCurrentVPS(action) {
  if (!currentDetailId) return;
  closeModal('modal-detail');
  await actionVPS(action, currentDetailId);
}

function confirmDeleteVPS() {
  if (!currentDetailId) return;
  if (confirm(`Yakin hapus container ${currentDetailId}? Semua data akan hilang!`)) {
    closeModal('modal-detail');
    actionVPS('delete', currentDetailId);
  }
}

// DETAIL MODAL
async function showDetailModal(id) {
  currentDetailId = id;
  document.getElementById('detail-title').textContent = 'Detail VPS — ' + id;
  document.getElementById('d-container').textContent = id;
  document.getElementById('d-status').innerHTML = '<span class="badge badge-pending"><span class="badge-dot"></span>Loading...</span>';
  openModal('modal-detail');
  const res = await post({ action: 'get_detail', id });
  if (res.success) {
    currentDetailData = res;
    const badgeClass = res.status === 'running' ? 'badge-running' : 'badge-stopped';
    document.getElementById('d-status').innerHTML = `<span class="badge ${badgeClass}"><span class="badge-dot"></span>${res.status}</span>`;
    document.getElementById('d-port').innerHTML = `${res.ssh_port} <button class="copy-btn" onclick="copyText('${res.ssh_port}')">⧉</button>`;
    document.getElementById('d-web').textContent = res.web_port;
    document.getElementById('d-pass').textContent = '•••••••';
    document.getElementById('d-host').textContent = res.ip;
    document.getElementById('d-sshcmd').textContent = ` ssh root@${res.ip} -p ${res.ssh_port}`;
    document.getElementById('d-date').textContent = res.created;
  }
}

function togglePass() {
  const el = document.getElementById('d-pass');
  if (!currentDetailData) return;
  const showing = el.textContent.includes('•');
  el.textContent = showing ? currentDetailData.password : '•••••••';
}

// FILTER VPS
function filterVPS(q) {
  document.querySelectorAll('#vps-tbody tr').forEach(tr => {
    tr.style.display = tr.textContent.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
  });
}

// VOUCHERS
function exportVouchers() {
  const codes = [...document.querySelectorAll('.voucher-item:not(.used)')].map(v => v.textContent.trim());
  const blob = new Blob([codes.join('\n')], { type: 'text/plain' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'vouchers.txt';
  a.click();
  showToast('✓ File vouchers.txt diunduh', 'success');
}

async function generateVouchers() {
  const count = document.getElementById('voucher-count').value;
  const res = await post({ action: 'generate_vouchers', count });
  if (res.success) {
    showToast('✓ ' + res.msg, 'success');
    closeModal('modal-genvoucher');
    setTimeout(() => location.reload(), 1500);
  }
}

// TERMINAL
const cmdHistory = [];
let histIdx = 0;

function handleTermKey(e) {
  if (e.key === 'Enter') runTermCmd();
  if (e.key === 'ArrowUp' && histIdx > 0) { histIdx--; e.target.value = cmdHistory[histIdx]; }
  if (e.key === 'ArrowDown' && histIdx < cmdHistory.length - 1) { histIdx++; e.target.value = cmdHistory[histIdx]; }
}

async function runTermCmd() {
  const input = document.getElementById('term-input');
  const cmd = input.value.trim();
  if (!cmd) return;
  cmdHistory.push(cmd);
  histIdx = cmdHistory.length;
  addTermLine(cmd, '⏳ Menjalankan...');
  input.value = '';
  const res = await post({ action: 'run_command', cmd });
  const term = document.getElementById('term-output');
  // Hapus baris loading terakhir
  term.lastElementChild?.remove();
  if (res.success) {
    addTermLine(cmd, res.output || '(no output)', 'out', false);
  } else {
    addTermLine(cmd, res.msg, 'error', false);
  }
}

function addTermLine(cmd, out, type='out', showCmd=true) {
  const term = document.getElementById('term-output');
  if (showCmd) {
    term.innerHTML += `<div class="term-line"><span class="term-prompt">root@xcode:~#</span><span class="term-cmd"> ${cmd}</span></div>`;
  }
  if (out) {
    out.split('\n').forEach(line => {
      if (line) term.innerHTML += `<div class="term-${type}">${line}</div>`;
    });
  }
  term.innerHTML += '<br>';
  term.scrollTop = term.scrollHeight;
}

function clearTerm() {
  document.getElementById('term-output').innerHTML = '<div class="term-line"><span class="term-prompt">root@xcode:~#</span><span class="term-cmd"> clear</span></div><br>';
}

function setCmd(cmd) {
  document.getElementById('term-input').value = cmd;
  showPage('terminal');
}


// USER DETAIL
let currentUserPass = '';
function showUserDetail(btn) {
  const d = btn.dataset;
  currentUserPass = d.password;
  document.getElementById('ud-title').textContent = 'Detail User — ' + d.username;
  document.getElementById('ud-username').textContent = d.username;
  document.getElementById('ud-email').textContent = d.email;
  document.getElementById('ud-wa').textContent = d.wa;
  document.getElementById('ud-paket').textContent = d.paket;
  document.getElementById('ud-expired').textContent = d.expired;
  document.getElementById('ud-date').textContent = d.date;
  document.getElementById('ud-container').textContent = d.container;
  document.getElementById('ud-pass').textContent = '*******';
  openModal('modal-user-detail');
}
function toggleUserPass() {
  const el = document.getElementById('ud-pass');
  el.textContent = el.textContent.includes('*') ? currentUserPass : '*******';
}

// COPY
function copyText(text) {
  navigator.clipboard.writeText(text).then(() => showToast('✓ Disalin: ' + text, 'success')).catch(() => showToast('Gagal menyalin', 'error'));
}
</script>

<?php endif; ?>

<!-- ===== MODAL: DETAIL USER ===== -->
<div class="modal-overlay" id="modal-user-detail">
  <div class="modal" style="width:500px">
    <div class="modal-title">
      <span id="ud-title">Detail User</span>
      <button class="modal-close" onclick="closeModal('modal-user-detail')">x</button>
    </div>
    <div style="border:1px solid var(--border);border-radius:var(--r);overflow:hidden;margin-bottom:16px">
      <div class="info-row" style="padding:9px 14px"><span class="info-key">Username</span><span class="info-val" id="ud-username">-</span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">Email</span><span class="info-val" id="ud-email">-</span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">WhatsApp</span><span class="info-val" id="ud-wa">-</span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">Paket</span><span class="info-val" id="ud-paket">-</span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">Expired</span><span class="info-val" id="ud-expired">-</span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">Tanggal Daftar</span><span class="info-val" id="ud-date">-</span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">Container</span><span class="info-val" id="ud-container">-</span></div>
      <div class="info-row" style="padding:9px 14px;border-bottom:none"><span class="info-key">Password VPS</span><span class="info-val"><span id="ud-pass">*******</span> <button class="copy-btn" onclick="toggleUserPass()">lihat</button></span></div>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button class="btn btn-ghost" onclick="closeModal('modal-user-detail')">Tutup</button>
    </div>
  </div>
</div>

</body>
</html>
