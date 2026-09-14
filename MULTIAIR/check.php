<?php
// MULTIAIR — diagnostic de l'hébergement (à ouvrir une fois après dépôt, puis à supprimer)
header('Content-Type: text/html; charset=utf-8');
$checks = [];
$checks[] = ['Version PHP >= 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION];
$checks[] = ['Extension pdo_sqlite', extension_loaded('pdo_sqlite'), extension_loaded('pdo_sqlite') ? 'présente' : 'ABSENTE : basculer sur Supabase'];
$checks[] = ['Extension json', extension_loaded('json'), ''];
$checks[] = ['Extension mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? '' : 'absente (non bloquant)'];
$checks[] = ['Extension openssl (SMTP TLS)', extension_loaded('openssl'), ''];
$checks[] = ['Fonction mail()', function_exists('mail'), ''];
$checks[] = ['config.php présent', is_file(__DIR__ . '/config.php'), is_file(__DIR__ . '/config.php') ? '' : 'copier config.example.php en config.php'];
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}
$writable = is_dir($dataDir) && is_writable($dataDir);
$checks[] = ['Dossier data/ inscriptible', $writable, $writable ? realpath($dataDir) : 'chmod 755 ou 775 sur MULTIAIR/data'];
$dbOk = false;
$dbMsg = '';
if ($writable && extension_loaded('pdo_sqlite')) {
    try {
        $pdo = new PDO('sqlite:' . $dataDir . '/_test.sqlite');
        $pdo->exec('CREATE TABLE IF NOT EXISTS t(x)');
        $pdo->exec('INSERT INTO t VALUES (1)');
        $dbOk = (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn() >= 1;
        $pdo = null;
        @unlink($dataDir . '/_test.sqlite');
    } catch (Throwable $e) {
        $dbMsg = $e->getMessage();
    }
}
$checks[] = ['Écriture SQLite réelle', $dbOk, $dbMsg];
// .htaccess honoré ? On teste si data/.htaccess est servi (il ne devrait pas l'être)
$self = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME'] ?? '/');
$htOk = null;
if (function_exists('curl_init')) {
    $ch = curl_init($self . '/data/.htaccess');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $htOk = in_array($code, [403, 404], true);
    $checks[] = ['Protection data/ (.htaccess)', $htOk, "HTTP $code sur data/.htaccess (403 ou 404 attendu)"];
}
if (is_file(__DIR__ . '/config.php')) {
    $cfg = require __DIR__ . '/config.php';
    $checks[] = ['Mot de passe modifié', ($cfg['password'] ?? '') !== 'CHANGER_MOI', ''];
    $checks[] = ['Clé API définie', strlen((string) ($cfg['api_key'] ?? '')) >= 16, ''];
}
$all = array_reduce($checks, fn($c, $x) => $c && ($x[1] !== false), true);
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><title>MULTIAIR - Diagnostic</title>
<style>body{font-family:system-ui,sans-serif;max-width:720px;margin:40px auto;padding:0 16px;color:#1c2431}
table{border-collapse:collapse;width:100%}td,th{padding:8px 10px;border-bottom:1px solid #e3e7ee;text-align:left}
.ok{color:#137a3f;font-weight:600}.ko{color:#b3261e;font-weight:600}.warn{color:#8a5a00;font-weight:600}
.final{padding:14px;border-radius:8px;margin:18px 0;font-weight:600}
.final.ok{background:#e6f5ec}.final.ko{background:#fbe9e7}</style></head><body>
<h1>MULTIAIR — diagnostic</h1>
<table><tr><th>Contrôle</th><th>Résultat</th><th>Détail</th></tr>
<?php foreach ($checks as [$label, $ok, $detail]): ?>
<tr><td><?= htmlspecialchars($label) ?></td>
<td class="<?= $ok === null ? 'warn' : ($ok ? 'ok' : 'ko') ?>"><?= $ok === null ? 'inconnu' : ($ok ? 'OK' : 'KO') ?></td>
<td><?= htmlspecialchars((string) $detail) ?></td></tr>
<?php endforeach; ?>
</table>
<div class="final <?= $all ? 'ok' : 'ko' ?>"><?= $all ? 'Hébergement compatible : PHP + SQLite. Vous pouvez ouvrir index.php.' : 'Un ou plusieurs contrôles échouent : voir la colonne Détail.' ?></div>
<p>URL de l'API à utiliser dans Make : <code><?= htmlspecialchars($self) ?>/api.php?r=…</code></p>
<p><small>Supprimez ce fichier (check.php) une fois la vérification faite.</small></p>
</body></html>
