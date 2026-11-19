<?php
/**
 * Rate limits and the usage log, stored as small files guarded by flock.
 * One request = one language of one product.
 */
declare(strict_types=1);

if (!defined('LISTING_APP')) {
    http_response_code(404);
    exit;
}

/**
 * Counts one request against the visitor's hourly and daily limits (sliding windows)
 * and the site-wide daily limit (resets at midnight UTC).
 *
 * Returns 0 when the request may go ahead, the seconds to wait when a limit is reached,
 * or -1 when the storage folder can't be used.
 */
function rate_take(string $dir, string $visitor, array $limits, ?int $now = null): int
{
    $now = $now ?? time();
    if (random_int(1, 50) === 1) {
        storage_cleanup($dir, $now);
    }
    $perHour = (int)$limits['ip_per_hour'];
    $perDay = (int)$limits['ip_per_day'];
    $siteDay = (int)$limits['site_per_day'];

    // Lock order is always visitor file, then site file, so requests can't deadlock.
    $ipFile = @fopen($dir . '/rl-ip-' . $visitor . '.json', 'c+');
    if ($ipFile === false) {
        return -1;
    }
    if (!flock($ipFile, LOCK_EX)) {
        fclose($ipFile);
        return -1;
    }
    try {
        $saved = json_decode((string)stream_get_contents($ipFile), true);
        $stamps = [];
        foreach (is_array($saved) ? $saved : [] as $t) {
            if (is_int($t) && $t > $now - 86400 && $t <= $now) {
                $stamps[] = $t;
            }
        }
        sort($stamps);

        $wait = 0;
        if ($perHour > 0) {
            $lastHour = array_values(array_filter($stamps, static fn(int $t): bool => $t > $now - 3600));
            if (count($lastHour) >= $perHour) {
                $wait = max($wait, $lastHour[count($lastHour) - $perHour] + 3600 - $now);
            }
        }
        if ($perDay > 0 && count($stamps) >= $perDay) {
            $wait = max($wait, $stamps[count($stamps) - $perDay] + 86400 - $now);
        }
        if ($wait > 0) {
            return max(1, $wait);
        }

        if ($siteDay > 0) {
            $siteFile = @fopen($dir . '/rl-site-' . gmdate('Y-m-d', $now) . '.txt', 'c+');
            if ($siteFile === false) {
                return -1;
            }
            if (!flock($siteFile, LOCK_EX)) {
                fclose($siteFile);
                return -1;
            }
            $count = (int)stream_get_contents($siteFile);
            if ($count >= $siteDay) {
                flock($siteFile, LOCK_UN);
                fclose($siteFile);
                return max(1, 86400 - $now % 86400);
            }
            write_locked($siteFile, (string)($count + 1));
            flock($siteFile, LOCK_UN);
            fclose($siteFile);
        }

        $stamps[] = $now;
        write_locked($ipFile, (string)json_encode($stamps));
        return 0;
    } finally {
        flock($ipFile, LOCK_UN);
        fclose($ipFile);
    }
}

/** Replaces the whole content of a file we hold a lock on. */
function write_locked($handle, string $data): void
{
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, $data);
    fflush($handle);
}

/** Deletes counters nobody needs any more and usage logs older than about 13 months. */
function storage_cleanup(string $dir, int $now): void
{
    $rules = ['/rl-ip-*.json' => 2 * 86400, '/rl-site-*.txt' => 3 * 86400, '/usage-*.csv' => 400 * 86400];
    foreach ($rules as $pattern => $maxAge) {
        foreach (glob($dir . $pattern) ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $now - $maxAge) {
                @unlink($file);
            }
        }
    }
}

/**
 * Appends one line to usage-YYYY-MM.csv: time, model, language, input and output tokens, status.
 * No photos, no notes and no IPs are ever written.
 */
function usage_log(string $dir, string $model, string $lang, int $in, int $out, string $status): void
{
    $file = $dir . '/usage-' . gmdate('Y-m') . '.csv';
    $h = @fopen($file, 'a');
    if ($h === false) {
        return;
    }
    if (flock($h, LOCK_EX)) {
        $stat = fstat($h);
        if ($stat !== false && $stat['size'] === 0) {
            fwrite($h, "time,model,language,input_tokens,output_tokens,status\n");
        }
        $safe = static fn(string $v): string => (string)preg_replace('/[^A-Za-z0-9._:-]/', '', $v);
        fwrite($h, implode(',', [gmdate('Y-m-d\TH:i:s\Z'), $safe($model), $safe($lang), max(0, $in), max(0, $out), $safe($status)]) . "\n");
        fflush($h);
        flock($h, LOCK_UN);
    }
    fclose($h);
}
