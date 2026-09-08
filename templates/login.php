<?php
declare(strict_types=1) ?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= $escape($csrf) ?>">
    <title>Anmelden · photobutler</title>
    <link rel="icon" type="image/svg+xml" href="?asset=favicon.svg">
    <script src="?asset=preferences.js"></script>
    <link rel="stylesheet" href="?asset=app.css">
    <script type="module" src="?asset=navigation.js"></script>
</head>
<body class="login-page">
    <p id="navigation-message" class="navigation-message" role="alert" hidden></p>
    <main class="login-card">
        <span class="brand-icon" aria-hidden="true">▧</span>
        <h1>photobutler</h1>
        <form id="login-form" method="post" action="<?= $escape($basePath) ?>index.php/login">
            <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
            <label for="username">Benutzername</label>
            <input id="username" type="text" name="username" autocomplete="username" required autofocus>
            <label for="password">Passwort</label>
            <input id="password" type="password" name="password" autocomplete="current-password" required>
            <p id="login-error" class="error" role="alert"></p>
            <button class="primary" type="submit">Anmelden <span aria-hidden="true">↗</span></button>
        </form>
    </main>
</body>
</html>
