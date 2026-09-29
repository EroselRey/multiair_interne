<?php
// MULTIAIR — plateforme des demandes clients (connexion + application)
declare(strict_types=1);
require __DIR__ . '/lib.php';
$user = ma_user();
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$admin = ($user['acces'] ?? '') === 'admin';
$initiales = '';
if ($user) {
    foreach (preg_split('/\s+/', trim((string) $user['nom'])) ?: [] as $mot) {
        $initiales .= mb_strtoupper(mb_substr($mot, 0, 1));
    }
    $initiales = mb_substr($initiales, 0, 2) ?: 'A';
}
$ic = [
    'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
    'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    'chart' => '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/>',
    'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    'route' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/>',
    'send' => '<path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/>',
    'cpu' => '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M9 9h6v6H9zM9 1v3M15 1v3M9 20v3M15 20v3M20 9h3M20 14h3M1 9h3M1 14h3"/>',
    'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
];
$icone = fn($n) => '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $ic[$n] . '</svg>';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Multiair — Demandes clients</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= MA_VERSION ?>">
</head>
<body class="<?= $user ? '' : 'page-simple' ?>">
<?php if (!$user): ?>
<main class="auth" id="auth">
  <div class="auth-marque">MULTIAIR</div>
  <div id="vueConnexion">
    <h1>Connexion</h1>
    <p class="auth-sous">Plateforme des demandes clients.</p>
    <form id="loginForm" class="auth-form" autocomplete="on">
      <label class="field"><span>Adresse e-mail</span><input name="login" type="text" inputmode="email" required autocomplete="username" autofocus></label>
      <label class="field"><span>Mot de passe</span><input name="password" type="password" required autocomplete="current-password"></label>
      <div id="loginMsg" aria-live="polite"></div>
      <button class="btn primary block big" type="submit">Se connecter</button>
    </form>
    <button class="link lien" id="oubliBtn" type="button">Mot de passe oublié ?</button>
  </div>
  <div id="vueOubli" hidden>
    <h1>Mot de passe oublié</h1>
    <p class="auth-sous">Indiquez votre adresse e-mail : vous recevrez un lien pour choisir un nouveau mot de passe.</p>
    <form id="oubliForm" class="auth-form">
      <label class="field"><span>Adresse e-mail</span><input name="email" type="email" required autocomplete="username"></label>
      <div id="oubliMsg" aria-live="polite"></div>
      <button class="btn primary block big" type="submit">Recevoir le lien</button>
    </form>
    <button class="link lien" id="retourBtn" type="button">Retour à la connexion</button>
  </div>
</main>
<script>
(() => {
  const $ = (s) => document.querySelector(s);
  const f = $('#loginForm'), m = $('#loginMsg');
  const post = (r, body) => fetch('api.php?r=' + r, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)})
    .then((x) => x.json()).catch(() => ({}));
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    m.innerHTML = '';
    const j = await post('auth/login', {login: f.login.value.trim(), password: f.password.value});
    if (j.ok) location.reload(); else m.innerHTML = '<div class="msg err">' + (j.erreur || 'Connexion impossible') + '</div>';
  });
  const vue = (oubli) => { $('#vueConnexion').hidden = oubli; $('#vueOubli').hidden = !oubli; };
  $('#oubliBtn').addEventListener('click', () => { vue(true); $('#oubliForm').email.value = f.login.value.includes('@') ? f.login.value : ''; });
  $('#retourBtn').addEventListener('click', () => vue(false));
  $('#oubliForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const j = await post('auth/recover', {email: e.target.email.value.trim()});
    $('#oubliMsg').innerHTML = '<div class="msg ' + (j.ok ? 'ok' : 'err') + '">' + (j.message || j.erreur || 'Erreur') + '</div>';
  });
})();
</script>
<?php else: ?>
<div class="app" id="app">
  <header class="topmob">
    <button type="button" id="menuBtn" aria-label="Ouvrir le menu"><?= $icone('menu') ?></button>
    <b>MULTIAIR</b>
  </header>
  <nav class="side" id="side" aria-label="Menu principal">
    <div class="logo">MULTIAIR</div>
    <button class="nav" data-page="a-traiter"><?= $icone('inbox') ?><span>À traiter</span><span class="n zero" data-badge="a-traiter">0</span></button>
    <button class="nav" data-page="demandes"><?= $icone('list') ?><span>Demandes</span></button>
    <button class="nav" data-page="stats"><?= $icone('chart') ?><span>Statistiques</span></button>
<?php if ($admin): ?>
    <div class="grp">RÉGLAGES</div>
    <button class="nav sub" data-page="equipe"><?= $icone('users') ?><span>Équipe et accès</span></button>
    <button class="nav sub" data-page="routage"><?= $icone('route') ?><span>Qui reçoit quoi</span></button>
    <button class="nav sub" data-page="messages"><?= $icone('send') ?><span>Messages aux clients</span></button>
    <div class="grp">AUTRES AUTOMATISATIONS</div>
    <button class="nav sub" data-page="overview"><?= $icone('cpu') ?><span>État des scénarios</span></button>
    <button class="nav sub" data-page="repondeur"><span style="width:18px"></span><span>Répondeur (appels)</span></button>
    <button class="nav sub" data-page="chatbot"><span style="width:18px"></span><span>Chatbot du site</span></button>
    <button class="nav sub" data-page="adv"><span style="width:18px"></span><span>Claire ADV (e-mails)</span></button>
    <button class="nav sub" data-page="cso"><span style="width:18px"></span><span>Devis CSO</span></button>
    <button class="nav sub" data-page="cee"><span style="width:18px"></span><span>Prime CEE</span></button>
<?php endif; ?>
    <div class="spacer"></div>
    <div class="moi">
      <div class="avatar" aria-hidden="true"><?= $e($initiales) ?></div>
      <div class="qui"><b><?= $e($user['nom']) ?></b><span><?= $e(MA_ACCES[$user['acces']] ?? '') ?></span></div>
      <button type="button" id="logoutBtn" title="Se déconnecter" aria-label="Se déconnecter"><?= $icone('logout') ?></button>
    </div>
  </nav>
  <div class="col">
    <button type="button" class="urgent-bar" id="urgentBar" hidden></button>
    <main id="main"><div class="loading">Chargement…</div></main>
  </div>
</div>
<div class="drawer-bg" id="drawerBg"></div>
<aside class="drawer" id="drawer" aria-labelledby="drawerTitle">
  <div class="dh"><h3 id="drawerTitle"></h3><button class="btn small" id="drawerClose">Fermer</button></div>
  <div class="db" id="drawerBody"></div>
  <div class="df" id="drawerFoot"></div>
</aside>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script>window.MA = <?= json_encode(['user' => $user, 'admin' => $admin, 'version' => MA_VERSION, 'acces' => MA_ACCES],
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="assets/chart.umd.js?v=4.4.1"></script>
<script src="assets/app.js?v=<?= MA_VERSION ?>"></script>
<?php endif; ?>
</body>
</html>
