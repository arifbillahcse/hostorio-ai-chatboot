<?php
/**
 * Login screen. Rendered without the panel layout — there is no navigation to
 * offer someone who is not signed in.
 *
 * @var callable $e escaping helper
 * @var string $csrf
 * @var string $base
 * @var string $error
 * @var bool $configured
 */
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="same-origin">
<meta name="robots" content="noindex, nofollow">
<title>Sign in</title>
<style>
*,*::before,*::after{box-sizing:border-box}
body{margin:0;font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
color:#111827;background:#f3f4f6}
.login{max-width:380px;margin:12vh auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:26px}
h1{font-size:20px;text-align:center;margin:0 0 20px}
label{display:block;font-size:13px;font-weight:600;margin-bottom:5px}
input[type=password]{width:100%;font:inherit;padding:10px 12px;border:1px solid #d1d5db;border-radius:9px}
button{width:100%;margin-top:16px;font:inherit;cursor:pointer;background:#2563eb;color:#fff;border:0;
border-radius:9px;padding:11px}
button:hover{background:#1d4ed8}
.alert{padding:11px 14px;border-radius:10px;margin-bottom:16px;font-size:14px;
background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.hint{font-size:12px;color:#6b7280;margin-top:14px;text-align:center}
code{background:#f3f4f6;padding:2px 5px;border-radius:4px;font-size:12px}
</style>
</head>
<body>
<div class="login">
    <h1>Chatbot admin</h1>

    <?php if ($error !== ''): ?>
        <div class="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <?php if (!$configured): ?>
        <div class="alert">
            No admin password is set. Generate one and add it to your <code>.env</code>:<br><br>
            <code>php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"</code>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= $e($base . '/login') ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" autofocus required>
        <button type="submit">Sign in</button>
    </form>

    <p class="hint">Five failed attempts locks this address out for 15 minutes.</p>
</div>
</body>
</html>
