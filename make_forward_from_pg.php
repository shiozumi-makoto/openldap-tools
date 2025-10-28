#!/usr/bin/env php
<?php
/**
 * make_forward_from_pg.php
 * PostgreSQL → LDAP → /home 経由で .forward 自動生成
 *
 * 例：
 *   php make_forward_from_pg.php --uid 9-102           （DRY-RUN）
 *   php make_forward_from_pg.php --uid 9-102 --confirm （実行）
 *   php make_forward_from_pg.php --all --confirm       （全件）
 */

require_once __DIR__.'/Env.php';
require_once __DIR__.'/Connection.php';
require_once __DIR__.'/CliColor.php';
require_once __DIR__.'/LdapConnector.php';

use Tools\Env;
use Tools\Connection;
use Tools\CliColor as C;

// 引数処理
function arg_has($k){ foreach($GLOBALS['argv'] as $a){ if($a===$k) return true; } return false; }
function arg_val($k,$def=null){
    foreach($GLOBALS['argv'] as $i=>$a){
        if(strpos($a, $k.'=')===0){ return substr($a, strlen($k)+1); }
        if($a===$k && isset($GLOBALS['argv'][$i+1]) && $GLOBALS['argv'][$i+1][0] !== '-') return $GLOBALS['argv'][$i+1];
    }
    return $def;
}

$DO_ALL  = arg_has('--all');
$UID_ONE = arg_val('--uid', null);
$CONFIRM = arg_has('--confirm');
$DOMAIN  = arg_val('--domain', 'esmile-holdings.com');
$BASE_PEOPLE = 'ou=People,dc=e-smile,dc=ne,dc=jp';
$BASE_USERS  = 'ou=Users,dc=e-smile,dc=ne,dc=jp';

echo C::yellow("=== make_forward_from_pg (DRY-RUN=".($CONFIRM?'OFF':'ON').") ===\n");

// DB接続
$dsn = Env::str('PG_DSN', 'pgsql:host=127.0.0.1;dbname=esmile');
$user = Env::str('PG_USER', 'postgres');
$pass = Env::str('PG_PASS', '');
$pdo  = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

// LDAP接続
$ldap = new Tools\LdapConnector(['uri'=>'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi']);

// 取得SQL
$sql = <<<SQL
SELECT j.cmp_id, j.user_id, p.samba_id, p.login_id
FROM public."情報個人" AS j
JOIN public.passwd_tnas AS p
  ON j.cmp_id = p.cmp_id AND j.user_id = p.user_id
WHERE (p.user_id >= 100 OR p.user_id = 1)
SQL;
if(!$DO_ALL && $UID_ONE){
    if(preg_match('/^(\d+)-(\d+)$/',$UID_ONE,$m)){
        $sql .= " AND j.cmp_id={$m[1]} AND j.user_id={$m[2]}";
    } else {
        fwrite(STDERR,"UID形式が不正です: {$UID_ONE}\n"); exit(1);
    }
}
$sql .= " ORDER BY j.cmp_id, j.user_id";
[O
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if(!$rows){ echo "対象レコードなし\n"; exit(0); }

foreach($rows as $r){
    $cmp = (int)$r['cmp_id'];
    $uid = (int)$r['user_id'];
    $smb = trim($r['samba_id']);
    if($smb===''){ echo C::red("[SKIP] samba_idなし {$cmp}-{$uid}\n"); continue; }

    $home = sprintf("/home/%02d-%03d-%s", $cmp, $uid, $smb);
    if(!is_dir($home)){
        echo C::red("[ERROR] ホーム未存在: {$home}\n");
        continue;
    }

    // Peopleから旧メール取得
    $filter_people = "(uid={$cmp}-{$uid})";
    $mail_old = [];
    $res1 = $ldap->search($BASE_PEOPLE, $filter_people, ['mail']);
    if($res1){
        foreach($res1 as $ent){
            if(isset($ent['mail'])){
                foreach($ent['mail'] as $m){ if($m) $mail_old[]=$m; }
            }
        }
    }

    // Usersから新メール確認
    $filter_users = "(uid={$smb})";
    $mail_new = "{$smb}@{$DOMAIN}";
    $res2 = $ldap->search($BASE_USERS, $filter_users, ['mail']);
    if($res2 && isset($res2[0]['mail'][0])){
        $mail_new = $res2[0]['mail'][0];
    }

    $mail_old = array_unique(array_filter($mail_old));
    $forwards = array_merge(['\\'.$mail_new], $mail_old);
    $text = implode(', ', $forwards)."\n";
    $fwd = $home.'/.forward';

    echo C::cyan(sprintf("[PLAN] %02d-%03d %-20s → %s\n", $cmp, $uid, $smb, implode(', ',$forwards)));

    if($CONFIRM){
        if(file_put_contents($fwd,$text)===false){
            echo C::red("書込失敗: $fwd\n"); continue;
        }
        @chmod($fwd,0600);
        echo C::green("作成: $fwd\n");
    }
}

echo C::yellow("=== 完了 ===\n");


