<?php
// MULTIAIR — page principale (login + tableau de bord des scénarios Make)
declare(strict_types=1);
require __DIR__ . '/lib.php';
$logged = ma_is_logged();
$login = $logged ? ($_SESSION['ma_login'] ?? 'admin') : null;
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>MULTIAIR — Scénarios</title>
<link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body class="<?= $logged ? '' : 'page-simple' ?>">
<?php if (!$logged): ?>
<div class="box login">
  <h1>MULTIAIR</h1>
  <p class="sub">Tableau de bord des scénarios Make</p>
  <form id="loginForm" autocomplete="on">
    <div class="field"><label>Identifiant</label><input name="login" value="admin" required autocomplete="username"></div>
    <div class="field"><label>Mot de passe</label><input name="password" type="password" required autocomplete="current-password" autofocus></div>
    <div id="loginMsg"></div>
    <button class="btn primary block" type="submit">Se connecter</button>
  </form>
  <p style="margin:14px 0 0;font-size:12.5px"><button class="link" id="recoverBtn" type="button">Mot de passe oublié ?</button></p>
</div>
<script>
const f = document.getElementById('loginForm'), m = document.getElementById('loginMsg');
f.addEventListener('submit', async (e) => {
  e.preventDefault();
  m.innerHTML = '';
  const r = await fetch('api.php?r=auth/login', {method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({login: f.login.value, password: f.password.value})});
  const j = await r.json().catch(() => ({}));
  if (j.ok) location.reload(); else m.innerHTML = '<div class="msg err">' + (j.erreur || 'Erreur') + '</div>';
});
document.getElementById('recoverBtn').addEventListener('click', async () => {
  m.innerHTML = '<div class="msg">Envoi en cours…</div>';
  const r = await fetch('api.php?r=auth/recover', {method: 'POST'});
  const j = await r.json().catch(() => ({}));
  m.innerHTML = '<div class="msg ' + (j.ok ? 'ok' : 'err') + '">' + (j.message || j.erreur || 'Erreur') + '</div>';
});
</script>
<?php else: ?>
<header class="topbar">
  <div class="brand">MULTIAIR <span>Scénarios Make · portail interne · v<?= MA_VERSION ?></span></div>
  <div class="spacer"></div>
  <span class="user">Connecté : <?= htmlspecialchars((string) $login) ?></span>
  <button class="btn small" id="refreshBtn" title="Recharger les données">↻ Actualiser</button>
  <button class="btn small" id="logoutBtn">Déconnexion</button>
</header>
<nav class="tabs" id="tabs">
  <button data-tab="overview" class="active">Vue d'ensemble</button>
  <button data-tab="repondeur">Répondeur IA <span class="badge zero" data-badge="repondeur">0</span></button>
  <button data-tab="chatbot">Chatbot Claire <span class="badge zero" data-badge="chatbot">0</span></button>
  <button data-tab="adv">Claire ADV <span class="badge zero" data-badge="adv">0</span></button>
  <button data-tab="cso">CSO devis <span class="badge zero" data-badge="cso">0</span></button>
  <button data-tab="cee">Prime CEE <span class="badge zero" data-badge="cee">0</span></button>
</nav>
<main id="main"><div class="loading">Chargement…</div></main>
<div class="drawer-bg" id="drawerBg"></div>
<aside class="drawer" id="drawer">
  <div class="dh"><h3 id="drawerTitle"></h3><button class="btn small" id="drawerClose">✕ Fermer</button></div>
  <div class="db" id="drawerBody"></div>
  <div class="df" id="drawerFoot"></div>
</aside>
<div class="toast" id="toast"></div>
<script src="assets/chart.umd.js?v=4.4.1"></script>
<script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
<?php endif; ?>
</body>
</html>
