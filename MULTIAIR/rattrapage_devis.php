<?php
/**
 * Rattrapage des devis CSO présents dans le classeur Google mais absents de la base.
 *
 * Entre le 14/09 et le 15/09, la route d'enregistrement des devis avait disparu de
 * l'API (erreur de ma part lors d'une modification) : le scénario Make appelait
 * l'API, recevait « ok », et rien n'était écrit. Le classeur Google, lui, a continué
 * d'être alimenté — d'où cette reprise.
 *
 * Mode d'emploi :
 *   1. Dans le classeur « Suivi devis CSO », onglet Devis  : Fichier > Télécharger > CSV
 *      puis onglet Lignes : Fichier > Télécharger > CSV
 *   2. Déposer les deux fichiers dans MULTIAIR/import/ (garder leur nom, il contient
 *      « Devis » et « Lignes »)
 *   3. Ouvrir cette page. Seuls les devis absents de la base sont ajoutés.
 *   4. Supprimer ce fichier et les CSV une fois le résultat vérifié.
 */
require_once __DIR__ . '/lib.php';
header('Content-Type: text/html; charset=utf-8');

/** Lit un CSV Google (séparateur détecté, BOM retiré) et renvoie les lignes de données. */
function lireCsv(string $fichier): array
{
    $brut = file_get_contents($fichier);
    $brut = preg_replace('/^\xEF\xBB\xBF/', '', (string) $brut);
    $lignes = [];
    $fp = fopen('php://memory', 'r+');
    fwrite($fp, $brut);
    rewind($fp);
    $premiere = fgets($fp);
    $sep = substr_count((string) $premiere, ';') > substr_count((string) $premiere, ',') ? ';' : ',';
    rewind($fp);
    while (($l = fgetcsv($fp, 0, $sep)) !== false) {
        if ($l !== [null] && array_filter($l, fn($v) => trim((string) $v) !== '')) {
            $lignes[] = $l;
        }
    }
    fclose($fp);
    array_shift($lignes);   // en-tête
    return $lignes;
}

$nombre = function ($v) {
    $v = trim(str_replace([' ', ' ', '€'], '', (string) $v));
    $v = str_replace(',', '.', $v);
    return $v === '' ? null : (float) $v;
};
$date = function ($v) {
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})(.*)$#', $v, $m)) {
        return "$m[3]-$m[2]-$m[1]" . ($m[4] !== '' ? ' ' . trim($m[4]) : '');
    }
    return $v;
};

$dossier = __DIR__ . '/import';
$fDevis = glob($dossier . '/*[Dd]evis*.csv');
$fLignes = glob($dossier . '/*[Ll]ignes*.csv');
$messages = [];
$ajoutes = [];
$presents = 0;
$lignesAjoutees = 0;

if (!$fDevis) {
    $messages[] = "Aucun fichier CSV contenant « Devis » dans " . htmlspecialchars($dossier) . ".";
} else {
    $db = ma_db();
    $lignesParOffre = [];
    foreach ($fLignes as $f) {
        foreach (lireCsv($f) as $l) {
            $n = trim((string) ($l[0] ?? ''));
            if ($n !== '') {
                $lignesParOffre[$n][] = $l;
            }
        }
    }
    $existe = $db->prepare('SELECT COUNT(*) FROM cso_devis WHERE n_offre = ?');
    $insDevis = $db->prepare('INSERT INTO cso_devis(date_traitement, n_offre, n_client, client, contact_client,
        email_client, tel_client, commercial, date_offre, validite_offre, ref_demande_client, montant_ht,
        transport, montant_ttc, nb_lignes, controle_coherence, statut, relance_1_j3, relance_2_j7, relance_3_j15,
        relances_envoyees, reponse_client, fichier_source, message_id, destinataire_email, copies_email,
        empreinte, version, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $insLigne = $db->prepare('INSERT INTO cso_lignes(devis_id, n_offre, version, poste, reference, designation,
        quantite, prix_unitaire, prix_total_ht, pays_origine, code_douanier, date_traitement)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');

    foreach ($fDevis as $f) {
        foreach (lireCsv($f) as $d) {
            $n = trim((string) ($d[1] ?? ''));
            if ($n === '' || !preg_match('/^\d{6,}$/', $n)) {
                continue;
            }
            $existe->execute([$n]);
            if ((int) $existe->fetchColumn() > 0) {
                $presents++;
                continue;
            }
            $dateTraitement = $date($d[0] ?? '') ?? ma_now();
            $version = max(1, (int) ($d[27] ?? 1));
            $insDevis->execute([
                $dateTraitement, $n, ma_str($d[2] ?? null), ma_str($d[3] ?? null), ma_str($d[4] ?? null),
                ma_str($d[5] ?? null), ma_str($d[6] ?? null), ma_str($d[7] ?? null),
                $date($d[8] ?? ''), $date($d[9] ?? ''), ma_str($d[10] ?? null),
                $nombre($d[11] ?? null), $nombre($d[12] ?? null), $nombre($d[13] ?? null),
                (int) ($d[14] ?? 0), ma_str($d[15] ?? null), ma_str($d[16] ?? null) ?? 'En attente',
                $date($d[17] ?? ''), $date($d[18] ?? ''), $date($d[19] ?? ''),
                (int) ($d[20] ?? 0), ma_str($d[21] ?? null), ma_str($d[22] ?? null),
                ma_str($d[23] ?? null), ma_str($d[24] ?? null), ma_str($d[25] ?? null),
                ma_str($d[26] ?? null), $version, ma_now(),
            ]);
            $id = (int) $db->lastInsertId();
            $nb = 0;
            foreach ($lignesParOffre[$n] ?? [] as $l) {
                $insLigne->execute([$id, $n, $version, ma_int($l[1] ?? null), ma_str($l[2] ?? null),
                    ma_str($l[3] ?? null), $nombre($l[4] ?? null), $nombre($l[5] ?? null), $nombre($l[6] ?? null),
                    ma_str($l[7] ?? null), ma_str($l[8] ?? null), $dateTraitement]);
                $nb++;
                $lignesAjoutees++;
            }
            $ajoutes[] = [$n, $d[3] ?? '', $d[16] ?? '', $nombre($d[11] ?? null), $nb];
        }
    }
    $db->prepare('INSERT INTO executions_log(date, scenario, statut, type_evenement, resume, payload) VALUES (?,?,?,?,?,?)')
        ->execute([ma_now(), 'cso', 'ok', 'rattrapage_devis',
            count($ajoutes) . ' devis repris du classeur (' . $lignesAjoutees . ' lignes), ' . $presents . ' déjà présent(s)',
            json_encode(['ajoutes' => count($ajoutes), 'presents' => $presents], JSON_UNESCAPED_UNICODE)]);
}
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><title>MULTIAIR — reprise des devis</title>
<style>body{font-family:system-ui,sans-serif;max-width:900px;margin:40px auto;padding:0 16px;color:#1c2431}
table{border-collapse:collapse;width:100%}td,th{padding:8px 10px;border-bottom:1px solid #e3e7ee;text-align:left;font-size:14px}
.res{padding:14px;border-radius:8px;margin:18px 0;background:#e6f5ec;font-weight:600}
.ko{background:#fbe9e7}.num{text-align:right}</style></head><body>
<h1>Reprise des devis depuis le classeur</h1>
<?php if ($messages): ?>
<div class="res ko"><?= implode('<br>', array_map('htmlspecialchars', $messages)) ?></div>
<p>Exportez l'onglet <strong>Devis</strong> et l'onglet <strong>Lignes</strong> du classeur en CSV
(Fichier &gt; Télécharger &gt; Valeurs séparées par des virgules), déposez les deux fichiers dans
<code>MULTIAIR/import/</code>, puis rechargez cette page.</p>
<?php else: ?>
<div class="res"><?= count($ajoutes) ?> devis ajouté(s) · <?= $lignesAjoutees ?> ligne(s) · <?= $presents ?> déjà présent(s)</div>
<table><tr><th>N° offre</th><th>Client</th><th>Statut</th><th class="num">Montant HT</th><th class="num">Lignes</th></tr>
<?php foreach ($ajoutes as [$n, $c, $s, $m, $nb]): ?>
<tr><td><?= htmlspecialchars($n) ?></td><td><?= htmlspecialchars((string) $c) ?></td>
<td><?= htmlspecialchars((string) $s) ?></td>
<td class="num"><?= number_format((float) $m, 2, ',', ' ') ?> €</td><td class="num"><?= $nb ?></td></tr>
<?php endforeach; ?>
</table>
<p>Vous pouvez relancer cette page sans risque : un devis déjà en base n'est jamais dupliqué.<br>
Supprimez ensuite ce fichier et les CSV du dossier <code>import/</code>.</p>
<?php endif; ?>
</body></html>
