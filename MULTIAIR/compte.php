<?php
// MULTIAIR — choisir son mot de passe, depuis le lien d'invitation ou de mot de passe oublié.
// Le lien vaut 7 jours et ne sert qu'une fois ; seule son empreinte est gardée en base.
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$db = ma_db();
$jeton = (string) ($_POST['j'] ?? $_GET['j'] ?? '');
$c = null;
if (preg_match('/^[a-f0-9]{32}$/', $jeton)) {
    $st = $db->prepare("SELECT * FROM rep_contacts WHERE mdp_jeton = ? AND mdp_jeton_exp > ? AND actif = 1
        AND acces IN ('admin','sav','commerce','finance')");
    $st->execute([hash('sha256', $jeton), ma_now()]);
    $c = $st->fetch() ?: null;
}
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$erreur = '';
if ($c && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = (string) ($_POST['mdp'] ?? '');
    $p2 = (string) ($_POST['mdp2'] ?? '');
    if (mb_strlen($p1) < 8) {
        $erreur = 'Le mot de passe doit faire au moins 8 caractères.';
    } elseif ($p1 !== $p2) {
        $erreur = 'Les deux mots de passe ne sont pas identiques.';
    } else {
        $db->prepare('UPDATE rep_contacts SET mdp_hash = ?, mdp_jeton = NULL, mdp_jeton_exp = NULL, derniere_connexion = ? WHERE id = ?')
            ->execute([password_hash($p1, PASSWORD_DEFAULT), ma_now(), $c['id']]);
        $db->prepare('INSERT INTO executions_log(scenario, date, statut, type_evenement, resume) VALUES (?,?,?,?,?)')
            ->execute(['plateforme', ma_now(), 'ok', 'compte_mot_de_passe', $c['nom'] . ' a choisi son mot de passe']);
        ma_ouvrir_session(['id' => (int) $c['id'], 'nom' => (string) $c['nom'], 'email' => (string) $c['email'], 'acces' => (string) $c['acces']]);
        header('Location: index.php', true, 303);
        exit;
    }
}
$prenom = $c ? (explode(' ', trim((string) $c['nom']))[0] ?? '') : '';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Mon accès — Multiair</title>
<link rel="stylesheet" href="assets/style.css?v=<?= MA_VERSION ?>">
</head>
<body class="page-simple">
<main class="auth">
  <div class="auth-marque">MULTIAIR</div>
<?php if (!$c): ?>
  <h1>Lien expiré</h1>
  <p class="auth-sous">Ce lien n'est plus valable (il sert une seule fois, pendant 7 jours). Sur la page de connexion, cliquez sur « Mot de passe oublié » pour en recevoir un nouveau.</p>
  <a class="btn primary block" href="index.php">Aller à la connexion</a>
<?php else: ?>
  <h1>Bonjour <?= $e($prenom) ?></h1>
  <p class="auth-sous">Choisissez votre mot de passe. Votre identifiant est <b><?= $e($c['email']) ?></b>.</p>
  <form method="post" class="auth-form">
    <input type="hidden" name="j" value="<?= $e($jeton) ?>">
    <?php if ($erreur !== ''): ?><div class="msg err" role="alert"><?= $e($erreur) ?></div><?php endif; ?>
    <label class="field"><span>Nouveau mot de passe</span><input type="password" name="mdp" minlength="8" required autocomplete="new-password" autofocus></label>
    <label class="field"><span>Le même, une seconde fois</span><input type="password" name="mdp2" minlength="8" required autocomplete="new-password"></label>
    <p class="hint">Au moins 8 caractères.</p>
    <button class="btn primary block big" type="submit">Enregistrer et me connecter</button>
  </form>
<?php endif; ?>
</main>
</body>
</html>
