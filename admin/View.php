<?php

declare(strict_types=1);

namespace Hostorio\Admin;

use Hostorio\Core\Config;

/**
 * Renders admin pages.
 *
 * Templates are plain PHP files under admin/views. No template engine, for the
 * same reason there is no framework: the package has to install by upload on
 * shared hosting with no Composer step.
 *
 * The one rule everything here exists to enforce: **escape on output**. Admin
 * pages display customer questions, ticket subjects and model answers, all of
 * which are attacker-influenced. Every template uses `$e()` rather than echoing
 * a variable directly.
 */
final class View
{
    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $file = __DIR__ . '/views/' . basename($template) . '.php';

        if (!is_file($file)) {
            return $this->layout('Not found', '<p>That page does not exist.</p>', $data);
        }

        $body = $this->capture($file, $data);

        return $this->layout((string) ($data['title'] ?? 'Admin'), $body, $data);
    }

    /**
     * Render a template without the surrounding layout — used for the login
     * screen and for CSV export.
     *
     * @param array<string, mixed> $data
     */
    public function renderBare(string $template, array $data = []): string
    {
        $file = __DIR__ . '/views/' . basename($template) . '.php';

        return is_file($file) ? $this->capture($file, $data) : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function capture(string $file, array $data): string
    {
        // Escaping helper, available to every template as $e().
        $e = static fn (mixed $value): string => htmlspecialchars(
            (string) (is_scalar($value) || $value === null ? $value : json_encode($value)),
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );

        $csrf = AdminAuth::csrfToken();
        $base = self::basePath();

        extract($data, EXTR_SKIP);

        ob_start();
        include $file;

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function layout(string $title, string $body, array $data = []): string
    {
        $e = static fn (mixed $v): string => htmlspecialchars(
            (string) $v,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );

        $base    = self::basePath();
        $active  = (string) ($data['active'] ?? '');
        $flash   = (string) ($data['flash'] ?? '');
        $error   = (string) ($data['error'] ?? '');
        $appName = (string) Config::get('app.name', 'Hostorio AI Chatbot');

        $nav = [
            ''              => 'Dashboard',
            'conversations' => 'Conversations',
            'knowledge'     => 'Knowledge base',
            'routing'       => 'Routing',
            'settings'      => 'Settings',
            'diagnostics'   => 'Diagnostics',
            'widget-demo'   => 'Widget demo',
        ];

        $links = '';

        foreach ($nav as $path => $label) {
            // Widget demo is not an admin page; link to the public demo instead
            if ($path === 'widget-demo') {
                $href = '/widget/demo.html';
                $class = '';
                $links .= '<a href="' . $e($href) . '" target="_blank"' . $class . '>' . $e($label) . '</a>';
            } else {
                $href = $base . ($path === '' ? '' : '/' . $path);
                $class = $active === $path ? ' class="on"' : '';
                $links .= '<a href="' . $e($href) . '"' . $class . '>' . $e($label) . '</a>';
            }
        }

        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="same-origin">
<title>' . $e($title) . ' — ' . $e($appName) . '</title>
<style>' . self::css() . '</style>
</head>
<body>
<header class="top">
  <div class="brand">' . $e($appName) . '</div>
  <nav>' . $links . '</nav>
  <form method="post" action="' . $e($base . '/logout') . '" class="logout">
    <input type="hidden" name="_csrf" value="' . $e(AdminAuth::csrfToken()) . '">
    <button type="submit">Sign out</button>
  </form>
</header>
<main>
' . ($error !== '' ? '<div class="alert error">' . $e($error) . '</div>' : '') . '
' . ($flash !== '' ? '<div class="alert ok">' . $e($flash) . '</div>' : '') . '
<h1>' . $e($title) . '</h1>
' . $body . '
</main>
</body>
</html>';
    }

    /**
     * URL prefix the panel is mounted at, honouring a subdirectory install.
     */
    public static function basePath(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        if (!str_ends_with($script, '.php')) {
            return '/admin';
        }

        $directory = rtrim(dirname($script), '/');

        if (str_ends_with($directory, '/public')) {
            $directory = substr($directory, 0, -strlen('/public'));
        }

        return ($directory === '/' ? '' : $directory) . '/admin';
    }

    private static function css(): string
    {
        return <<<'CSS'
*,*::before,*::after{box-sizing:border-box}
body{margin:0;font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
color:#111827;background:#f3f4f6}
a{color:#1d4ed8}
.top{display:flex;align-items:center;gap:20px;flex-wrap:wrap;background:#111827;color:#f9fafb;padding:12px 20px}
.top .brand{font-weight:700}
.top nav{display:flex;gap:4px;flex-wrap:wrap;flex:1}
.top nav a{color:#d1d5db;text-decoration:none;padding:7px 12px;border-radius:8px;font-size:14px}
.top nav a:hover{background:#1f2937;color:#fff}
.top nav a.on{background:#2563eb;color:#fff}
.top .logout button{background:transparent;border:1px solid #4b5563;color:#d1d5db;border-radius:8px;
padding:6px 12px;cursor:pointer;font:inherit;font-size:13px}
.top .logout button:hover{border-color:#9ca3af;color:#fff}
main{max-width:1100px;margin:0 auto;padding:24px 20px 64px}
h1{font-size:24px;margin:0 0 20px}
h2{font-size:17px;margin:28px 0 10px}
.alert{padding:11px 14px;border-radius:10px;margin-bottom:18px;font-size:14px}
.alert.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
.alert.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px;margin-bottom:18px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:20px}
.stat{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px}
.stat .label{font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em}
.stat .value{font-size:26px;font-weight:700;margin-top:4px}
.stat .sub{font-size:12px;color:#6b7280;margin-top:2px}
table{width:100%;border-collapse:collapse;background:#fff;font-size:14px}
.scroll{overflow-x:auto;border:1px solid #e5e7eb;border-radius:12px}
th,td{text-align:left;padding:10px 12px;border-bottom:1px solid #f3f4f6;vertical-align:top}
th{background:#f9fafb;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280}
tr:last-child td{border-bottom:0}
td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
.muted{color:#6b7280}
.tag{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;background:#e5e7eb;color:#374151}
.tag.ok{background:#d1fae5;color:#065f46}
.tag.warn{background:#fef3c7;color:#92400e}
.tag.bad{background:#fee2e2;color:#991b1b}
label{display:block;font-size:13px;font-weight:600;margin-bottom:5px}
input[type=text],input[type=password],input[type=number],input[type=search],select,textarea{
width:100%;font:inherit;padding:9px 11px;border:1px solid #d1d5db;border-radius:9px;background:#fff}
textarea{min-height:110px;resize:vertical}
.field{margin-bottom:15px}
.hint{font-size:12px;color:#6b7280;margin-top:4px}
.row{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
button.btn,a.btn{display:inline-block;font:inherit;font-size:14px;cursor:pointer;text-decoration:none;
background:#2563eb;color:#fff;border:0;border-radius:9px;padding:9px 16px}
button.btn:hover,a.btn:hover{background:#1d4ed8}
button.btn.secondary,a.btn.secondary{background:#fff;color:#374151;border:1px solid #d1d5db}
button.btn.secondary:hover{background:#f9fafb}
button.btn.danger{background:#dc2626}
button.btn.danger:hover{background:#b91c1c}
.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px}
.bubble{padding:10px 13px;border-radius:12px;margin-bottom:10px;max-width:80%;white-space:pre-wrap;
overflow-wrap:anywhere}
.bubble.user{background:#2563eb;color:#fff;margin-left:auto;border-bottom-right-radius:4px}
.bubble.assistant{background:#fff;border:1px solid #e5e7eb;border-bottom-left-radius:4px}
.meta{font-size:12px;color:#6b7280;margin:-6px 0 12px}
.bar{height:8px;background:#e5e7eb;border-radius:999px;overflow:hidden;margin-top:6px}
.bar span{display:block;height:100%;background:#2563eb}
.empty{padding:26px;text-align:center;color:#6b7280;background:#fff;border:1px dashed #d1d5db;border-radius:12px}
.login{max-width:380px;margin:12vh auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:26px}
.login h1{font-size:20px;text-align:center}
code{background:#f3f4f6;padding:2px 5px;border-radius:4px;font-size:13px}
CSS;
    }
}
