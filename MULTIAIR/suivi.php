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
if ($dem) {
    $materiel = trim(($dem['marque'] ?? '') . ' ' . ($dem['modele'] ?? ''));
    $materiel = $materiel === 'Inconnue' ? '' : $materiel;
    $sujet = trim((string) ($dem['type_panne'] ?: $dem['besoin_commercial'] ?: ($dem['reference_facture'] ? 'Facture ' . $dem['reference_facture'] : '')));
    $objet = trim($materiel . ($materiel && $sujet ? ' — ' : '') . $sujet);
    $role = strtoupper((string) $dem['service']) === 'SAV' ? 'responsable technique' : 'équipe Multiair';
    $etapes = [
        ['Demande reçue', ma_date_fr($dem['created_at']), 'Transmise à notre ' . ma_rep_service_libelle($dem['service']) . '.', true],
        ['Prise en charge', ma_date_fr($dem['pris_at'] ?? null),
            $dem['pris_at'] ? (($dem['pris_par'] ? $dem['pris_par'] . ', ' . $role . ', s\'occupe de votre demande.' : 'Notre équipe s\'occupe de votre demande.'))
                : 'Nous vous recontactons au plus vite.', (bool) $dem['pris_at']],
    ];
    if (!empty($dem['rappel_prevu'])) {
        $etapes[] = ['Rappel prévu', ma_date_fr($dem['rappel_prevu']), 'Nous vous appelons à ce moment-là.', $dem['statut'] === 'traite' || strtotime($dem['rappel_prevu']) <= time()];
    }
    $etapes[] = ['Demande traitée', ma_date_fr($dem['traite_at'] ?? null), $dem['traite_at'] ? 'Merci de votre confiance.' : '', $dem['statut'] === 'traite'];
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $dem ? 'Votre demande n° ' . (int) $dem['id'] . ' — Multiair France' : 'Suivi de demande — Multiair France' ?></title>
<style>
  :root { --ink:#1c2431; --muted:#5f6b7a; --line:#dde3ea; --bg:#f4f6f8; --card:#fff; --primary:#0f2f52; --ok:#2e7d5b; --accent:#e8720c; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--ink); font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif; }
  .wrap { max-width:560px; margin:0 auto; padding:20px 16px 40px; display:grid; gap:16px; }
  .marque { font-weight:800; color:var(--primary); letter-spacing:.05em; font-size:15px; }
  h1 { font-size:24px; margin:4px 0 0; }
  .objet { color:var(--muted); margin:2px 0 0; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:18px; }
  ol { list-style:none; margin:0; padding:0; display:grid; gap:0; }
  li { display:grid; grid-template-columns:28px 1fr; gap:12px; position:relative; padding-bottom:18px; }
  li:last-child { padding-bottom:0; }
  li::before { content:""; position:absolute; left:13px; top:26px; bottom:0; width:2px; background:var(--line); }
  li:last-child::before { display:none; }
  .pt { width:28px; height:28px; border-radius:50%; border:2px solid var(--line); background:#fff; display:grid; place-items:center; font-size:14px; color:#fff; }
  li.fait .pt { background:var(--ok); border-color:var(--ok); }
  li.fait::before { background:var(--ok); }
  .et b { display:block; }
  .et .quand { color:var(--muted); font-size:14px; }
  .et .txt { font-size:15px; }
  li:not(.fait) .et b { color:var(--muted); }
  .contact a { display:inline-block; margin-top:8px; background:var(--primary); color:#fff; padding:10px 16px; border-radius:8px; text-decoration:none; font-weight:700; }
  .pied { color:var(--muted); font-size:13px; }
  a:focus-visible { outline:3px solid #8ab4f8; outline-offset:2px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="marque">MULTIAIR FRANCE</div>
<?php if (!$dem): ?>
  <div class="card"><h1>Demande introuvable</h1><p>Ce lien de suivi n'est pas valide. Pour toute question, appelez notre standard.</p>
    <div class="contact"><a href="tel:<?= $e($standardTel) ?>">📞 <?= $e($standard) ?></a></div></div>
<?php else: ?>
  <div>
    <h1>Votre demande n° <?= (int) $dem['id'] ?></h1>
    <?php if ($objet !== ''): ?><p class="objet"><?= $e($objet) ?></p><?php endif; ?>
  </div>
  <div class="card">
    <ol>
    <?php foreach ($etapes as [$titre, $quand, $txt, $fait]): ?>
      <li class="<?= $fait ? 'fait' : '' ?>">
        <span class="pt" aria-hidden="true"><?= $fait ? '✓' : '' ?></span>
        <div class="et"><b><?= $e($titre) ?></b>
          <?php if ($quand !== ''): ?><span class="quand"><?= $e(ucfirst($quand)) ?></span><?php endif; ?>
          <?php if ($txt !== ''): ?><div class="txt"><?= $e($txt) ?></div><?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
    </ol>
  </div>
  <div class="card contact">Une question sur votre demande ? Appelez notre standard en indiquant le n° <?= (int) $dem['id'] ?>.
    <div><a href="tel:<?= $e($standardTel) ?>">📞 <?= $e($standard) ?></a></div></div>
  <p class="pied">Cette page est personnelle : elle ne montre que votre demande. Elle se met à jour à chaque étape.</p>
<?php endif; ?>
</div>
</body>
</html>
