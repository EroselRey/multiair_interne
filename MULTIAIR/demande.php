<?php
// MULTIAIR — une demande, pour l'équipe : ouverte depuis l'e-mail ou le SMS de transmission, sans
// mot de passe. Le lien secret ne donne accès qu'à cette demande. Pensée d'abord pour le téléphone.
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$db = ma_db();
$jeton = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
$dem = null;
if (preg_match('/^[a-f0-9]{32}$/', $jeton)) {
    $st = $db->prepare('SELECT * FROM rep_demandes WHERE jeton_interne = ?');
    $st->execute([$jeton]);
    $dem = $st->fetch() ?: null;
}
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$connecte = ma_user();

// Une pièce jointe de la demande : le lien secret suffit, comme pour la demande elle-même.
if ($dem && isset($_GET['piece'])) {
    $st = $db->prepare('SELECT * FROM rep_pieces WHERE id = ? AND demande_id = ?');
    $st->execute([(int) $_GET['piece'], (int) $dem['id']]);
    if ($piece = $st->fetch()) {
        ma_piece_servir($piece, isset($_GET['telecharger']));
    }
    http_response_code(404);
    exit('Pièce jointe introuvable.');
}

if ($dem && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $qui = trim((string) ($_POST['qui'] ?? ''));
    if ($qui === '__autre') {
        $qui = trim((string) ($_POST['qui_autre'] ?? ''));
    }
    $qui = mb_substr($qui, 0, 80);
    if ($qui !== '') {
        setcookie('ma_qui', $qui, ['expires' => time() + 180 * 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
    }
    $action = (string) ($_POST['action'] ?? '');
    $chg = [];
    $message = 'Enregistré.';
    switch ($action) {
        case 'prendre':
            $chg = ['statut' => 'en_cours', 'pris_par' => $qui];
            $message = 'Demande prise en charge. Le client est prévenu.';
            break;
        case 'rappel':
            if (trim((string) ($_POST['rappel_prevu'] ?? '')) !== '') {
                $chg['rappel_prevu'] = (string) $_POST['rappel_prevu'];
                if ($dem['statut'] === 'a_traiter') {
                    $chg += ['statut' => 'en_cours', 'pris_par' => $qui];
                }
                $message = 'Rappel enregistré. Le client est prévenu.';
            }
            break;
        case 'traiter':
            $chg = ['statut' => 'traite', 'traite_par' => $qui];
            $message = 'Demande traitée. Le client est prévenu.';
            break;
        case 'rouvrir':
            $chg = ['statut' => 'en_cours'];
            $message = 'Demande rouverte.';
            break;
        case 'note':
            $message = 'Note ajoutée.';
            break;
        case 'piece':
            $recues = ma_pieces_recues();
            if (!$recues && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && empty($_FILES)) {
                $recues = [['nom' => 'pièce jointe', 'chemin' => null, 'erreur' => 'trop lourd pour le serveur (limite ' . ini_get('post_max_size') . ')']];
            }
            $ko = [];
            foreach ($recues as $f) {
                $p = ma_piece_ajouter($db, (int) $dem['id'], $f['nom'], $f['chemin'], null, 'equipe', $qui ?: null, null, $f['erreur']);
                if ($p['erreur']) {
                    $ko[] = $p['nom'] . ' : ' . $p['erreur'];
                }
            }
            $message = !$recues ? 'Aucun fichier choisi.' : ($ko ? 'Non enregistré — ' . implode(' ; ', $ko) : (count($recues) > 1 ? 'Pièces jointes ajoutées.' : 'Pièce jointe ajoutée.'));
            break;
    }
    if (trim((string) ($_POST['note'] ?? '')) !== '') {
        $chg['note'] = mb_substr((string) $_POST['note'], 0, 1000);
    }
    if ($chg) {
        ma_rep_avancer($db, (int) $dem['id'], $chg, $qui, 'lien_equipe');
    }
    header('Location: demande.php?t=' . $jeton . '&ok=' . rawurlencode($message), true, 303);
    exit;
}

$personnes = $dem ? ma_rep_personnes_demande($db, $dem) : [];
if ($dem && $dem['pris_par'] && !in_array($dem['pris_par'], $personnes, true)) {
    array_unshift($personnes, $dem['pris_par']);
}
$quiDefaut = (string) ($connecte['nom'] ?? ($_COOKIE['ma_qui'] ?? ''));
if ($quiDefaut === 'Administrateur') {
    $quiDefaut = (string) ($_COOKIE['ma_qui'] ?? '');
}
$rappelVal = !empty($dem['rappel_prevu']) ? date('Y-m-d\TH:i', strtotime($dem['rappel_prevu'])) : '';
$lienClient = $dem ? ma_rep_liens($db, $dem)['client'] : '';
$urgent = $dem && $dem['priorite'] === 'URGENT' && $dem['statut'] === 'a_traiter';
$etat = $dem ? ($urgent ? ['URGENT', 'urgent'] : ([
    'a_traiter' => ['À traiter', 'a_traiter'], 'en_cours' => ['En cours', 'en_cours'], 'traite' => ['Traitée', 'traite']][$dem['statut']] ?? [$dem['statut'], ''])) : null;
$canal = $dem ? ma_canal($dem) : 'telephone';
$svc = $dem ? (['SAV' => 'SAV', 'COMMERCIAL' => 'Commerce', 'FINANCE' => 'Finance', 'RH' => 'RH', 'AUTRE' => 'À orienter'][$dem['service']] ?? $dem['service']) : '';
[$materiel, $sujet] = $dem ? ma_rep_objet_client($dem) : ['', ''];
$trouve = in_array($quiDefaut, $personnes, true);
$pieces = $dem ? ma_pieces_liste($db, (int) $dem['id']) : [];
$histo = $dem ? array_values(array_filter(ma_rep_historique($db, $dem), fn($x) => !in_array($x['type'], ['message_client', 'reponse_claire'], true))) : [];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $dem ? 'Demande n° ' . (int) $dem['id'] . ' — Multiair' : 'Demande introuvable — Multiair' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= MA_VERSION ?>">
<style>
  body { background: var(--bg); }
  .m-top { background: var(--primary); color: #fff; padding: 20px 20px 22px; }
  .m-top .marque { font-weight: 800; letter-spacing: .07em; font-size: 13px; color: #a9bbd0; }
  .m-top h1 { margin: 6px 0 10px; font-size: 22px; font-weight: 800; line-height: 1.3; }
  .m-top .tags { display: flex; gap: 6px; flex-wrap: wrap; }
  .m-top .tags .pill { background: var(--primary-2); color: #fff; }
  .m-wrap { max-width: 640px; margin: 0 auto; padding: 16px; display: flex; flex-direction: column; gap: 14px; }
  .m-card { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 16px 18px; display: flex; flex-direction: column; gap: 8px; }
  .m-card .lab { font-size: 12px; font-weight: 800; letter-spacing: .05em; color: var(--muted); }
  .m-card .gros { font-size: 18px; font-weight: 800; }
  .m-card .gris { color: var(--muted); font-size: 14px; }
  .appel { display: flex; align-items: center; justify-content: center; gap: 8px; background: var(--ok); color: #fff; text-decoration: none; font-weight: 700; font-size: 17px; padding: 15px; border-radius: 12px; margin-top: 6px; }
  .m-form { display: flex; flex-direction: column; gap: 10px; }
  .m-form select, .m-form input, .m-form textarea { font: inherit; font-size: 16px; padding: 12px; border: 1px solid var(--border-2); border-radius: 12px; background: #fff; width: 100%; color: var(--text); }
  .m-form label { display: flex; flex-direction: column; gap: 6px; font-size: 13px; font-weight: 700; color: var(--muted); }
  .m-form .btn { font-size: 16px; padding: 15px; border-radius: 12px; }
  .deux { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  details summary { cursor: pointer; font-weight: 700; color: var(--primary); padding: 4px 0; }
  details[open] summary { margin-bottom: 10px; }
  .ev { display: flex; flex-direction: column; gap: 10px; font-size: 14px; }
  .ev div { border-left: 3px solid var(--border-2); padding-left: 10px; }
  .ev small { display: block; color: var(--muted); }
  .pied { font-size: 13px; color: var(--muted); text-align: center; }
  .pj { display: flex; flex-direction: column; gap: 4px; border-top: 1px solid var(--border); padding-top: 10px; }
  .pj img, .pj video { width: 100%; max-height: 320px; object-fit: contain; background: #000; border-radius: 10px; }
  .pj small { color: var(--muted); }
</style>
</head>
<body>
<?php if (!$dem): ?>
  <div class="m-top"><div class="marque">MULTIAIR</div><h1>Demande introuvable</h1></div>
  <div class="m-wrap"><div class="m-card">Ce lien n'est pas valide. Utilisez le lien reçu par e-mail ou par SMS, ou connectez-vous à la plateforme.
    <a class="btn primary" href="index.php">Ouvrir la plateforme</a></div></div>
<?php else: ?>
  <header class="m-top">
    <div class="marque">MULTIAIR</div>
    <h1>Demande n° <?= (int) $dem['id'] ?><?= $sujet !== '' ? ' — ' . $e($sujet) : '' ?></h1>
    <div class="tags"><span class="st <?= $e($etat[1]) ?>"><?= $e($etat[0]) ?></span><span class="pill"><?= $e($svc . ' · ' . (MA_CANAUX[$canal][0] ?? '')) ?></span>
      <span class="pill"><?= $e(ma_date_fr($dem['created_at'])) ?></span></div>
  </header>
  <div class="m-wrap">
    <?php if (isset($_GET['ok'])): ?><div class="msg ok" role="status"><?= $e($_GET['ok'] ?: 'Enregistré.') ?></div><?php endif; ?>
    <?php if ($urgent): ?><div class="msg err"><b>URGENT — production arrêtée.</b> À rappeler tout de suite.</div><?php endif; ?>

    <section class="m-card">
      <span class="lab">CLIENT À RAPPELER</span>
      <span class="gros"><?= $e($dem['contact'] ?: 'Contact non précisé') ?><?= $dem['societe'] ? ' — ' . $e($dem['societe']) : '' ?></span>
      <span class="gris"><?= $e(implode(' · ', array_filter([($dem['type_client'] ?? '') === 'distributeur' ? 'Distributeur' : 'Client direct',
          trim(($dem['code_postal'] ?? '') ?: ($dem['departement'] ? 'dpt ' . $dem['departement'] : ''))]))) ?></span>
      <?php if ($dem['email']): ?><span class="gris"><?= $e($dem['email']) ?></span><?php endif; ?>
      <?php if ($dem['tel']): ?><a class="appel" href="tel:+<?= $e($dem['tel']) ?>">Appeler le <?= $e(ma_tel_lisible($dem['tel'])) ?></a><?php endif; ?>
    </section>

    <section class="m-card">
      <span class="lab">LA DEMANDE</span>
      <?php if ($materiel !== ''): ?><b><?= $e($materiel) ?><?= $dem['numero_serie'] ? ' · n° ' . $e($dem['numero_serie']) : '' ?></b><?php endif; ?>
      <?php foreach ([['Problème', $dem['type_panne']], ['Besoin', $dem['besoin_commercial']], ['Facture', $dem['reference_facture']],
          ['Pourquoi urgent', $dem['priorite'] === 'URGENT' ? $dem['justification_urgence'] : ''], ['Résumé', $dem['resume']]] as [$k, $v]):
          if (trim((string) $v) === '') continue; ?>
        <div><span class="gris"><?= $e($k) ?> : </span><?= nl2br($e($v)) ?></div>
      <?php endforeach; ?>
      <span class="gris">Transmise à <?= $e($dem['destinataires'] ?: str_replace(';', ', ', (string) $dem['dest_to'])) ?>
        <?= $dem['pris_par'] ? ' · prise en charge par ' . $e($dem['pris_par']) : '' ?>
        <?= $dem['rappel_prevu'] ? ' · rappel prévu ' . $e(ma_date_fr($dem['rappel_prevu'])) : '' ?></span>
    </section>

    <section class="m-card">
      <span class="lab">PIÈCES JOINTES<?= $pieces ? ' (' . count($pieces) . ')' : '' ?></span>
      <?php if (!$pieces): ?><span class="gris">Aucune pièce jointe.</span><?php endif; ?>
      <?php foreach ($pieces as $p): $url = 'demande.php?t=' . $jeton . '&piece=' . (int) $p['id']; ?>
        <div class="pj">
          <?php if (!$p['erreur'] && str_starts_with((string) $p['type'], 'image/') && $p['en_ligne']): ?><a href="<?= $e($url) ?>" target="_blank" rel="noopener"><img src="<?= $e($url) ?>" alt="<?= $e($p['nom']) ?>" loading="lazy"></a>
          <?php elseif (!$p['erreur'] && str_starts_with((string) $p['type'], 'video/')): ?><video src="<?= $e($url) ?>" controls preload="metadata" playsinline></video><?php endif; ?>
          <?php if ($p['erreur']): ?><b><?= $e($p['nom']) ?></b><small style="color:var(--danger)">Non enregistrée : <?= $e($p['erreur']) ?> — voir l'e-mail d'origine</small>
          <?php else: ?><a href="<?= $e($url) ?>" target="_blank" rel="noopener"><b><?= $e($p['nom']) ?></b></a>
            <small><?= $e(($p['taille'] >= 1048576 ? round($p['taille'] / 1048576, 1) . ' Mo' : max(1, (int) round($p['taille'] / 1024)) . ' Ko') . ' · ' . ($p['source'] === 'email' ? 'reçue avec l\'e-mail' : 'ajoutée' . ($p['ajoute_par'] ? ' par ' . $p['ajoute_par'] : ''))) ?> · <a href="<?= $e($url) ?>&telecharger=1">Télécharger</a></small><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <details><summary>Ajouter une photo, une vidéo ou un document</summary>
        <form method="post" enctype="multipart/form-data" class="m-form">
          <input type="hidden" name="t" value="<?= $e($jeton) ?>">
          <input type="hidden" name="qui" value="<?= $e($quiDefaut) ?>">
          <input type="file" name="fichier[]" multiple required aria-label="Fichiers à ajouter">
          <button class="btn" name="action" value="piece">Ajouter à la demande</button>
        </form></details>
    </section>

    <form method="post" class="m-form">
      <input type="hidden" name="t" value="<?= $e($jeton) ?>">
      <label>QUI ÊTES-VOUS ?
        <select name="qui" required onchange="this.form.qui_autre.hidden = this.value !== '__autre'">
          <option value="">— Choisir —</option>
          <?php foreach ($personnes as $p): ?><option<?= $p === $quiDefaut ? ' selected' : '' ?>><?= $e($p) ?></option><?php endforeach; ?>
          <option value="__autre"<?= !$trouve && $quiDefaut !== '' ? ' selected' : '' ?>>Autre personne…</option>
        </select>
        <input name="qui_autre" placeholder="Votre nom" value="<?= !$trouve ? $e($quiDefaut) : '' ?>"<?= $trouve || $quiDefaut === '' ? ' hidden' : '' ?>>
      </label>
      <?php if ($dem['statut'] === 'traite'): ?>
        <div class="msg ok">Cette demande est traitée<?= $dem['traite_par'] ? ' par ' . $e($dem['traite_par']) : '' ?>.</div>
        <button class="btn" name="action" value="rouvrir">Rouvrir la demande</button>
      <?php else: ?>
        <?php if ($dem['statut'] === 'a_traiter'): ?>
          <button class="btn <?= $urgent ? 'urgent' : 'primary' ?>" name="action" value="prendre">Je la prends en charge</button>
        <?php endif; ?>
        <details<?= $dem['statut'] === 'en_cours' && !$dem['rappel_prevu'] ? ' open' : '' ?>><summary>Planifier un rappel</summary>
          <div class="m-form"><input type="datetime-local" name="rappel_prevu" value="<?= $e($rappelVal) ?>" aria-label="Date et heure du rappel">
            <button class="btn" name="action" value="rappel">Enregistrer le rappel et prévenir le client</button></div></details>
        <button class="btn go" name="action" value="traiter" onclick="return confirm('Marquer la demande comme traitée ? Le client sera prévenu.')">Marquer traitée</button>
      <?php endif; ?>
      <details><summary>Ajouter une note interne</summary>
        <div class="m-form"><textarea name="note" rows="3" placeholder="Visible uniquement par l'équipe Multiair"></textarea>
          <button class="btn" name="action" value="note">Enregistrer la note</button></div></details>
    </form>
    <p class="hint" style="margin:0">Chaque étape prévient le client par WhatsApp et e-mail. Vos coordonnées ne lui sont jamais communiquées.</p>

    <details class="m-card"><summary>Historique (<?= count($histo) ?>)</summary>
      <div class="ev"><?php foreach ($histo as $x): $d = $x['detail'] ?? []; ?>
        <div><b><?= $e($x['resume']) ?></b><small><?= $e(ma_date_fr($x['date'])) ?><?= $x['qui'] ? ' · ' . $e($x['qui']) : '' ?></small>
          <?php foreach (($d['envois'] ?? $d['canaux'] ?? []) as $v): ?><small><?= $e($v['canal'] . ' → ' . $v['a']) ?></small><?php endforeach; ?>
          <?php if (!empty($d['texte']) && $x['type'] === 'note'): ?><small><?= $e($d['texte']) ?></small><?php endif; ?></div>
      <?php endforeach; ?></div></details>

    <p class="pied"><a href="<?= $e($lienClient) ?>" target="_blank" rel="noopener">Voir ce que voit le client</a> · <a href="index.php#demande/<?= (int) $dem['id'] ?>">Ouvrir sur la plateforme</a></p>
  </div>
<?php endif; ?>
</body>
</html>
