<?php
// MULTIAIR — import de l'historique (fichiers JSON déposés dans import/)
// Format d'un fichier : { "nom_table": [ {colonne: valeur, ...}, ... ], ... }
// Un fichier importé est renommé en .done pour ne pas être rejoué.
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (!ma_is_logged() && PHP_SAPI !== 'cli') {
    header('Location: index.php');
    exit;
}
header('Content-Type: text/html; charset=utf-8');
$db = ma_db();
$dir = __DIR__ . '/import';
$files = glob($dir . '/*.json') ?: [];
$rapport = [];

if ((PHP_SAPI === 'cli' || ($_POST['go'] ?? '') === '1') && $files) {
    foreach ($files as $file) {
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            $rapport[] = [basename($file), 'JSON invalide', 0];
            continue;
        }
        $db->beginTransaction();
        try {
            foreach ($data as $table => $rows) {
                if (!preg_match('/^[a-z_]+$/', (string) $table) || !is_array($rows)) {
                    continue;
                }
                $st = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
                $st->execute([$table]);
                if (!$st->fetchColumn()) {
                    $rapport[] = [basename($file), "table inconnue : $table", 0];
                    continue;
                }
                $colsT = array_column($db->query("PRAGMA table_info($table)")->fetchAll(), 'name');
                $n = 0;
                foreach ($rows as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    // Normalisations spécifiques
                    if ($table === 'rep_fiches') {
                        $r['tel_norm'] = ma_tel((string) ($r['tel_norm'] ?? ''));
                        $r['created_at'] = ma_date($r['created_at'] ?? null) ?? ma_now();
                    }
                    if ($table === 'rep_demandes') {
                        $r['tel'] = ma_tel((string) ($r['tel'] ?? ''));
                        $r['created_at'] = ma_date($r['created_at'] ?? null) ?? ma_now();
                    }
                    if ($table === 'chat_messages' || $table === 'chat_leads' || $table === 'cee_leads' || $table === 'cee_conversations' || $table === 'cee_actions' || $table === 'adv_demandes') {
                        $r['date'] = ma_date($r['date'] ?? null) ?? ma_now();
                    }
                    if ($table === 'cee_leads') {
                        $r['telephone_intl'] = ma_tel((string) ($r['telephone_intl'] ?? $r['telephone_brut'] ?? ''));
                    }
                    if ($table === 'cso_devis') {
                        $r['date_traitement'] = ma_date($r['date_traitement'] ?? null) ?? ma_now();
                        $r['date_offre'] = ma_date($r['date_offre'] ?? null, false);
                        $r['validite_offre'] = ma_date($r['validite_offre'] ?? null, false);
                        $r['version'] = ma_int($r['version'] ?? 1) ?: 1;
                        // doublon de n_offre : on remplace
                        $db->prepare('DELETE FROM cso_lignes WHERE n_offre = ?')->execute([(string) $r['n_offre']]);
                        $db->prepare('DELETE FROM cso_devis WHERE n_offre = ?')->execute([(string) $r['n_offre']]);
                    }
                    if ($table === 'cso_lignes') {
                        $st = $db->prepare('SELECT id FROM cso_devis WHERE n_offre = ?');
                        $st->execute([(string) ($r['n_offre'] ?? '')]);
                        $r['devis_id'] = (int) $st->fetchColumn();
                        if (!$r['devis_id']) {
                            continue;
                        }
                        $r['version'] = ma_int($r['version'] ?? 1) ?: 1;
                        $r['date_traitement'] = ma_date($r['date_traitement'] ?? null);
                    }
                    if ($table === 'chat_routage') {
                        $db->prepare('INSERT OR REPLACE INTO chat_routage(categorie, dest_to, dest_cc, libelle) VALUES (?,?,?,?)')
                            ->execute([strtolower((string) $r['categorie']), $r['dest_to'] ?? null, $r['dest_cc'] ?? null, $r['libelle'] ?? null]);
                        $n++;
                        continue;
                    }
                    $row = [];
                    foreach ($colsT as $c) {
                        if ($c !== 'id' && array_key_exists($c, $r)) {
                            $row[$c] = $r[$c];
                        }
                    }
                    if (!$row) {
                        continue;
                    }
                    $keys = array_keys($row);
                    $db->prepare("INSERT INTO $table (" . implode(',', $keys) . ') VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')')
                        ->execute(array_values($row));
                    $n++;
                }
                $rapport[] = [basename($file), $table, $n];
            }
            $db->commit();
            rename($file, $file . '.done');
        } catch (Throwable $e) {
            $db->rollBack();
            $rapport[] = [basename($file), 'ERREUR : ' . $e->getMessage(), 0];
        }
    }
    $files = glob($dir . '/*.json') ?: [];
}
if (PHP_SAPI === 'cli') {
    foreach ($rapport as $r) {
        echo implode(' | ', $r), "\n";
    }
    exit;
}
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><title>MULTIAIR - Import</title>
<link rel="stylesheet" href="assets/style.css"></head>
<body class="page-simple"><div class="box">
<h1>Import de l'historique</h1>
<?php if ($rapport): ?>
<table class="tbl"><tr><th>Fichier</th><th>Table</th><th>Lignes importées</th></tr>
<?php foreach ($rapport as [$f, $t, $n]): ?><tr><td><?= htmlspecialchars($f) ?></td><td><?= htmlspecialchars($t) ?></td><td><?= (int) $n ?></td></tr><?php endforeach; ?>
</table>
<?php endif; ?>
<?php if ($files): ?>
<p>Fichiers en attente :</p><ul><?php foreach ($files as $f): ?><li><?= htmlspecialchars(basename($f)) ?> (<?= round(filesize($f) / 1024) ?> Ko)</li><?php endforeach; ?></ul>
<form method="post"><input type="hidden" name="go" value="1"><button class="btn primary">Importer maintenant</button></form>
<?php else: ?>
<p>Aucun fichier JSON en attente dans <code>import/</code>.</p>
<?php endif; ?>
<p><a href="index.php">← Retour au tableau de bord</a></p>
</div></body></html>
