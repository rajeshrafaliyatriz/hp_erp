<?php
// Sprint 0 evidence. Prints the exact rows a write probe is about to touch, so
// the after-state can be compared and reversed. Read only.
$lines=file(__DIR__.'/../../../.env'); $e=[];
foreach($lines as $l){ $l=trim($l); if($l===""||$l[0]==="#")continue; $p=explode("=",$l,2); if(count($p)<2)continue; $e[trim($p[0])]=trim($p[1]," \"'"); }
$p=new PDO("mysql:host={$e['DB_HOST']};port={$e['DB_PORT']};dbname={$e['DB_DATABASE']}",$e['DB_USERNAME'],$e['DB_PASSWORD'],[PDO::MYSQL_ATTR_INIT_COMMAND=>"SET NAMES utf8mb4"]);
foreach ((array)($argv[1] ?? null) as $_) {}
$sql = $argv[1] ?? '';
if ($sql === '') { fwrite(STDERR, "usage: php snapshot.php \"<select>\"\n"); exit(1); }
foreach ($p->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), "\n";
}
