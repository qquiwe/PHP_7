<?php

function cacheFilePath(string $key): string
{
    return sys_get_temp_dir() . '/practicum7_cache_' . md5($key) . '.json';
}

function cachedQuery(PDO $pdo, string $key, string $sql, int $ttlSeconds = 45): array
{
    $file = cacheFilePath($key);

    if (is_file($file) && (time() - filemtime($file)) < $ttlSeconds) {
        $cached = json_decode(file_get_contents($file), true);
        if (is_array($cached)) {
            $cached['_cache'] = 'hit';
            return $cached;
        }
    }

    $stmt = $pdo->query($sql);
    $result = $stmt->fetch();
    $result = is_array($result) ? $result : [];

    file_put_contents($file, json_encode($result));

    $result['_cache'] = 'miss';
    return $result;
}

function invalidateCache(string $key): void
{
    $file = cacheFilePath($key);
    if (is_file($file)) {
        unlink($file);
    }
}
