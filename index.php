<?php
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
session_start();

define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'admin123');
define('BASE_PORT', 12100);
define('BASE_WEB_PORT', 21100);
define('PORT_FILE', '/usr/lib/cgi-bin/port.txt');
define('PORTWEB_FILE', '/usr/lib/cgi-bin/portweb.txt');
define('VOUCHER_FILE', '/usr/lib/cgi-bin/vouchers.txt');
define('IP_FILE', '/usr/lib/cgi-bin/ip.txt');
define('CHECKDATA2_DIR', '/home/checkdata2/');
define('CHECKDATA_DIR', '/home/checkdata/');
define('RAMBUTAN_DIR', '/home/rambutan/');
define('LOG_FILE', '/tmp/activity.log');
define('CF_ZONE', '3902676282e7d9649acadfde7e43278f');
define('CF_EMAIL', 'Mftharizky@gmail.com');
define('CF_KEY', 'c57c3a383e270f1e3978515157339c40474e1');
define('CF_DOMAIN', 'tugaspkl.my.id');
define('WA_CONF', '/var/www/html/tugasDocker/wa.conf');

function getWAConfig() {
    $conf = ['token'=>'','sender'=>''];
    if (file_exists(WA_CONF)) {
        foreach (file(WA_CONF) as $line) {
            if (strpos($line,'WATOKEN=')===0) $conf['token']=trim(substr($line,8));
            if (strpos($line,'WASENDER=')===0) $conf['sender']=trim(substr($line,9));
        }
    }
    return $conf;
}

function sendWA($number, $message) {
    $wa = getWAConfig();
    if (!$wa['token'] || $wa['token']==='isi_token_fonnte_kamu') return false;
    $ch = curl_init('https://api.fonnte.com/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: '.$wa['token'], 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['target'=>$number,'message'=>$message])
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function logActivity($msg, $type='info') {
    $line = date('Y-m-d H:i:s').'|'.$type.'|'.$msg."\n";
    file_put_contents(LOG_FILE, $line, FILE_APPEND);
}

function getActivityLog($limit=20) {
    if (!file_exists(LOG_FILE)) return [];
    $lines = array_reverse(array_filter(explode("\n", trim(file_get_contents(LOG_FILE)))));
    $result = [];
    foreach (array_slice($lines, 0, $limit) as $line) {
        $parts = explode('|', $line, 3);
        if (count($parts) === 3) {
            $result[] = ['time'=>$parts[0],'type'=>$parts[1],'msg'=>$parts[2]];
        }
    }
    return $result;
}

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
            $ssh_port = '-'; $web_port = '-';
            if (preg_match('/0\.0\.0\.0:(\d+)->22/', $ports, $m)) $ssh_port = $m[1];
            if (preg_match('/0\.0\.0\.0:(\d+)->80/', $ports, $m)) $web_port = $m[1];
            $containers[] = [
                'id'=>$name, 'user'=>str_replace('server','',$name),
                'ssh'=>$ssh_port, 'web'=>$web_port,
                'status'=>$running?'running':'stopped',
                'cpu'=>0, 'date'=>date('d-m-Y'),
            ];
        }
    }
    return $containers;
}

function getUserList() {
    $users = [];
    if (is_dir(CHECKDATA2_DIR)) {
        foreach (scandir(CHECKDATA2_DIR) as $f) {
            if ($f==='.'||$f==='..'||$f==='locked') continue;
            $data = trim(file_get_contents(CHECKDATA2_DIR.$f));
            $parts = array_map('trim', explode(',', $data));
            $expired = $parts[7] ?? '-';
            // Convert d-m-Y ke Y-m-d untuk strtotime
            $exp_parts = explode('-', $expired);
            $exp_ts = (count($exp_parts) === 3) ? mktime(0,0,0,(int)$exp_parts[1],(int)$exp_parts[0],(int)$exp_parts[2]) : 0;
            $diff = $exp_ts ? round(($exp_ts-time())/86400) : 0;
            $users[] = [
                'username'=>$parts[0]??'', 'password'=>$parts[1]??'',
                'email'=>$parts[2]??$f, 'wa'=>$parts[3]??'',
                'date'=>$parts[4]??'', 'port'=>$parts[5]??'',
                'paket'=>$parts[6]??'bulanan', 'expired'=>$expired,
                'container'=>rtrim($parts[8]??'-','.'),
                'days_left'=>$diff,
            ];
        }
    }
    return $users;
}

function getVoucherList() {
    if (!file_exists(VOUCHER_FILE)) return [];
    return array_values(array_filter(explode("\n", trim(file_get_contents(VOUCHER_FILE)))));
}

function getCurrentPort() {
    return (int)(file_exists(PORT_FILE)?trim(file_get_contents(PORT_FILE)):BASE_PORT);
}
function getCurrentWebPort() {
    return (int)(file_exists(PORTWEB_FILE)?trim(file_get_contents(PORTWEB_FILE)):BASE_WEB_PORT);
}
function getServerIP() {
    return file_exists(IP_FILE)?trim(file_get_contents(IP_FILE)):'0.0.0.0';
}
function dockerCommand($cmd) {
    return shell_exec("docker $cmd 2>&1");
}

function cfAddDNS($name, $ip) {
    $ch = curl_init('https://api.cloudflare.com/client/v4/zones/'.CF_ZONE.'/dns_records');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>['X-Auth-Email: '.CF_EMAIL,'X-Auth-Key: '.CF_KEY,'Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode(['type'=>'A','name'=>$name.'.'.CF_DOMAIN,'content'=>$ip,'ttl'=>120,'proxied'=>true])
    ]);
    $res = json_decode(curl_exec($ch),true);
    curl_close($ch);
    return $res;
}

function cfListDNS() {
    $ch = curl_init('https://api.cloudflare.com/client/v4/zones/'.CF_ZONE.'/dns_records?per_page=100');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>['X-Auth-Email: '.CF_EMAIL,'X-Auth-Key: '.CF_KEY,'Content-Type: application/json']
    ]);
    $res = json_decode(curl_exec($ch),true);
    curl_close($ch);
    return $res['result'] ?? [];
}

function cfDeleteDNS($record_id) {
    $ch = curl_init('https://api.cloudflare.com/client/v4/zones/'.CF_ZONE.'/dns_records/'.$record_id);
    curl_setopt_array($ch,[
        CURLOPT_CUSTOMREQUEST=>'DELETE', CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>['X-Auth-Email: '.CF_EMAIL,'X-Auth-Key: '.CF_KEY,'Content-Type: application/json']
    ]);
    $res = json_decode(curl_exec($ch),true);
    curl_close($ch);
    return $res;
}

// AJAX ACTIONS
if (isset($_POST['action']) && isset($_SESSION['logged_in'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'create_vps') {
        $user = preg_replace('/[^a-z0-9]/','',strtolower($_POST['username']??''));
        $pass = preg_replace('/[^a-zA-Z0-9]/','',$_POST['password']??'');
        $email = $_POST['email']??'';
        $wa = $_POST['wa']??'-';
        $paket = $_POST['paket']??'bulanan';
        $voucher_input = trim($_POST['voucher']??'');
        $durasi = ($paket==='tahunan')?365:30;
        $expired = date('d-m-Y',strtotime("+{$durasi} days"));
        if (!$user||!$pass||!$email) { echo json_encode(['success'=>false,'msg'=>'Lengkapi semua field']); exit; }
        // Validasi voucher
        $vouchers = getVoucherList();
        if (!in_array($voucher_input, $vouchers)) { echo json_encode(['success'=>false,'msg'=>'Voucher tidak valid atau sudah digunakan']); exit; }
        $port = getCurrentPort();
        $webport = getCurrentWebPort();
        $cmd = "docker run -d --name server{$port} -e ROOT_PASSWORD={$pass} -p {$port}:22 -p {$webport}:80 --restart=always xcodedata 2>&1";
        $out = shell_exec($cmd);
        file_put_contents(PORT_FILE, $port+1);
        file_put_contents(PORTWEB_FILE, $webport+1);
        $tanggal = date('d-m-Y');
        @file_put_contents(CHECKDATA2_DIR.$email, "$user, $pass, $email, $wa, $tanggal, $port, $paket, $expired, server{$port}.");
        @file_put_contents(CHECKDATA_DIR.$email, $email);
        @file_put_contents(RAMBUTAN_DIR."$user.$tanggal", "$user, $pass, $email, $wa, $tanggal, $port, $paket, $expired, server{$port}.");
        // Hapus voucher
        $vouchers = array_filter($vouchers, fn($v)=>$v!==$voucher_input);
        file_put_contents(VOUCHER_FILE, implode("\n",$vouchers));
        // Portmap
        $pm = '/etc/apache2/portmap.txt';
        $pmc = file_exists($pm)?file_get_contents($pm):'';
        if (strpos($pmc,"\n{$user} ")===false) { file_put_contents($pm,$pmc."\n{$user} {$webport}"); shell_exec('sudo systemctl reload apache2 2>/dev/null'); }
        // DNS Cloudflare
        cfAddDNS($user, getServerIP());
        // WA notif
        if ($wa && $wa!=='-') sendWA($wa, "✅ VPS Anda berhasil dibuat!\nUsername: $user\nSSH Port: $port\nPassword: $pass\nExpired: $expired\nSSH: ssh root@".getServerIP()." -p $port");
        logActivity("VPS server{$port} dibuat untuk user $user ($email)",'create');
        // Buat user Linux untuk SSH
        shell_exec("/usr/local/bin/create-vps-user.sh $user $pass server{$port} 2>/dev/null &");
        echo json_encode(['success'=>true,'msg'=>"VPS server{$port} berhasil dibuat",'output'=>$out,'port'=>$port,'webport'=>$webport]);
        exit;
    }

    if ($action==='stop_vps') { $id=$_POST['id']??''; $out=dockerCommand("stop $id"); logActivity("VPS $id dihentikan",'stop'); echo json_encode(['success'=>true,'msg'=>"Container $id dihentikan",'output'=>$out]); exit; }
    if ($action==='start_vps') { $id=$_POST['id']??''; $out=dockerCommand("start $id"); logActivity("VPS $id dijalankan",'start'); echo json_encode(['success'=>true,'msg'=>"Container $id dijalankan",'output'=>$out]); exit; }
    if ($action==='restart_vps') { $id=$_POST['id']??''; $out=dockerCommand("restart $id"); logActivity("VPS $id di-restart",'restart'); echo json_encode(['success'=>true,'msg'=>"Container $id di-restart",'output'=>$out]); exit; }

    if ($action==='delete_vps') {
        $id=$_POST['id']??'';
        dockerCommand("stop $id");
        $out=dockerCommand("rm $id");
        foreach (glob(CHECKDATA2_DIR.'*') as $file) {
            if (basename($file)==='locked') continue;
            $data=trim(file_get_contents($file));
            $parts=array_map('trim',explode(',',rtrim($data,'.')));
            if (rtrim($parts[8]??'','.')===$id) { unlink($file); break; }
        }
        logActivity("VPS $id dihapus",'delete');
        echo json_encode(['success'=>true,'msg'=>"Container $id dan user dihapus",'output'=>$out]);
        exit;
    }

    if ($action==='renew_vps') {
        $email=$_POST['email']??''; $voucher_input=trim($_POST['voucher']??''); $paket=$_POST['paket']??'bulanan';
        $vouchers=getVoucherList();
        if (!in_array($voucher_input,$vouchers)) { echo json_encode(['success'=>false,'msg'=>'Voucher tidak valid']); exit; }
        $file=CHECKDATA2_DIR.$email;
        if (!file_exists($file)) { echo json_encode(['success'=>false,'msg'=>'User tidak ditemukan']); exit; }
        $data=trim(file_get_contents($file));
        $parts=array_map('trim',explode(',',rtrim($data,'.')));
        $durasi=($paket==='tahunan')?365:30;
        $cur_exp=strtotime(str_replace('-','/',$parts[7]??date('d-m-Y')));
        $base=($cur_exp>time())?$cur_exp:time();
        $new_expired=date('d-m-Y',$base+($durasi*86400));
        $parts[6]=$paket; $parts[7]=$new_expired;
        file_put_contents($file,implode(', ',$parts).'.');
        $vouchers=array_filter($vouchers,fn($v)=>$v!==$voucher_input);
        file_put_contents(VOUCHER_FILE,implode("\n",$vouchers));
        if (($parts[3]??'-')!=='-') sendWA($parts[3],"✅ VPS Anda berhasil diperpanjang!\nExpired baru: $new_expired");
        logActivity("VPS user {$parts[0]} diperpanjang hingga $new_expired",'renew');
        echo json_encode(['success'=>true,'msg'=>"VPS diperpanjang hingga $new_expired"]);
        exit;
    }

    if ($action==='run_command') {
        $cmd=$_POST['cmd']??'';
        if (!preg_match('/^docker\s/',$cmd)) { echo json_encode(['success'=>false,'msg'=>'Hanya perintah docker yang diizinkan']); exit; }
        $out=shell_exec("$cmd 2>&1");
        echo json_encode(['success'=>true,'output'=>htmlspecialchars($out)]);
        exit;
    }

    if ($action==='generate_vouchers') {
        $count=(int)($_POST['count']??100);
        $v=[];
        for($i=0;$i<$count;$i++) $v[]=strtoupper(substr(bin2hex(random_bytes(5)),0,8));
        file_put_contents(VOUCHER_FILE,implode("\n",$v));
        logActivity("$count voucher baru digenerate",'voucher');
        echo json_encode(['success'=>true,'msg'=>"$count voucher digenerate",'count'=>$count]);
        exit;
    }

    if ($action==='delete_voucher') {
        $code=$_POST['code']??'';
        $v=array_filter(getVoucherList(),fn($x)=>$x!==$code);
        file_put_contents(VOUCHER_FILE,implode("\n",$v));
        echo json_encode(['success'=>true,'msg'=>"Voucher $code dihapus"]);
        exit;
    }

    if ($action==='get_detail') {
        $id=$_POST['id']??'';
        $info=shell_exec("docker inspect $id 2>/dev/null");
        $data=json_decode($info,true);
        if ($data&&isset($data[0])) {
            $c=$data[0];
            $ports=$c['HostConfig']['PortBindings']??[];
            $ssh_port=$ports['22/tcp'][0]['HostPort']??'-';
            $web_port=$ports['80/tcp'][0]['HostPort']??'-';
            $pass='';
            foreach ($c['Config']['Env']??[] as $e) if (strpos($e,'ROOT_PASSWORD=')===0) $pass=substr($e,14);
            // Cari user data
            $user_data=null;
            foreach (glob(CHECKDATA2_DIR.'*') as $f) {
                if (basename($f)==='locked') continue;
                $d=trim(file_get_contents($f));
                $p=array_map('trim',explode(',',rtrim($d,'.')));
                if (rtrim($p[8]??'','.')===$id) { $user_data=$p; break; }
            }
            echo json_encode(['success'=>true,'id'=>$id,'status'=>$c['State']['Status']??'-','ssh_port'=>$ssh_port,'web_port'=>$web_port,'password'=>$pass,'created'=>date('d-m-Y H:i',strtotime($c['Created']??'')),'ip'=>getServerIP(),'user_data'=>$user_data]);
        } else { echo json_encode(['success'=>false,'msg'=>'Container tidak ditemukan']); }
        exit;
    }

    if ($action==='cf_list') { echo json_encode(['success'=>true,'records'=>cfListDNS()]); exit; }
    if ($action==='cf_add') { $name=$_POST['name']??''; $ip=$_POST['ip']??getServerIP(); $res=cfAddDNS($name,$ip); logActivity("DNS $name.".CF_DOMAIN." ditambahkan",'dns'); echo json_encode(['success'=>$res['success']??false,'msg'=>$res['success']?'DNS ditambahkan':'Gagal: '.($res['errors'][0]['message']??'error')]); exit; }
    if ($action==='cf_delete') { $rid=$_POST['record_id']??''; $res=cfDeleteDNS($rid); logActivity("DNS record $rid dihapus",'dns'); echo json_encode(['success'=>$res['success']??false]); exit; }

    if ($action==='save_settings') {
        $wa_token=trim($_POST['wa_token']??''); $wa_sender=trim($_POST['wa_sender']??'');
        if ($wa_token) file_put_contents(WA_CONF,"WATOKEN=$wa_token\nWASENDER=$wa_sender\n");
        echo json_encode(['success'=>true,'msg'=>'Pengaturan disimpan']);
        exit;
    }

    if ($action==='get_logs') { echo json_encode(['success'=>true,'logs'=>getActivityLog(30)]); exit; }

    echo json_encode(['success'=>false,'msg'=>'Action tidak dikenal']);
    exit;
}

// LOGIN/LOGOUT
$login_error='';
if (isset($_POST['login'])) {
    if ($_POST['username']===ADMIN_USER&&$_POST['password']===ADMIN_PASS) { $_SESSION['logged_in']=true; header('Location: '.$_SERVER['PHP_SELF']); exit; }
    $login_error='Username atau password salah!';
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: '.$_SERVER['PHP_SELF']); exit; }

$is_logged_in=isset($_SESSION['logged_in'])&&$_SESSION['logged_in'];
$containers=$is_logged_in?getDockerContainers():[];
$users=$is_logged_in?getUserList():[];
$vouchers=$is_logged_in?getVoucherList():[];
$server_ip=$is_logged_in?getServerIP():'';
$running_count=count(array_filter($containers,fn($c)=>$c['status']==='running'));
$stopped_count=count($containers)-$running_count;
$logs=$is_logged_in?getActivityLog(10):[];
$wa_conf=$is_logged_in?getWAConfig():['token'=>'','sender'=>''];
// CPU/RAM/Disk stats
$cpu_usage=0; $ram_used=0; $ram_total=0; $disk_used=0; $disk_total=0;
if ($is_logged_in) {
    // CPU real
    $cpu_raw = shell_exec("grep 'cpu ' /proc/stat");
    $cpu_usage = 0;
    if ($cpu_raw) {
        $cpu_parts = preg_split('/\s+/', trim($cpu_raw));
        $total = array_sum(array_slice($cpu_parts, 1));
        $idle = $cpu_parts[4] ?? 0;
        $cpu_usage = $total > 0 ? round((1 - $idle/$total) * 100) : 0;
    }
    // RAM real
    $mem=shell_exec("free -m | grep Mem");
    $ram_total=0; $ram_used=0;
    if ($mem) { preg_match('/Mem:\s+(\d+)\s+(\d+)/',$mem,$mm); $ram_total=$mm[1]??0; $ram_used=$mm[2]??0; }
    // Disk real
    $disk=shell_exec("df -BG / | tail -1");
    $disk_total=0; $disk_used=0;
    if ($disk) { preg_match('/(\d+)G\s+(\d+)G/',$disk,$dm); $disk_total=$dm[1]??0; $disk_used=$dm[2]??0; }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>XcodeHoster — VPS Panel</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0e1a;--bg2:#111827;--bg3:#1a2235;--bg4:#1e2d45;--border:rgba(99,179,237,0.12);--border2:rgba(99,179,237,0.25);--accent:#38bdf8;--green:#34d399;--red:#f87171;--yellow:#fbbf24;--purple:#a78bfa;--text:#e2e8f0;--text2:#94a3b8;--text3:#64748b;--mono:'JetBrains Mono',monospace;--sans:'Sora',sans-serif;--r:10px;--r2:14px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--sans);background:var(--bg);color:var(--text);min-height:100vh;display:flex;font-size:14px}
.login-wrap{min-height:100vh;width:100%;display:flex;align-items:center;justify-content:center}
.login-box{background:var(--bg2);border:1px solid var(--border2);border-radius:20px;padding:40px;width:100%;max-width:400px}
.login-logo{text-align:center;margin-bottom:32px}
.logo-icon{width:48px;height:48px;background:linear-gradient(135deg,var(--accent),var(--purple));border-radius:12px;display:inline-flex;align-items:center;justify-content:center;font-family:var(--mono);font-weight:700;font-size:18px;color:#0a0e1a;margin-bottom:12px}
.login-logo h1{font-size:22px;font-weight:700}
.login-logo p{font-size:12px;color:var(--text3);margin-top:4px;font-family:var(--mono)}
.err-msg{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);border-radius:8px;padding:10px 14px;font-size:12px;color:var(--red);margin-bottom:16px;text-align:center}
.sidebar{width:240px;min-height:100vh;background:var(--bg2);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;z-index:100}
.sidebar-logo{padding:20px;border-bottom:1px solid var(--border)}
.logo-mark{display:flex;align-items:center;gap:10px;text-decoration:none}
.logo-sm{width:34px;height:34px;background:linear-gradient(135deg,var(--accent),var(--purple));border-radius:8px;display:flex;align-items:center;justify-content:center;font-family:var(--mono);font-weight:700;font-size:13px;color:#0a0e1a}
.logo-text{font-size:15px;font-weight:700}
.logo-sub{font-size:10px;color:var(--text3);font-family:var(--mono)}
.sidebar-nav{padding:12px;flex:1;overflow-y:auto}
.nav-label{font-size:10px;font-weight:600;letter-spacing:.08em;color:var(--text3);text-transform:uppercase;padding:8px 10px 6px;margin-top:8px}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:var(--r);cursor:pointer;color:var(--text2);font-size:13.5px;transition:all .15s;text-decoration:none;position:relative;margin-bottom:2px;border:none;background:none;width:100%;text-align:left;font-family:var(--sans)}
.nav-item:hover{background:var(--bg3);color:var(--text)}
.nav-item.active{background:rgba(56,189,248,.1);color:var(--accent);font-weight:500}
.nav-item.active::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:20px;background:var(--accent);border-radius:0 3px 3px 0}
.nav-badge{margin-left:auto;background:var(--accent);color:#0a0e1a;font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;font-family:var(--mono)}
.sidebar-footer{padding:16px 12px;border-top:1px solid var(--border)}
.user-card{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--r);background:var(--bg3)}
.user-av{width:32px;height:32px;background:linear-gradient(135deg,var(--purple),var(--accent));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;color:#0a0e1a}
.main{margin-left:240px;flex:1;display:flex;flex-direction:column}
.topbar{height:60px;background:var(--bg2);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 28px;gap:16px;position:sticky;top:0;z-index:50}
.topbar-title{font-size:16px;font-weight:600;flex:1}
.page{display:none}.page.active{display:block}
.content{padding:28px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:var(--r);font-family:var(--sans);font-size:13px;font-weight:500;cursor:pointer;transition:all .15s;border:none}
.btn-primary{background:var(--accent);color:#0a0e1a}.btn-primary:hover{opacity:.9}
.btn-ghost{background:transparent;color:var(--text2);border:1px solid var(--border2)}.btn-ghost:hover{background:var(--bg3);color:var(--text)}
.btn-danger{background:rgba(248,113,113,.1);color:var(--red);border:1px solid rgba(248,113,113,.2)}.btn-danger:hover{background:rgba(248,113,113,.2)}
.btn-success{background:rgba(52,211,153,.1);color:var(--green);border:1px solid rgba(52,211,153,.2)}.btn-success:hover{background:rgba(52,211,153,.2)}
.btn-warning{background:rgba(251,191,36,.1);color:var(--yellow);border:1px solid rgba(251,191,36,.2)}
.btn-sm{padding:5px 10px;font-size:12px}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
.stat-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r2);padding:20px;position:relative;overflow:hidden}
.stat-card::after{content:'';position:absolute;top:0;right:0;width:80px;height:80px;border-radius:50%;opacity:.06;transform:translate(20px,-20px)}
.stat-card.blue::after{background:var(--accent)}.stat-card.green::after{background:var(--green)}.stat-card.yellow::after{background:var(--yellow)}.stat-card.purple::after{background:var(--purple)}
.stat-label{font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:8px}
.stat-value{font-size:32px;font-weight:700;font-family:var(--mono);line-height:1;margin-bottom:6px}
.stat-value.green{color:var(--green)}.stat-value.yellow{color:var(--yellow)}.stat-value.purple{color:var(--purple)}
.stat-change{font-size:12px;color:var(--text3)}
.gauge-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
.gauge-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r2);padding:24px;display:flex;flex-direction:column;align-items:center}
.gauge-wrap{position:relative;width:100px;height:100px;margin-bottom:16px}
.gauge-wrap svg{transform:rotate(-90deg)}
.gauge-bg{fill:none;stroke:var(--bg3);stroke-width:8}
.gauge-fill{fill:none;stroke-width:8;stroke-linecap:round;transition:stroke-dashoffset .8s ease}
.gauge-text{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-family:var(--mono);font-size:18px;font-weight:600}
.gauge-label{font-size:13px;font-weight:600;margin-bottom:4px}
.gauge-sub{font-size:12px;color:var(--text3)}
.dash-grid{display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:24px}
.table-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r2);overflow:hidden;margin-bottom:24px}
.table-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
.table-title{font-size:14px;font-weight:600;flex:1}
.search-wrap{position:relative}
.search-input{background:var(--bg3);border:1px solid var(--border);border-radius:var(--r);padding:7px 12px 7px 30px;color:var(--text);font-family:var(--sans);font-size:13px;width:200px;outline:none}
.search-input:focus{border-color:var(--border2)}
.si{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text3);font-size:13px}
table{width:100%;border-collapse:collapse}
thead th{font-size:11px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--text3);padding:10px 20px;text-align:left;background:var(--bg3);border-bottom:1px solid var(--border)}
tbody tr{border-bottom:1px solid var(--border);transition:background .1s;cursor:pointer}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:var(--bg3)}
tbody td{padding:12px 20px;font-size:13.5px;vertical-align:middle}
.mono{font-family:var(--mono);font-size:12px}
.text-accent{color:var(--accent)}.text-muted{color:var(--text3)}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;font-family:var(--mono)}
.badge-running{background:rgba(52,211,153,.12);color:var(--green);border:1px solid rgba(52,211,153,.2)}
.badge-stopped{background:rgba(248,113,113,.12);color:var(--red);border:1px solid rgba(248,113,113,.2)}
.badge-pending{background:rgba(251,191,36,.12);color:var(--yellow);border:1px solid rgba(251,191,36,.2)}
.badge-dot{width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block}
.progress-bar{height:5px;background:var(--bg3);border-radius:99px;overflow:hidden;width:80px}
.progress-fill{height:100%;border-radius:99px}
.fill-green{background:var(--green)}.fill-yellow{background:var(--yellow)}.fill-red{background:var(--red)}
.activity-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r2);overflow:hidden}
.activity-item{display:flex;gap:12px;padding:12px 18px;border-bottom:1px solid var(--border);align-items:flex-start}
.activity-item:last-child{border-bottom:none}
.act-dot{width:8px;height:8px;border-radius:50%;margin-top:4px;flex-shrink:0}
.act-create{background:var(--green)}.act-delete{background:var(--red)}.act-stop{background:var(--yellow)}.act-start{background:var(--green)}.act-restart{background:var(--accent)}.act-dns{background:var(--purple)}.act-voucher{background:var(--yellow)}.act-renew{background:var(--accent)}.act-info{background:var(--text3)}
.act-text{font-size:13px;color:var(--text2);line-height:1.5;flex:1}
.act-time{font-size:11px;color:var(--text3);font-family:var(--mono);white-space:nowrap}
.terminal{background:#060d16;border:1px solid var(--border);border-radius:var(--r2);overflow:hidden;margin-bottom:24px}
.term-header{background:var(--bg3);padding:10px 16px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border)}
.term-dot{width:11px;height:11px;border-radius:50%}
.td-r{background:#ff5f57}.td-y{background:#febc2e}.td-g{background:#28c840}
.term-title{font-family:var(--mono);font-size:12px;color:var(--text3);margin-left:6px}
.term-body{padding:16px;font-family:var(--mono);font-size:12.5px;color:#8fa3bf;min-height:180px;max-height:350px;overflow-y:auto;line-height:1.7}
.term-line{display:flex;gap:8px}
.term-prompt{color:var(--accent);flex-shrink:0}
.term-cmd{color:#c9d5e3}.term-out{color:var(--text3)}.term-success{color:var(--green)}.term-error{color:var(--red)}
.term-input-wrap{display:flex;align-items:center;gap:8px;padding:10px 16px;border-top:1px solid var(--border);background:var(--bg3)}
.term-input{flex:1;background:transparent;border:none;outline:none;font-family:var(--mono);font-size:12.5px;color:var(--text)}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);z-index:1000;align-items:flex-start;justify-content:center;overflow-y:auto;padding:40px 20px}
.modal-overlay.open{display:flex}
.modal{background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r2);padding:28px;width:480px;max-width:95vw;animation:mIn .2s ease;max-height:90vh;overflow-y:auto}
.modal-lg{width:580px}
@keyframes mIn{from{opacity:0;transform:scale(.96) translateY(-10px)}to{opacity:1;transform:none}}
.modal-title{font-size:16px;font-weight:600;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between}
.modal-close{width:28px;height:28px;border-radius:6px;background:var(--bg3);border:none;color:var(--text3);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center}
.modal-close:hover{color:var(--text)}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:22px;padding-top:18px;border-top:1px solid var(--border)}
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:12px;font-weight:500;color:var(--text3);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em}
.form-input{width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:var(--r);padding:9px 12px;color:var(--text);font-family:var(--sans);font-size:13.5px;outline:none;transition:border-color .15s}
.form-input:focus{border-color:var(--accent)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.voucher-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px;padding:16px}
.voucher-item{background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:10px;font-family:var(--mono);font-size:12px;text-align:center;cursor:pointer;transition:all .15s;letter-spacing:.05em}
.voucher-item:hover{background:rgba(56,189,248,.1);border-color:var(--accent);color:var(--accent)}
.info-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r2);padding:20px;margin-bottom:16px}
.info-card-title{font-size:13px;font-weight:600;color:var(--text2);margin-bottom:14px;display:flex;align-items:center;gap:8px}
.info-row{display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px}
.info-row:last-child{border-bottom:none}
.info-key{color:var(--text3)}
.info-val{color:var(--text);font-family:var(--mono);font-size:12px;display:flex;align-items:center;gap:6px}
.copy-btn{background:none;border:none;color:var(--text3);cursor:pointer;padding:2px 4px;border-radius:4px;transition:color .15s}
.copy-btn:hover{color:var(--accent)}
.expired-ok{color:var(--green)}.expired-warn{color:var(--yellow)}.expired-danger{color:var(--red)}
.toast{position:fixed;bottom:24px;right:24px;background:var(--bg2);border:1px solid var(--border2);border-radius:10px;padding:12px 20px;font-size:13px;z-index:9999;animation:tIn .3s ease;box-shadow:0 8px 24px rgba(0,0,0,.3)}
.toast.hide{animation:tOut .3s ease forwards}
@keyframes tIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
@keyframes tOut{to{opacity:0;transform:translateY(16px)}}
.dns-table td,.dns-table th{padding:8px 12px;font-size:12px}
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
.section-title{font-size:18px;font-weight:700}
</style>
</head>
<body>
<?php if (!$is_logged_in): ?>
<div class="login-wrap">
  <div class="login-box">
    <div class="login-logo">
      <div class="logo-icon">XC</div>
      <h1>XcodeHoster VPS</h1>
      <p>Admin Panel — Docker Manager</p>
    </div>
    <?php if ($login_error): ?><div class="err-msg">⚠️ <?= htmlspecialchars($login_error) ?></div><?php endif; ?>
    <form method="POST">
      <div class="form-group"><label class="form-label">Username</label><input type="text" name="username" class="form-input" placeholder="admin" required></div>
      <div class="form-group"><label class="form-label">Password</label><input type="password" name="password" class="form-input" placeholder="••••••••" required></div>
      <button type="submit" name="login" class="btn btn-primary" style="width:100%;justify-content:center;padding:11px">🔐 Masuk ke Panel</button>
    </form>
  </div>
</div>
<?php else: ?>
<nav class="sidebar">
  <div class="sidebar-logo">
    <a class="logo-mark" href="#"><div class="logo-sm">XC</div><div><div class="logo-text">XcodeHoster</div><div class="logo-sub">VPS PANEL v2.0</div></div></a>
  </div>
  <div class="sidebar-nav">
    <div class="nav-label">Utama</div>
    <button class="nav-item active" onclick="showPage('dashboard',this)">📊 Dashboard</button>
    <button class="nav-item" onclick="showPage('servers',this)">🖥️ VPS Containers <span class="nav-badge" id="nav-vps"><?= count($containers) ?></span></button>
    <button class="nav-item" onclick="showPage('users',this)">👥 Users <span class="nav-badge" id="nav-users"><?= count($users) ?></span></button>
    <button class="nav-item" onclick="showPage('billing',this)">💳 Billing</button>
    <div class="nav-label">Server</div>
    <button class="nav-item" onclick="showPage('dns',this)">🌐 DNS Manager</button>
    <button class="nav-item" onclick="showPage('terminal',this)">⌨️ Terminal</button>
    <div class="nav-label">Sistem</div>
    <button class="nav-item" onclick="showPage('voucher',this)">🎟️ Voucher <span class="nav-badge"><?= count($vouchers) ?></span></button>
    <button class="nav-item" onclick="showPage('settings',this)">⚙️ Pengaturan</button>
  </div>
  <div class="sidebar-footer">
    <div class="user-card">
      <div class="user-av">A</div>
      <div><div style="font-size:13px;font-weight:500">Administrator</div><div style="font-size:11px;color:var(--text3)">Super Admin</div></div>
      <a href="?logout=1" style="margin-left:auto;color:var(--text3);font-size:18px;text-decoration:none" title="Logout">⏻</a>
    </div>
  </div>
</nav>
<div class="main">
  <div class="topbar">
    <div class="topbar-title" id="page-title">Dashboard</div>
    <span class="mono text-muted" style="font-size:11px"><?= date('d M Y, H:i') ?> WIB</span>
    <span class="mono" style="font-size:11px;color:var(--green)">● <?= $server_ip ?></span>
    <a href="?logout=1" class="btn btn-ghost btn-sm">Logout</a>
  </div>

  <!-- DASHBOARD -->
  <div class="page active content" id="page-dashboard">
    <div class="stats-grid">
      <div class="stat-card blue"><div class="stat-label">Total VPS</div><div class="stat-value"><?= count($containers) ?></div><div class="stat-change">▲ Container terdaftar</div></div>
      <div class="stat-card green"><div class="stat-label">Running</div><div class="stat-value green"><?= $running_count ?></div><div class="stat-change"><?= $stopped_count ?> stopped</div></div>
      <div class="stat-card yellow"><div class="stat-label">Total Users</div><div class="stat-value yellow"><?= count($users) ?></div><div class="stat-change">▲ <?= count(array_filter($users,fn($u)=>$u['days_left']<=7&&$u['days_left']>0)) ?> hampir expired</div></div>
      <div class="stat-card purple"><div class="stat-label">Voucher Aktif</div><div class="stat-value purple"><?= count($vouchers) ?></div><div class="stat-change">Tersisa</div></div>
    </div>
    <div class="gauge-grid">
      <div class="gauge-card">
        <div class="gauge-wrap">
          <svg width="100" height="100" viewBox="0 0 100 100">
            <circle class="gauge-bg" cx="50" cy="50" r="40"/>
            <circle class="gauge-fill" id="g-cpu" cx="50" cy="50" r="40" stroke="<?= $cpu_usage>80?'#f87171':($cpu_usage>60?'#fbbf24':'#38bdf8') ?>" stroke-dasharray="251.2" stroke-dashoffset="<?= 251.2-(251.2*$cpu_usage/100) ?>"/>
          </svg>
          <div class="gauge-text" style="color:<?= $cpu_usage>80?'var(--red)':($cpu_usage>60?'var(--yellow)':'var(--accent)') ?>"><?= $cpu_usage ?>%</div>
        </div>
        <div class="gauge-label">CPU Usage</div>
        <div class="gauge-sub"><?= round($cpu_usage/100*shell_exec('nproc'),1) ?> / <?= trim(shell_exec('nproc')) ?> core</div>
      </div>
      <div class="gauge-card">
        <?php $ram_pct=$ram_total>0?round($ram_used/$ram_total*100):0; ?>
        <div class="gauge-wrap">
          <svg width="100" height="100" viewBox="0 0 100 100">
            <circle class="gauge-bg" cx="50" cy="50" r="40"/>
            <circle class="gauge-fill" cx="50" cy="50" r="40" stroke="var(--purple)" stroke-dasharray="251.2" stroke-dashoffset="<?= 251.2-(251.2*$ram_pct/100) ?>"/>
          </svg>
          <div class="gauge-text" style="color:var(--purple)"><?= $ram_pct ?>%</div>
        </div>
        <div class="gauge-label">RAM Usage</div>
        <div class="gauge-sub"><?= round($ram_used/1024,1) ?> / <?= round($ram_total/1024,1) ?> GB</div>
      </div>
      <div class="gauge-card">
        <?php $disk_pct=$disk_total>0?round($disk_used/$disk_total*100):0; ?>
        <div class="gauge-wrap">
          <svg width="100" height="100" viewBox="0 0 100 100">
            <circle class="gauge-bg" cx="50" cy="50" r="40"/>
            <circle class="gauge-fill" cx="50" cy="50" r="40" stroke="var(--green)" stroke-dasharray="251.2" stroke-dashoffset="<?= 251.2-(251.2*$disk_pct/100) ?>"/>
          </svg>
          <div class="gauge-text" style="color:var(--green)"><?= $disk_pct ?>%</div>
        </div>
        <div class="gauge-label">Storage</div>
        <div class="gauge-sub"><?= $disk_used ?> / <?= $disk_total ?> GB</div>
      </div>
    </div>
    <div class="dash-grid">
      <div class="table-card">
        <div class="table-header"><span class="table-title">🖥️ VPS Terbaru</span><button class="btn btn-ghost btn-sm" onclick="showPage('servers',document.querySelector('[onclick*=servers]'))">Lihat Semua</button></div>
        <table><thead><tr><th>Container</th><th>User</th><th>SSH Port</th><th>Status</th></tr></thead><tbody>
        <?php foreach(array_slice($containers,0,5) as $c): ?>
        <tr onclick="showDetailModal('<?= $c['id'] ?>')">
          <td><span class="mono text-accent"><?= htmlspecialchars($c['id']) ?></span></td>
          <td><?= htmlspecialchars($c['user']) ?></td>
          <td><span class="mono"><?= $c['ssh'] ?></span></td>
          <td><span class="badge badge-<?= $c['status'] ?>"><span class="badge-dot"></span><?= ucfirst($c['status']) ?></span></td>
        </tr>
        <?php endforeach; if(empty($containers)): ?><tr><td colspan="4" style="text-align:center;padding:24px;color:var(--text3)">Belum ada container</td></tr><?php endif; ?>
        </tbody></table>
      </div>
      <div class="activity-card">
        <div class="table-header"><span class="table-title">📋 Log Aktivitas</span></div>
        <?php if(empty($logs)): ?>
        <div style="padding:24px;text-align:center;color:var(--text3);font-size:13px">Belum ada aktivitas</div>
        <?php else: foreach($logs as $log): ?>
        <div class="activity-item">
          <div class="act-dot act-<?= htmlspecialchars($log['type']) ?>"></div>
          <div style="flex:1"><div class="act-text"><?= htmlspecialchars($log['msg']) ?></div><div class="act-time"><?= htmlspecialchars($log['time']) ?></div></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- VPS CONTAINERS -->
  <div class="page content" id="page-servers">
    <div class="table-card">
      <div class="table-header">
        <span class="table-title">VPS Containers</span>
        <div class="search-wrap"><span class="si">🔍</span><input type="text" class="search-input" placeholder="Cari container..." oninput="filterVPS(this.value)"></div>
        <button class="btn btn-primary btn-sm" onclick="openModal('modal-create')">+ Buat VPS</button>
      </div>
      <table><thead><tr><th>Container ID</th><th>User</th><th>Domain</th><th>SSH Port</th><th>Web Port</th><th>Status</th><th>CPU</th><th>Aksi</th></tr></thead>
      <tbody id="vps-tbody">
      <?php foreach($containers as $c): $cpu=$c['cpu']; $cc=$cpu>80?'fill-red':($cpu>60?'fill-yellow':'fill-green'); ?>
      <tr onclick="showDetailModal('<?= $c['id'] ?>')">
        <td><span class="mono text-accent"><?= htmlspecialchars($c['id']) ?></span></td>
        <td><?= htmlspecialchars($c['user']) ?></td>
        <td><span class="mono" style="font-size:11px"><?= htmlspecialchars($c['user']) ?>.tugaspkl.my.id</span></td>
        <td><span class="mono"><?= $c['ssh'] ?></span></td>
        <td><span class="mono"><?= $c['web'] ?></span></td>
        <td><span class="badge badge-<?= $c['status'] ?>"><span class="badge-dot"></span><?= ucfirst($c['status']) ?></span></td>
        <td><div style="display:flex;align-items:center;gap:8px"><div class="progress-bar"><div class="progress-fill <?= $cc ?>" style="width:<?= $cpu ?>%"></div></div><span class="mono text-muted" style="font-size:11px"><?= $cpu ?>%</span></div></td>
        <td onclick="event.stopPropagation()"><div style="display:flex;gap:6px">
          <button class="btn btn-ghost btn-sm" onclick="showDetailModal('<?= $c['id'] ?>')">Detail</button>
          <?php if($c['status']==='running'): ?><button class="btn btn-danger btn-sm" onclick="actionVPS('stop','<?= $c['id'] ?>')">Stop</button>
          <?php else: ?><button class="btn btn-success btn-sm" onclick="actionVPS('start','<?= $c['id'] ?>')">Start</button><?php endif; ?>
        </div></td>
      </tr>
      <?php endforeach; if(empty($containers)): ?><tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text3)">Belum ada container. Klik "+ Buat VPS".</td></tr><?php endif; ?>
      </tbody></table>
    </div>
  </div>

  <!-- USERS -->
  <div class="page content" id="page-users">
    <div class="table-card">
      <div class="table-header"><span class="table-title">Manajemen User (<?= count($users) ?>)</span></div>
      <table><thead><tr><th>Username</th><th>Email</th><th>WhatsApp</th><th>Paket</th><th>Expired</th><th>Sisa Hari</th><th>Aksi</th></tr></thead><tbody>
      <?php foreach($users as $u):
        $dl=$u['days_left']; $ec=$dl>7?'expired-ok':($dl>0?'expired-warn':'expired-danger');
      ?>
      <tr>
        <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
        <td class="mono" style="font-size:12px"><?= htmlspecialchars($u['email']) ?></td>
        <td class="mono"><?= htmlspecialchars($u['wa']) ?></td>
        <td><span class="badge badge-pending"><?= htmlspecialchars($u['paket']) ?></span></td>
        <td class="mono"><?= htmlspecialchars($u['expired']) ?></td>
        <td><span class="<?= $ec ?>" style="font-weight:600;font-family:var(--mono)"><?= $dl ?>h</span></td>
        <td><div style="display:flex;gap:6px">
          <button class="btn btn-ghost btn-sm" onclick="showUserDetail(this)"
            data-email="<?= htmlspecialchars($u['email']) ?>"
            data-username="<?= htmlspecialchars($u['username']) ?>"
            data-wa="<?= htmlspecialchars($u['wa']) ?>"
            data-paket="<?= htmlspecialchars($u['paket']) ?>"
            data-expired="<?= htmlspecialchars($u['expired']) ?>"
            data-date="<?= htmlspecialchars($u['date']) ?>"
            data-container="<?= htmlspecialchars($u['container']) ?>"
            data-password="<?= htmlspecialchars($u['password']) ?>">Detail</button>

        </div></td>
      </tr>
      <?php endforeach; if(empty($users)): ?><tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text3)">Belum ada user.</td></tr><?php endif; ?>
      </tbody></table>
    </div>
  </div>

  <!-- BILLING -->
  <div class="page content" id="page-billing">
    <div class="section-header"><div class="section-title">💳 Billing & Langganan</div></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px">
      <div class="stat-card green"><div class="stat-label">User Aktif</div><div class="stat-value green"><?= count(array_filter($users,fn($u)=>$u['days_left']>0)) ?></div><div class="stat-change">Langganan berjalan</div></div>
      <div class="stat-card red" style="--red:#f87171"><div class="stat-label">Expired / Hampir Expired</div><div class="stat-value" style="color:var(--red)"><?= count(array_filter($users,fn($u)=>$u['days_left']<=7)) ?></div><div class="stat-change">Perlu perhatian</div></div>
    </div>
    <div class="table-card">
      <div class="table-header"><span class="table-title">Status Billing User</span></div>
      <table><thead><tr><th>Username</th><th>Email</th><th>Paket</th><th>Expired</th><th>Sisa Hari</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
      <?php foreach($users as $u): $dl=$u['days_left']; ?>
      <tr>
        <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
        <td class="mono" style="font-size:12px"><?= htmlspecialchars($u['email']) ?></td>
        <td><?= htmlspecialchars($u['paket']) ?></td>
        <td class="mono"><?= htmlspecialchars($u['expired']) ?></td>
        <td><span style="font-family:var(--mono);font-weight:600;color:<?= $dl>7?'var(--green)':($dl>0?'var(--yellow)':'var(--red)') ?>"><?= $dl ?>h</span></td>
        <td><span class="badge <?= $dl>7?'badge-running':($dl>0?'badge-pending':'badge-stopped') ?>"><span class="badge-dot"></span><?= $dl>7?'Aktif':($dl>0?'Hampir Expired':'Expired') ?></span></td>
        <td><button class="btn btn-warning btn-sm" onclick="openRenew('<?= htmlspecialchars($u['email']) ?>','<?= htmlspecialchars($u['username']) ?>')">Perpanjang</button></td>
      </tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
  </div>

  <!-- DNS MANAGER -->
  <div class="page content" id="page-dns">
    <div class="section-header"><div class="section-title">🌐 DNS Manager</div><button class="btn btn-primary btn-sm" onclick="openModal('modal-dns-add')">+ Tambah DNS</button></div>
    <div class="table-card">
      <div class="table-header"><span class="table-title">DNS Records — <?= CF_DOMAIN ?></span><button class="btn btn-ghost btn-sm" onclick="loadDNS()">↻ Refresh</button></div>
      <table class="dns-table"><thead><tr><th>Type</th><th>Name</th><th>Content</th><th>TTL</th><th>Proxied</th><th>Aksi</th></tr></thead>
      <tbody id="dns-tbody"><tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text3)">Klik Refresh untuk memuat DNS records</td></tr></tbody>
      </table>
    </div>
  </div>

  <!-- TERMINAL -->
  <div class="page content" id="page-terminal">
    <div class="terminal">
      <div class="term-header"><div class="term-dot td-r"></div><div class="term-dot td-y"></div><div class="term-dot td-g"></div><span class="term-title">Docker Terminal</span><button class="btn btn-ghost btn-sm" style="margin-left:auto" onclick="clearTerm()">Clear</button></div>
      <div class="term-body" id="term-output">
        <div class="term-line"><span class="term-prompt">root@xcode:~#</span><span class="term-cmd"> XcodeHoster VPS Panel</span></div>
        <div class="term-success">✓ Terhubung ke <?= $server_ip ?></div><br>
      </div>
      <div class="term-input-wrap">
        <span class="term-prompt">root@xcode:~#</span>
        <input type="text" class="term-input" id="term-input" placeholder="docker ps -a" onkeydown="handleTermKey(event)">
        <button class="btn btn-primary btn-sm" onclick="runTermCmd()">▶ Run</button>
      </div>
    </div>
    <div class="info-card"><div class="info-card-title">🚀 Perintah Cepat</div>
      <div style="display:flex;flex-wrap:wrap;gap:8px;padding-top:8px">
        <button class="btn btn-ghost btn-sm" onclick="setCmd('docker ps -a')">docker ps -a</button>
        <button class="btn btn-ghost btn-sm" onclick="setCmd('docker images')">docker images</button>
        <button class="btn btn-ghost btn-sm" onclick="setCmd('docker stats --no-stream')">docker stats</button>
        <button class="btn btn-ghost btn-sm" onclick="setCmd('docker system df')">docker system df</button>
        <button class="btn btn-ghost btn-sm" onclick="setCmd('docker system prune -f')">docker system prune</button>
      </div>
    </div>
  </div>

  <!-- VOUCHER -->
  <div class="page content" id="page-voucher">
    <div class="table-card">
      <div class="table-header"><span class="table-title">🎟️ Voucher Aktivasi (<?= count($vouchers) ?> tersisa)</span>
        <button class="btn btn-ghost btn-sm" onclick="exportVouchers()">⬇ Export</button>
        <button class="btn btn-primary btn-sm" onclick="openModal('modal-genvoucher')">+ Generate</button>
      </div>
      <div class="voucher-grid" id="voucher-grid">
        <?php foreach($vouchers as $v): ?>
        <div class="voucher-item" onclick="copyText('<?= htmlspecialchars($v) ?>')" title="Klik untuk salin"><?= htmlspecialchars($v) ?></div>
        <?php endforeach; if(empty($vouchers)): ?>
        <div style="grid-column:1/-1;text-align:center;padding:32px;color:var(--text3)">Belum ada voucher. Klik Generate.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- SETTINGS -->
  <div class="page content" id="page-settings">
    <div class="section-header"><div class="section-title">⚙️ Pengaturan</div></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
      <div class="info-card">
        <div class="info-card-title">📋 Info Server</div>
        <div class="info-row"><span class="info-key">Server IP</span><span class="info-val"><?= $server_ip ?> <button class="copy-btn" onclick="copyText('<?= $server_ip ?>')">⧉</button></span></div>
        <div class="info-row"><span class="info-key">Port SSH Berikutnya</span><span class="info-val"><?= getCurrentPort() ?></span></div>
        <div class="info-row"><span class="info-key">Port Web Berikutnya</span><span class="info-val"><?= getCurrentWebPort() ?></span></div>
        <div class="info-row"><span class="info-key">PHP Version</span><span class="info-val"><?= PHP_VERSION ?></span></div>
        <div class="info-row"><span class="info-key">Server Time</span><span class="info-val"><?= date('d-m-Y H:i:s') ?></span></div>
        <div class="info-row"><span class="info-key">CF Domain</span><span class="info-val"><?= CF_DOMAIN ?></span></div>
      </div>
      <div class="info-card">
        <div class="info-card-title">📱 WhatsApp (Fonnte)</div>
        <div class="form-group"><label class="form-label">Token Fonnte</label><input type="text" class="form-input" id="set-watoken" value="<?= htmlspecialchars($wa_conf['token']) ?>" placeholder="token_fonnte_kamu"></div>
        <div class="form-group"><label class="form-label">Nomor WA Pengirim</label><input type="text" class="form-input" id="set-wasender" value="<?= htmlspecialchars($wa_conf['sender']) ?>" placeholder="628xxxxxxxxxx"></div>
        <button class="btn btn-primary btn-sm" onclick="saveSettings()">💾 Simpan</button>
      </div>
      <div class="info-card">
        <div class="info-card-title">📖 Panduan Docker</div>
        <div class="info-row"><span class="info-key">Build Image</span><span class="info-val mono">docker build -t xcodedata .</span></div>
        <div class="info-row"><span class="info-key">Lihat Container</span><span class="info-val mono">docker ps -a</span></div>
        <div class="info-row"><span class="info-key">Stop Container</span><span class="info-val mono">docker stop server12100</span></div>
        <div class="info-row"><span class="info-key">SSH ke Container</span><span class="info-val mono">ssh root@<?= $server_ip ?> -p [PORT]</span></div>
      </div>
      <div class="info-card">
        <div class="info-card-title">🔑 Ganti Password Admin</div>
        <div class="form-group"><label class="form-label">Password Baru</label><input type="password" class="form-input" id="set-newpass" placeholder="password baru"></div>
        <p style="font-size:12px;color:var(--text3);margin-bottom:12px">Edit langsung di index.php baris ADMIN_PASS setelah ganti.</p>
        <button class="btn btn-ghost btn-sm" onclick="copyText(document.getElementById('set-newpass').value||'')">Copy Password</button>
      </div>
    </div>
  </div>

</div><!-- end .main -->
<!-- MODAL: DETAIL VPS -->
<div class="modal-overlay" id="modal-detail">
  <div class="modal">
    <div class="modal-title"><span id="detail-title">Detail VPS</span><button class="modal-close" onclick="closeModal('modal-detail')">×</button></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
      <div><div class="form-label">Container</div><div class="mono text-accent" id="d-container">-</div></div>
      <div><div class="form-label">Status</div><div id="d-status"><span class="badge badge-pending"><span class="badge-dot"></span>Loading...</span></div></div>
    </div>
    <div style="border:1px solid var(--border);border-radius:var(--r);overflow:hidden;margin-bottom:16px">
      <div class="info-row" style="padding:9px 14px"><span class="info-key">SSH Host</span><span class="info-val" id="d-host">-</span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">SSH Port</span><span class="info-val" id="d-port">-</span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">Web URL</span><span class="info-val"><a id="d-web" href="#" target="_blank" style="color:var(--accent);text-decoration:none">-</a></span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">Username SSH</span><span class="info-val">root</span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">Password</span><span class="info-val"><span id="d-pass">•••••••</span> <button class="copy-btn" onclick="togglePass()">👁</button></span></div>
      <div class="info-row" style="padding:9px 14px;border-bottom:none"><span class="info-key">Dibuat</span><span class="info-val" id="d-date">-</span></div>
    </div>
    <div class="terminal" style="margin-bottom:16px">
      <div class="term-header"><div class="term-dot td-r"></div><div class="term-dot td-y"></div><div class="term-dot td-g"></div><span class="term-title">SSH Command</span></div>
      <div class="term-body" style="min-height:44px"><div class="term-line"><span class="term-prompt">$</span><span class="term-cmd" id="d-sshcmd"> -</span></div></div>
    </div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-success btn-sm" onclick="actionCurrentVPS('start')">Start</button>
      <button class="btn btn-ghost btn-sm" onclick="actionCurrentVPS('restart')">Restart</button>
      <button class="btn btn-ghost btn-sm" onclick="actionCurrentVPS('stop')">Stop</button>
      <button class="btn btn-danger btn-sm" style="margin-left:auto" onclick="confirmDeleteVPS()">🗑 Hapus</button>
    </div>
  </div>
</div>

<!-- MODAL: CREATE VPS -->
<div class="modal-overlay" id="modal-create">
  <div class="modal">
    <div class="modal-title">🖥️ Buat VPS Baru<button class="modal-close" onclick="closeModal('modal-create')">×</button></div>
    <div class="form-group"><label class="form-label">Username (huruf kecil & angka)</label><input type="text" class="form-input" id="new-username" placeholder="useranda" oninput="updatePreview()"></div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Password</label><input type="text" class="form-input" id="new-password" placeholder="password123" oninput="updatePreview()"></div>
      <div class="form-group"><label class="form-label">Email</label><input type="email" class="form-input" id="new-email" placeholder="user@email.com"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">WhatsApp</label><input type="text" class="form-input" id="new-wa" placeholder="08123456789"></div>
      <div class="form-group"><label class="form-label">Paket</label>
        <select class="form-input" id="new-paket">
          <option value="bulanan">Bulanan (30 hari)</option>
          <option value="tahunan">Tahunan (365 hari)</option>
        </select>
      </div>
    </div>
    <div class="form-group"><label class="form-label">Kode Voucher</label><input type="text" class="form-input" id="new-voucher" placeholder="ABCD1234" style="text-transform:uppercase"></div>
    <div class="terminal" style="margin-bottom:0">
      <div class="term-header"><div class="term-dot td-r"></div><div class="term-dot td-y"></div><div class="term-dot td-g"></div><span class="term-title">Preview Command</span></div>
      <div class="term-body" id="preview-cmd" style="min-height:50px"><div class="term-line"><span class="term-prompt">root@xcode:~#</span><span class="term-cmd"> Isi form untuk preview...</span></div></div>
    </div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-create')">Batal</button>
      <button class="btn btn-primary" onclick="createVPS()">🚀 Buat VPS</button>
    </div>
  </div>
</div>

<!-- MODAL: DETAIL USER -->
<div class="modal-overlay" id="modal-user-detail">
  <div class="modal">
    <div class="modal-title"><span id="ud-title">Detail User</span><button class="modal-close" onclick="closeModal('modal-user-detail')">×</button></div>
    <div style="border:1px solid var(--border);border-radius:var(--r);overflow:hidden;margin-bottom:16px">
      <div class="info-row" style="padding:9px 14px"><span class="info-key">Username</span><span class="info-val" id="ud-username">-</span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">Email</span><span class="info-val" id="ud-email">-</span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">WhatsApp</span><span class="info-val" id="ud-wa">-</span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">Paket</span><span class="info-val" id="ud-paket">-</span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">Expired</span><span class="info-val" id="ud-expired">-</span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">Tanggal Daftar</span><span class="info-val" id="ud-date">-</span></div>
      <div class="info-row" style="padding:9px 14px"><span class="info-key">Container</span><span class="info-val" id="ud-container">-</span></div>
      <div class="info-row" style="padding:9px 14px;border-bottom:none"><span class="info-key">Password VPS</span><span class="info-val"><span id="ud-pass">•••••••</span> <button class="copy-btn" onclick="toggleUserPass()">👁</button></span></div>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button class="btn btn-ghost" onclick="closeModal('modal-user-detail')">Tutup</button>
    </div>
  </div>
</div>

<!-- MODAL: PERPANJANG -->
<div class="modal-overlay" id="modal-renew">
  <div class="modal">
    <div class="modal-title"><span id="renew-title">Perpanjang VPS</span><button class="modal-close" onclick="closeModal('modal-renew')">×</button></div>
    <input type="hidden" id="renew-email">
    <div class="form-group"><label class="form-label">Paket Perpanjangan</label>
      <select class="form-input" id="renew-paket">
        <option value="bulanan">Bulanan (+30 hari)</option>
        <option value="tahunan">Tahunan (+365 hari)</option>
      </select>
    </div>
    <div class="form-group"><label class="form-label">Kode Voucher</label><input type="text" class="form-input" id="renew-voucher" placeholder="ABCD1234" style="text-transform:uppercase"></div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-renew')">Batal</button>
      <button class="btn btn-primary" onclick="renewVPS()">✅ Perpanjang</button>
    </div>
  </div>
</div>

<!-- MODAL: GENERATE VOUCHER -->
<div class="modal-overlay" id="modal-genvoucher">
  <div class="modal">
    <div class="modal-title">🎟️ Generate Voucher<button class="modal-close" onclick="closeModal('modal-genvoucher')">×</button></div>
    <div class="form-group"><label class="form-label">Jumlah Voucher</label><input type="number" class="form-input" id="voucher-count" value="100" min="10" max="1000"></div>
    <p style="font-size:12px;color:var(--text3)">⚠️ Voucher lama akan diganti dengan yang baru.</p>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-genvoucher')">Batal</button>
      <button class="btn btn-primary" onclick="generateVouchers()">Generate</button>
    </div>
  </div>
</div>

<!-- MODAL: ADD DNS -->
<div class="modal-overlay" id="modal-dns-add">
  <div class="modal">
    <div class="modal-title">🌐 Tambah DNS Record<button class="modal-close" onclick="closeModal('modal-dns-add')">×</button></div>
    <div class="form-group"><label class="form-label">Subdomain</label>
      <div style="display:flex;align-items:center;gap:8px">
        <input type="text" class="form-input" id="dns-name" placeholder="namauser" style="flex:1">
        <span style="color:var(--text3);font-family:var(--mono);font-size:13px">.<?= CF_DOMAIN ?></span>
      </div>
    </div>
    <div class="form-group"><label class="form-label">IP Address</label><input type="text" class="form-input" id="dns-ip" value="<?= $server_ip ?>" placeholder="103.x.x.x"></div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-dns-add')">Batal</button>
      <button class="btn btn-primary" onclick="addDNS()">+ Tambah</button>
    </div>
  </div>
</div>

<script>
const nextPort = <?= getCurrentPort() ?>;
let currentDetailId = null, currentDetailData = null, currentUserPass = '';

function showPage(name, el) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('page-' + name).classList.add('active');
  if (el) el.classList.add('active');
  const titles = {dashboard:'Dashboard',servers:'VPS Containers',users:'Manajemen User',billing:'Billing',dns:'DNS Manager',terminal:'Terminal',voucher:'Voucher',settings:'Pengaturan'};
  document.getElementById('page-title').textContent = titles[name] || name;
  if (name === 'dns') loadDNS();
}

function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m => m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); }));

function showToast(msg, type='info') {
  const t = document.createElement('div');
  t.className = 'toast';
  t.style.borderColor = type==='success'?'var(--green)':type==='error'?'var(--red)':'var(--border2)';
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => { t.classList.add('hide'); setTimeout(() => t.remove(), 300); }, 3000);
}

async function post(data) {
  const fd = new FormData();
  for (const k in data) fd.append(k, data[k]);
  const res = await fetch(location.pathname, { method: 'POST', body: fd });
  return res.json();
}

function updatePreview() {
  const user = document.getElementById('new-username').value || 'username';
  const pass = document.getElementById('new-password').value || 'password';
  document.getElementById('preview-cmd').innerHTML = `<div class="term-line"><span class="term-prompt">root@xcode:~#</span><span class="term-cmd"> docker run -d --name server${nextPort} -e ROOT_PASSWORD=${pass} -p ${nextPort}:22 -p ${nextPort+9000}:80 --restart=always xcodedata</span></div>`;
}

async function createVPS() {
  const user = document.getElementById('new-username').value;
  const pass = document.getElementById('new-password').value;
  const email = document.getElementById('new-email').value;
  const voucher = document.getElementById('new-voucher').value.toUpperCase();
  if (!user||!pass||!email||!voucher) { showToast('⚠ Lengkapi semua field termasuk voucher', 'error'); return; }
  showToast('⏳ Membuat VPS...');
  const res = await post({ action:'create_vps', username:user, password:pass, email, wa:document.getElementById('new-wa').value, paket:document.getElementById('new-paket').value, voucher });
  showToast(res.success?'✓ '+res.msg:'✗ '+res.msg, res.success?'success':'error');
  if (res.success) { closeModal('modal-create'); setTimeout(() => location.reload(), 2000); }
}

async function actionVPS(action, id) {
  showToast(`⏳ ${action} ${id}...`);
  const res = await post({ action: action+'_vps', id });
  showToast(res.success?'✓ '+res.msg:'✗ '+res.msg, res.success?'success':'error');
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

async function showDetailModal(id) {
  currentDetailId = id;
  document.getElementById('detail-title').textContent = 'Detail VPS — ' + id;
  document.getElementById('d-container').textContent = id;
  document.getElementById('d-status').innerHTML = '<span class="badge badge-pending"><span class="badge-dot"></span>Loading...</span>';
  openModal('modal-detail');
  const res = await post({ action:'get_detail', id });
  if (res.success) {
    currentDetailData = res;
    const bc = res.status==='running'?'badge-running':'badge-stopped';
    document.getElementById('d-status').innerHTML = `<span class="badge ${bc}"><span class="badge-dot"></span>${res.status}</span>`;
    document.getElementById('d-port').innerHTML = `${res.ssh_port} <button class="copy-btn" onclick="copyText('${res.ssh_port}')">⧉</button>`;
    const webEl = document.getElementById('d-web');
    if (res.web_port && res.web_port !== '-') {
      const udata = res.user_data;
      const username = udata ? udata[0].trim() : res.id.replace('server','');
      const webUrl = 'http://' + username + '.tugaspkl.my.id';
      webEl.href = webUrl;
      webEl.textContent = webUrl;
    } else {
      webEl.textContent = '-';
      webEl.removeAttribute('href');
    }
    document.getElementById('d-pass').textContent = '•••••••';
    document.getElementById('d-host').textContent = res.ip;
    document.getElementById('d-sshcmd').textContent = ` ssh root@${res.ip} -p ${res.ssh_port}`;
    document.getElementById('d-date').textContent = res.created;
  }
}

function togglePass() {
  const el = document.getElementById('d-pass');
  if (!currentDetailData) return;
  el.textContent = el.textContent.includes('•') ? currentDetailData.password : '•••••••';
}

function filterVPS(q) {
  document.querySelectorAll('#vps-tbody tr').forEach(tr => {
    tr.style.display = tr.textContent.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
  });
}

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
  document.getElementById('ud-pass').textContent = '•••••••';
  openModal('modal-user-detail');
}

function toggleUserPass() {
  const el = document.getElementById('ud-pass');
  el.textContent = el.textContent.includes('•') ? currentUserPass : '•••••••';
}

function openRenew(email, username) {
  document.getElementById('renew-email').value = email;
  document.getElementById('renew-title').textContent = 'Perpanjang VPS — ' + username;
  document.getElementById('renew-voucher').value = '';
  openModal('modal-renew');
}

async function renewVPS() {
  const email = document.getElementById('renew-email').value;
  const voucher = document.getElementById('renew-voucher').value.toUpperCase();
  const paket = document.getElementById('renew-paket').value;
  if (!voucher) { showToast('⚠ Masukkan kode voucher', 'error'); return; }
  showToast('⏳ Memproses perpanjangan...');
  const res = await post({ action:'renew_vps', email, voucher, paket });
  showToast(res.success?'✓ '+res.msg:'✗ '+res.msg, res.success?'success':'error');
  if (res.success) { closeModal('modal-renew'); setTimeout(() => location.reload(), 2000); }
}

function exportVouchers() {
  const codes = [...document.querySelectorAll('.voucher-item')].map(v => v.textContent.trim());
  const blob = new Blob([codes.join('\n')], { type: 'text/plain' });
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'vouchers.txt'; a.click();
  showToast('✓ vouchers.txt diunduh', 'success');
}

async function generateVouchers() {
  const count = document.getElementById('voucher-count').value;
  const res = await post({ action:'generate_vouchers', count });
  if (res.success) { showToast('✓ '+res.msg, 'success'); closeModal('modal-genvoucher'); setTimeout(() => location.reload(), 1500); }
}

async function loadDNS() {
  document.getElementById('dns-tbody').innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--text3)">⏳ Memuat...</td></tr>';
  const res = await post({ action:'cf_list' });
  if (res.success && res.records) {
    if (res.records.length === 0) { document.getElementById('dns-tbody').innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--text3)">Tidak ada DNS record</td></tr>'; return; }
    document.getElementById('dns-tbody').innerHTML = res.records.map(r => `<tr>
      <td><span class="badge badge-pending">${r.type}</span></td>
      <td class="mono" style="font-size:12px">${r.name}</td>
      <td class="mono" style="font-size:12px">${r.content}</td>
      <td class="mono">${r.ttl}</td>
      <td>${r.proxied ? '🟠 Yes' : '⚪ No'}</td>
      <td><button class="btn btn-danger btn-sm" onclick="deleteDNS('${r.id}','${r.name}')">Hapus</button></td>
    </tr>`).join('');
  } else { document.getElementById('dns-tbody').innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--red)">Gagal memuat DNS. Cek API Key Cloudflare.</td></tr>'; }
}

async function addDNS() {
  const name = document.getElementById('dns-name').value.trim();
  const ip = document.getElementById('dns-ip').value.trim();
  if (!name) { showToast('⚠ Masukkan nama subdomain', 'error'); return; }
  showToast('⏳ Menambah DNS...');
  const res = await post({ action:'cf_add', name, ip });
  showToast(res.success?'✓ '+res.msg:'✗ '+res.msg, res.success?'success':'error');
  if (res.success) { closeModal('modal-dns-add'); loadDNS(); }
}

async function deleteDNS(id, name) {
  if (!confirm(`Hapus DNS record ${name}?`)) return;
  const res = await post({ action:'cf_delete', record_id:id });
  showToast(res.success?'✓ DNS dihapus':'✗ Gagal hapus DNS', res.success?'success':'error');
  if (res.success) loadDNS();
}

const cmdHistory = []; let histIdx = 0;
function handleTermKey(e) {
  if (e.key==='Enter') runTermCmd();
  if (e.key==='ArrowUp'&&histIdx>0) { histIdx--; e.target.value=cmdHistory[histIdx]; }
  if (e.key==='ArrowDown'&&histIdx<cmdHistory.length-1) { histIdx++; e.target.value=cmdHistory[histIdx]; }
}

async function runTermCmd() {
  const input = document.getElementById('term-input');
  const cmd = input.value.trim();
  if (!cmd) return;
  cmdHistory.push(cmd); histIdx = cmdHistory.length;
  addTermLine(cmd, '⏳ Menjalankan...'); input.value = '';
  const res = await post({ action:'run_command', cmd });
  const term = document.getElementById('term-output');
  term.lastElementChild?.remove();
  addTermLine(cmd, res.success?(res.output||'(no output)'):(res.msg||'error'), res.success?'out':'error', false);
}

function addTermLine(cmd, out, type='out', showCmd=true) {
  const term = document.getElementById('term-output');
  if (showCmd) term.innerHTML += `<div class="term-line"><span class="term-prompt">root@xcode:~#</span><span class="term-cmd"> ${cmd}</span></div>`;
  if (out) out.split('\n').forEach(line => { if(line) term.innerHTML += `<div class="term-${type}">${line}</div>`; });
  term.innerHTML += '<br>'; term.scrollTop = term.scrollHeight;
}

function clearTerm() { document.getElementById('term-output').innerHTML = '<div class="term-line"><span class="term-prompt">root@xcode:~#</span><span class="term-cmd"> clear</span></div><br>'; }
function setCmd(cmd) { document.getElementById('term-input').value = cmd; showPage('terminal', document.querySelector('[onclick*=terminal]')); }

async function saveSettings() {
  const token = document.getElementById('set-watoken').value;
  const sender = document.getElementById('set-wasender').value;
  const res = await post({ action:'save_settings', wa_token:token, wa_sender:sender });
  showToast(res.success?'✓ '+res.msg:'✗ Gagal simpan', res.success?'success':'error');
}

function copyText(text) {
  navigator.clipboard.writeText(text).then(() => showToast('✓ Disalin: '+text, 'success')).catch(() => showToast('Gagal menyalin', 'error'));
}
</script>
<?php endif; ?>
</body>
</html>
