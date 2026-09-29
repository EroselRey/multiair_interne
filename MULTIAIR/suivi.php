<?php
// MULTIAIR — suivi d'une demande, pour le client : ouvert depuis le WhatsApp ou l'e-mail reçu.
// Le lien secret ne montre que SA demande, et jamais une coordonnée interne (portable, e-mail).
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$db = ma_db();
$jeton = (string) ($_GET['c'] ?? '');
$dem = null;
if (preg_match('/^[a-f0-9]{32}$/', $jeton)) {
    $st = $db->prepare('SELECT * FROM rep_demandes WHERE jeton_client = ?');
    $st->execute([$jeton]);
    $dem = $st->fetch() ?: null;
}
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$standard = ma_param($db, 'rep_standard_tel', '01 34 32 95 00');
$standardTel = preg_replace('/\D/', '', $standard);

$objet = '';
$etapes = [];
$maintenant = '';
if ($dem) {
    [$materiel, $sujet] = ma_rep_objet_client($dem);
    $objet = trim($materiel . ($materiel && $sujet ? ' — ' : '') . $sujet);
    $role = strtoupper((string) $dem['service']) === 'SAV' ? 'responsable technique' : 'de l\'équipe Multiair';
    $pris = (bool) $dem['pris_at'] || $dem['statut'] !== 'a_traiter';
    $etapes[] = ['Demande reçue', ucfirst(ma_date_fr($dem['created_at'])), true];
    $etapes[] = ['Prise en charge', $pris ? trim(($dem['pris_par'] ? 'Par ' . $dem['pris_par'] . ', ' . $role : 'Par notre équipe') . ($dem['pris_at'] ? ' · ' . ma_date_fr($dem['pris_at']) : '')) : 'Nous vous recontactons au plus vite', $pris];
    if (!empty($dem['rappel_prevu'])) {
        $etapes[] = ['Rappel prévu', ucfirst(ma_date_fr($dem['rappel_prevu'])), $dem['statut'] === 'traite' || strtotime($dem['rappel_prevu']) <= time()];
    }
    $etapes[] = ['Demande traitée', $dem['traite_at'] ? ucfirst(ma_date_fr($dem['traite_at'])) : '', $dem['statut'] === 'traite'];
    $evt = $dem['statut'] === 'traite' ? 'traitee' : ($pris ? (!empty($dem['rappel_prevu']) && strtotime($dem['rappel_prevu']) > time() ? 'rappel' : 'prise_en_charge') : 'recue');
    $maintenant = ma_rep_message_client($db, $dem, $evt)[1];
}
$check = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $dem ? 'Votre demande n° ' . (int) $dem['id'] . ' — Multiair France' : 'Suivi de demande — Multiair France' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= MA_VERSION ?>">
<style>
  .s-top { background: var(--primary); color: #fff; padding: 24px 20px; }
  .s-top > div { max-width: 560px; margin: 0 auto; display: flex; flex-direction: column; gap: 6px; }
  .s-top .marque { font-weight: 800; letter-spacing: .07em; font-size: 13px; color: #a9bbd0; }
  .s-top h1 { margin: 0; font-size: 25px; font-weight: 800; }
  .s-top p { margin: 0; color: #dbe4ee; font-size: 15px; }
  .s-wrap { max-width: 560px; margin: 0 auto; padding: 18px 16px 40px; display: flex; flex-direction: column; gap: 14px; }
  .s-now { background: var(--ok-bg); border-radius: 14px; padding: 16px 18px; display: flex; flex-direction: column; gap: 4px; }
  .s-now span { font-size: 13px; font-weight: 800; color: var(--ok); letter-spacing: .04em; }
  .s-now b { font-size: 16px; line-height: 1.5; font-weight: 700; }
  .s-card { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 18px; }
  .s-tl { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 18px; }
  .s-tl li { display: flex; gap: 14px; }
  .s-tl .pt { width: 26px; height: 26px; border-radius: 50%; border: 2px solid var(--border-2); flex: none; display: flex; align-items: center; justify-content: center; color: #fff; }
  .s-tl li.fait .pt { background: var(--ok); border-color: var(--ok); }
  .s-tl li > span:last-child { display: flex; flex-direction: column; }
  .s-tl b { font-weight: 700; }
  .s-tl li:not(.fait) b { color: var(--muted); }
  .s-tl small { color: var(--muted); font-size: 13px; }
  .appel { display: flex; align-items: center; justify-content: center; gap: 8px; background: var(--primary); color: #fff; text-decoration: none; font-weight: 700; font-size: 17px; padding: 15px; border-radius: 12px; margin-top: 10px; }
  .pied { color: var(--muted); font-size: 13px; text-align: center; margin: 0; }
</style>
</head>
<body>
<?php if (!$dem): ?>
  <header class="s-top"><div><span class="marque">MULTIAIR FRANCE</span><h1>Demande introuvable</h1></div></header>
  <div class="s-wrap"><div class="s-card">Ce lien de suivi n'est pas valide. Pour toute question, appelez notre standard.
    <a class="appel" href="tel:<?= $e($standardTel) ?>"><?= $e($standard) ?></a></div></div>
<?php else: ?>
  <header class="s-top"><div>
    <span class="marque">MULTIAIR FRANCE</span>
    <h1>Votre demande n° <?= (int) $dem['id'] ?></h1>
    <?php if ($objet !== ''): ?><p><?= $e($objet) ?></p><?php endif; ?>
  </div></header>
  <div class="s-wrap">
    <section class="s-now" aria-live="polite"><span>EN CE MOMENT</span><b><?= $e($maintenant) ?></b></section>
    <section class="s-card" aria-label="Avancement">
      <ol class="s-tl">
      <?php foreach ($etapes as [$titre, $quand, $fait]): ?>
        <li class="<?= $fait ? 'fait' : '' ?>"><span class="pt"><?= $fait ? $check : '' ?></span>
          <span><b><?= $e($titre) ?></b><?php if ($quand !== ''): ?><small><?= $e($quand) ?></small><?php endif; ?></span></li>
      <?php endforeach; ?>
      </ol>
    </section>
    <section class="s-card">Une question ? Appelez notre standard en indiquant le n° <?= (int) $dem['id'] ?>.
      <a class="appel" href="tel:<?= $e($standardTel) ?>"><?= $e($standard) ?></a></section>
    <p class="pied">Page personnelle : elle ne montre que votre demande et se met à jour à chaque étape.</p>
  </div>
<?php endif; ?>
</body>
</html>
