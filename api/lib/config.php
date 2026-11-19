<?php
/**
 * Finds and loads the private config file, and prepares the storage folder.
 *
 * Search order:
 *   1. the path in the LISTING_CONFIG environment variable, if set (nothing else is tried then);
 *   2. private/listing-config.php in each folder above the app, at most 6 levels up,
 *      skipping public_html and anything inside it (Hostinger: domains/<domain>/private/);
 *   3. config.local.php in the app folder, only under PHP's built-in server (php -S).
 */
declare(strict_types=1);

if (!defined('LISTING_APP')) {
    http_response_code(404);
    exit;
}

function config_defaults(): array
{
    return [
        'anthropic_api_key' => '',
        'workspace_id' => '',
        'models' => ['best' => 'claude-sonnet-5-5', 'fast' => 'claude-haiku-5-5'],
        'max_tokens' => 1500,
        'enabled' => true,
        'limits' => ['ip_per_hour' => 30, 'ip_per_day' => 60, 'site_per_day' => 600],
        'ip_salt' => '',
        'storage_dir' => '',
        'anthropic_base_url' => 'https://api.anthropic.com',
    ];
}

/**
 * Loads the config once per request.
 * Returns ['cfg' => array, 'file' => string], or null when no usable config exists.
 */
function load_app(): ?array
{
    static $app = false;
    if ($app !== false) {
        return $app;
    }
    $appDir = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
    $file = find_config_file($appDir);
    if ($file === null) {
        return $app = null;
    }
    try {
        ob_start();
        $raw = (static function (string $f) {
            return include $f;
        })($file);
    } catch (Throwable $e) {
        error_log('listing-writer: the config file could not be read: ' . $e->getMessage());
        $raw = null;
    } finally {
        ob_end_clean();
    }
    if (!is_array($raw)) {
        error_log('listing-writer: the config file must return an array');
        return $app = null;
    }
    return $app = ['cfg' => normalize_config($raw), 'file' => $file];
}

function find_config_file(string $appDir): ?string
{
    $env = getenv('LISTING_CONFIG');
    if (!is_string($env) || $env === '') {
        $env = (string)($_SERVER['LISTING_CONFIG'] ?? $_SERVER['REDIRECT_LISTING_CONFIG'] ?? '');
    }
    if ($env !== '') {
        return readable_file($env) ? $env : null;
    }

    $dir = $appDir;
    for ($level = 1; $level <= 6; $level++) {
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
        if (in_public_html($dir)) {
            continue;
        }
        $file = rtrim($dir, '/\\') . '/private/listing-config.php';
        if (readable_file($file)) {
            return $file;
        }
    }

    if (PHP_SAPI === 'cli-server') {
        $local = $appDir . '/config.local.php';
        if (readable_file($local)) {
            return $local;
        }
    }
    return null;
}

/** True for public_html itself and every folder inside it. */
function in_public_html(string $dir): bool
{
    return in_array('public_html', explode('/', str_replace('\\', '/', $dir)), true);
}

/** is_file + is_readable without open_basedir warnings. */
function readable_file(string $path): bool
{
    return path_allowed($path) && @is_file($path) && @is_readable($path);
}

/** True when open_basedir (if set) allows the path. Errs on the strict side. */
function path_allowed(string $path): bool
{
    $limit = (string)ini_get('open_basedir');
    if ($limit === '') {
        return true;
    }
    $path = str_replace('\\', '/', $path);
    foreach (explode(PATH_SEPARATOR, $limit) as $base) {
        $base = trim($base);
        if ($base === '') {
            continue;
        }
        if ($base === '.') {
            $base = (string)getcwd();
        }
        $base = rtrim(str_replace('\\', '/', $base), '/');
        if ($base === '' || $path === $base || str_starts_with($path, $base . '/')) {
            return true;
        }
    }
    return false;
}

/** Fills in defaults and whitelists every value. */
function normalize_config(array $raw): array
{
    $d = config_defaults();
    $str = static fn($v, string $def = ''): string => is_string($v) ? trim($v) : $def;

    $models = $d['models'];
    if (is_array($raw['models'] ?? null)) {
        foreach (['best', 'fast'] as $q) {
            $m = $str($raw['models'][$q] ?? '');
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@-]{0,99}$/', $m)) {
                $models[$q] = $m;
            }
        }
    }

    $limits = $d['limits'];
    if (is_array($raw['limits'] ?? null)) {
        foreach ($limits as $k => $v) {
            if (isset($raw['limits'][$k]) && is_numeric($raw['limits'][$k])) {
                $limits[$k] = max(0, (int)$raw['limits'][$k]);
            }
        }
    }

    $key = $str($raw['anthropic_api_key'] ?? '');
    if (preg_match('/[\s\x00-\x1f]/', $key)) {
        $key = '';
    }
    $workspace = $str($raw['workspace_id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $workspace)) {
        $workspace = '';
    }
    $base = rtrim($str($raw['anthropic_base_url'] ?? '', $d['anthropic_base_url']), '/');
    if (!preg_match('#^https?://[^\s/?\#]+(/[^\s?\#]*)?$#i', $base)) {
        $base = $d['anthropic_base_url'];
    }
    $maxTokens = is_numeric($raw['max_tokens'] ?? null) ? (int)$raw['max_tokens'] : $d['max_tokens'];

    return [
        'anthropic_api_key' => $key,
        'workspace_id' => $workspace,
        'models' => $models,
        'max_tokens' => min(16000, max(256, $maxTokens)),
        'enabled' => array_key_exists('enabled', $raw) ? filter_var($raw['enabled'], FILTER_VALIDATE_BOOLEAN) : true,
        'limits' => $limits,
        'ip_salt' => $str($raw['ip_salt'] ?? ''),
        'storage_dir' => rtrim($str($raw['storage_dir'] ?? ''), '/\\'),
        'anthropic_base_url' => $base,
    ];
}

/** True when the demo can take requests. */
function app_ready(?array $app): bool
{
    return $app !== null && $app['cfg']['enabled'] && $app['cfg']['anthropic_api_key'] !== '' && function_exists('curl_init');
}

/** Secret for hashing visitor IPs: ip_salt, or one derived from the API key. */
function ip_salt(array $cfg): string
{
    return $cfg['ip_salt'] !== '' ? $cfg['ip_salt'] : hash_hmac('sha256', 'listing-writer visitor salt', $cfg['anthropic_api_key']);
}

/**
 * Folder for rate-limit counters and usage logs: storage_dir, or listing-storage next to the config file.
 * Created with 0700 on first use. Returns null when it can't be used.
 */
function storage_dir(array $app): ?string
{
    $dir = $app['cfg']['storage_dir'] !== '' ? $app['cfg']['storage_dir'] : dirname($app['file']) . '/listing-storage';
    if (!path_allowed($dir)) {
        error_log('listing-writer: storage folder is outside open_basedir: ' . $dir);
        return null;
    }
    if (!@is_dir($dir) && !@mkdir($dir, 0700, true) && !@is_dir($dir)) {
        error_log('listing-writer: cannot create the storage folder: ' . $dir);
        return null;
    }
    if (!@is_writable($dir)) {
        error_log('listing-writer: the storage folder is not writable: ' . $dir);
        return null;
    }
    $deny = $dir . '/.htaccess';
    if (!@is_file($deny)) {
        // In case the folder ever ends up inside a web root.
        @file_put_contents($deny, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    return $dir;
}
