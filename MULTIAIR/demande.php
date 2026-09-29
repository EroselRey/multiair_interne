<?php
// MULTIAIR — une demande du répondeur, pour l'équipe : ouverte depuis l'e-mail ou le SMS de
// transmission, sans compte. Le lien secret ne donne accès qu'à cette demande.
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

if ($dem && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $qui = trim((string) ($_POST['qui'] ?? ''));
    if ($qui === '__autre') {
        $qui = trim((string) ($_POST['qui_autre'] ?? ''));
    }
    $qui = mb_substr($qui, 0, 80);
    if ($qui !== '') {
        setcookie('ma_qui', $qui, ['expires' => time() + 180 * 86400, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    }
    $chg = [];
    switch ($_POST['action'] ?? '') {
        case 'prendre':
            $chg = ['statut' => 'en_cours', 'pris_par' => $qui];
            break;
        case 'traiter':
            $chg = ['statut' => 'traite', 'traite_par' => $qui];
            break;
        case 'rouvrir':
            $chg = ['statut' => 'en_cours'];
            break;
    }
    if (array_key_exists('rappel_prevu', $_POST) && ($_POST['action'] ?? '') !== 'traiter') {
        $chg['rappel_prevu'] = (string) $_POST['rappel_prevu'];
    }
    if (trim((string) ($_POST['note'] ?? '')) !== '') {
        $chg['note'] = mb_substr((string) $_POST['note'], 0, 1000);
    }
    if ($chg) {
        ma_rep_avancer($db, (int) $dem['id'], $chg, $qui);
    }
    header('Location: demande.php?t=' . $jeton . '&ok=1', true, 303);
    exit;
}

$statuts = ['a_traiter' => ['À traiter', '#b3261e'], 'en_cours' => ['Pris en charge', '#b26a00'], 'traite' => ['Traitée', '#2e7d5b']];
$tel = (string) ($dem['tel'] ?? '');
$telLocal = preg_match('/^33(\d{9})$/', $tel, $m) ? '0' . $m[1] : $tel;
$telLisible = preg_match('/^0\d{9}$/', $telLocal) ? trim(chunk_split($telLocal, 2, ' ')) : $telLocal;
$personnes = $dem ? ma_rep_personnes_demande($db, $dem) : [];
$quiDefaut = (string) ($_COOKIE['ma_qui'] ?? '');
$rappelVal = !empty($dem['rappel_prevu']) ? date('Y-m-d\TH:i', strtotime($dem['rappel_prevu'])) : '';
$lienClient = $dem ? ma_rep_liens($db, $dem)['client'] : '';

$choixQui = function () use ($personnes, $quiDefaut, $e): string {
    $h = '<select name="qui" required onchange="this.form.qui_autre.hidden=this.value!==\'__autre\'">'
        . '<option value="">— Qui êtes-vous ? —</option>';
    $trouve = false;
    foreach ($personnes as $p) {
        $sel = $p === $quiDefaut ? ' selected' : '';
        $trouve = $trouve || $sel !== '';
        $h .= '<option' . $sel . '>' . $e($p) . '</option>';
    }
    $h .= '<option value="__autre"' . (!$trouve && $quiDefaut !== '' ? ' selected' : '') . '>Autre personne…</option></select>'
        . '<input name="qui_autre" placeholder="Votre nom" value="' . (!$trouve ? $e($quiDefaut) : '') . '"'
        . ($trouve || $quiDefaut === '' ? ' hidden' : '') . '>';
    return $h;
};
$ligne = fn($k, $v) => trim((string) $v) === '' ? '' : '<tr><th>' . $e($k) . '</th><td>' . nl2br($e($v)) . '</td></tr>';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $dem ? 'Demande n° ' . (int) $dem['id'] . ' — Multiair' : 'Demande introuvable — Multiair' ?></title>
<style>
  :root { --ink:#1c2431; --muted:#5f6b7a; --line:#dde3ea; --bg:#f4f6f8; --card:#fff; --primary:#0f2f52; --primary-2:#1c4f86; --danger:#b3261e; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--ink); font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif; }
  .wrap { max-width:720px; margin:0 auto; padding:16px; display:grid; gap:14px; }
  header { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  header .marque { font-weight:800; color:var(--primary); letter-spacing:.04em; }
  h1 { font-size:22px; margin:0; }
  .pill { display:inline-block; padding:2px 10px; border-radius:999px; color:#fff; font-size:13px; font-weight:700; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:16px; display:grid; gap:10px; }
  .urgent { background:var(--danger); color:#fff; border-radius:8px; padding:10px 14px; font-weight:700; }
  .appeler { font-size:18px; }
  .appeler a.tel { display:inline-block; margin-top:6px; background:var(--primary-2); color:#fff; padding:10px 16px; border-radius:8px; text-decoration:none; font-weight:700; }
  table { border-collapse:collapse; width:100%; font-size:15px; }
  th { text-align:left; vertical-align:top; color:var(--muted); font-weight:600; padding:5px 12px 5px 0; white-space:nowrap; width:1%; }
  td { padding:5px 0; }
  form { display:grid; gap:10px; }
  label { display:grid; gap:4px; font-size:14px; color:var(--muted); }
  select, input, textarea { font:inherit; padding:9px 10px; border:1px solid var(--line); border-radius:8px; width:100%; background:#fff; color:var(--ink); }
  button { font:inherit; font-weight:700; padding:12px 16px; border:0; border-radius:8px; cursor:pointer; background:var(--primary-2); color:#fff; }
  button.ok { background:#2e7d5b; }
  button.sec { background:#e8edf2; color:var(--ink); }
  .row { display:flex; gap:10px; flex-wrap:wrap; }
  .row > * { flex:1 1 200px; }
  .msg { background:#e3f1ea; color:#123f2c; border-radius:8px; padding:10px 14px; }
  .hist { white-space:pre-wrap; font-size:14px; color:var(--muted); }
  .pied { font-size:13px; color:var(--muted); }
  a:focus-visible, button:focus-visible, select:focus-visible, input:focus-visible, textarea:focus-visible { outline:3px solid #8ab4f8; outline-offset:2px; }
</style>
</head>
<body>
<div class="wrap">
<?php if (!$dem): ?>
  <header><span class="marque">MULTIAIR</span></header>
  <div class="card"><h1>Demande introuvable</h1><p>Ce lien n'est pas valide. Utilisez le lien reçu par e-mail ou par SMS, ou ouvrez le tableau de bord MULTIAIR.</p></div>
<?php else:
    [$stLib, $stCoul] = $statuts[$dem['statut']] ?? [$dem['statut'], '#5f6b7a']; ?>
  <header>
    <span class="marque">MULTIAIR</span>
    <h1>Demande n° <?= (int) $dem['id'] ?> — <?= $e($dem['service']) ?><?= $dem['marque'] ? ' ' . $e($dem['marque']) : '' ?></h1>
    <span class="pill" style="background:<?= $stCoul ?>"><?= $e($stLib) ?></span>
  </header>
  <?php if (isset($_GET['ok'])): ?><div class="msg" role="status">Enregistré. Le client est prévenu de l'étape.</div><?php endif; ?>
  <?php if ($dem['priorite'] === 'URGENT' && $dem['statut'] !== 'traite'): ?><div class="urgent">🔴 URGENT — production arrêtée : rappel immédiat</div><?php endif; ?>

  <div class="card appeler">
    <div><b>À rappeler :</b> <?= $e($dem['contact'] ?: 'contact non précisé') ?> — <?= $e($dem['societe'] ?: 'société non précisée') ?></div>
    <?php if ($telLisible): ?><div><a class="tel" href="tel:<?= $e($telLocal) ?>">📞 <?= $e($telLisible) ?></a></div><?php endif; ?>
  </div>

  <div class="card">
    <table>
      <?= $ligne('Reçue le', ma_date_fr($dem['created_at'])) ?>
      <?= $ligne('Type de client', ($dem['type_client'] ?? '') === 'distributeur' ? 'Distributeur' : 'Client direct') ?>
      <?= $ligne('Site', trim(($dem['code_postal'] ?? '') . ($dem['departement'] ? ' (dép. ' . $dem['departement'] . ')' : ''))) ?>
      <?= $ligne('Marque / modèle', trim(($dem['marque'] ?? '') . ' ' . ($dem['modele'] ?? ''))) ?>
      <?= $ligne('N° de série', $dem['numero_serie'] ?? '') ?>
      <?= $ligne('Problème', $dem['type_panne'] ?? '') ?>
      <?= $ligne('Besoin', $dem['besoin_commercial'] ?? '') ?>
      <?= $ligne('Facture / dossier', $dem['reference_facture'] ?? '') ?>
      <?= $ligne('Pourquoi urgent', $dem['priorite'] === 'URGENT' ? ($dem['justification_urgence'] ?? '') : '') ?>
      <?= $ligne('Résumé', $dem['resume'] ?? '') ?>
      <?= $ligne('E-mail du client', $dem['email'] ?? '') ?>
      <?= $ligne('Transmis à', $dem['destinataires'] ?? '') ?>
      <?= $ligne('Pris en charge', $dem['pris_at'] ? ma_date_fr($dem['pris_at']) . ($dem['pris_par'] ? ' par ' . $dem['pris_par'] : '') : '') ?>
      <?= $ligne('Rappel prévu', ma_date_fr($dem['rappel_prevu'] ?? null)) ?>
      <?= $ligne('Traitée', $dem['traite_at'] ? ma_date_fr($dem['traite_at']) . ($dem['traite_par'] ? ' par ' . $dem['traite_par'] : '') : '') ?>
    </table>
  </div>

  <div class="card">
  <?php if ($dem['statut'] === 'a_traiter'): ?>
    <form method="post">
      <input type="hidden" name="t" value="<?= $e($jeton) ?>">
      <input type="hidden" name="action" value="prendre">
      <div class="row">
        <label>Qui prend la demande ?<?= $choixQui() ?></label>
        <label>Rappel prévu (facultatif)<input type="datetime-local" name="rappel_prevu" value="<?= $e($rappelVal) ?>"></label>
      </div>
      <label>Note interne (facultatif)<textarea name="note" rows="2"></textarea></label>
      <button type="submit">Je prends en charge</button>
    </form>
  <?php elseif ($dem['statut'] === 'en_cours'): ?>
    <form method="post">
      <input type="hidden" name="t" value="<?= $e($jeton) ?>">
      <input type="hidden" name="action" value="rappel">
      <div class="row">
        <label>Rappel ou intervention prévu<input type="datetime-local" name="rappel_prevu" value="<?= $e($rappelVal) ?>"></label>
        <label>Note interne (facultatif)<input name="note"></label>
      </div>
      <button type="submit" class="sec">Enregistrer la date / la note</button>
    </form>
    <form method="post">
      <input type="hidden" name="t" value="<?= $e($jeton) ?>">
      <input type="hidden" name="action" value="traiter">
      <label>Qui clôture ?<?= $choixQui() ?></label>
      <label>Note de clôture (facultatif)<textarea name="note" rows="2"></textarea></label>
      <button type="submit" class="ok">Demande traitée</button>
    </form>
  <?php else: ?>
    <p>Cette demande est traitée.</p>
    <form method="post">
      <input type="hidden" name="t" value="<?= $e($jeton) ?>">
      <input type="hidden" name="action" value="rouvrir">
      <label>Note (facultatif)<input name="note"></label>
      <button type="submit" class="sec">Rouvrir la demande</button>
    </form>
  <?php endif; ?>
  </div>

  <?php if (trim((string) $dem['commentaire']) !== ''): ?>
    <div class="card"><b>Historique</b><div class="hist"><?= $e($dem['commentaire']) ?></div></div>
  <?php endif; ?>

  <p class="pied">Page réservée à l'équipe Multiair. Le client suit sa demande sur sa propre page, sans vos coordonnées :
    <a href="<?= $e($lienClient) ?>" target="_blank" rel="noopener">voir ce que voit le client</a>.</p>
<?php endif; ?>
</div>
</body>
</html>
