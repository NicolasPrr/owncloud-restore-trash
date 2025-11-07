<?php
/**
 * ownCloud restore from trash - folders first, then files.
 * - Creates missing parent directories (MKCOL)
 * - Retries on 409/423/5xx with small backoff
 * - Reuses a single cURL handle (Keep-Alive)
 * - Optional filters:
 *     --include-prefix="path/prefix/"
 *     --index-from=0 --index-to=1999  (inclusive)
 *
 * Requires Sabre\Xml via composer (as in the original project).
 */

class RestoreTrash
{
    private string $uri;
    private string $username;
    private string $password;
    private \Sabre\Xml\Service $sabreService;
    private \DateTime $restoreDate;

    /** @var resource cURL handle */
    private $ch;

    /** @var array<int,array> */
    private array $trashbinData = [];

    private ?string $includePrefix = null; // normalized "foo/bar/"
    private int $indexFrom = 0;
    private ?int $indexTo = null; // inclusive

    public function __construct(string $uri, string $username, string $password, string $restoreDate)
    {
        $this->sabreService = new \Sabre\Xml\Service();
        $this->uri = rtrim($uri, '/');
        $this->username = $username;
        $this->password = $password;
        $this->restoreDate = new \DateTime($restoreDate);

        // cURL handle (Keep-Alive)
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [
            CURLOPT_FAILONERROR    => 0, // queremos leer el código (409/423/etc)
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_USERPWD        => "{$this->username}:{$this->password}",
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_TIMEOUT        => 120,
            // Headers base (se agregan/reenemplazan por request)
            CURLOPT_HTTPHEADER     => ['Connection: Keep-Alive','Accept: */*'],
        ]);
    }

    public function setIncludePrefix(?string $p): void
    {
        if ($p === null || $p === '') { $this->includePrefix = null; return; }
        $p = ltrim($p, '/');
        $this->includePrefix = (substr($p, -1) === '/') ? $p : ($p . '/');
    }

    public function setIndexRange(int $from = 0, ?int $to = null): void
    {
        $this->indexFrom = max(0, $from);
        $this->indexTo = ($to !== null && $to >= 0) ? $to : null;
    }

    public function run(): void
    {
        echo "Collecting items to restore…\n";
        $this->collectTrashbinData();
        echo sprintf("Found %d candidate items (after date & filters)\n", count($this->trashbinData));
        $this->restoreTrashbinData();
    }

    private function dav(string $method, string $url, array $headers = [], ?string $body = null, ?int $depth = null): array
    {
        $h = [];
        // base headers (Connection/Accept) ya están desde curl_setopt_array inicial
        foreach ($headers as $k => $v) $h[] = "$k: $v";
        if ($depth !== null) $h[] = "Depth: $depth";
        if ($body !== null && !array_key_exists('Content-Type', $headers)) {
            $h[] = "Content-Type: application/xml; charset=UTF-8";
        }

        curl_setopt_array($this->ch, [
            CURLOPT_URL           => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER    => $h,
            CURLOPT_POSTFIELDS    => $body ?? '',
        ]);

        $resp   = curl_exec($this->ch);
        $status = (int) curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE);

        if ($resp === false) {
            $err = curl_error($this->ch);
            throw new \RuntimeException("cURL error: $err");
        }
        return [$status, $resp];
    }

    private function propfindBody(): string
    {
        return '<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:prop>
    <oc:trashbin-original-filename />
    <oc:trashbin-original-location />
    <oc:trashbin-delete-datetime />
    <d:resourcetype />
    <d:getcontenttype />
  </d:prop>
</d:propfind>';
    }

    private function encodePathSegments(string $path): string
    {
        $parts = array_filter(explode('/', $path), fn($s) => $s !== '');
        return implode('/', array_map('rawurlencode', $parts));
    }

    private function pathDepth(string $p): int
    {
        return substr_count(trim($p, '/'), '/');
    }

    private function collectTrashbinData(): void
    {
        $trashRoot = $this->uri . "/remote.php/dav/trash-bin/" . rawurlencode($this->username) . "/";

        [$status, $response] = $this->dav("PROPFIND", $trashRoot, ['charset' => 'UTF-8'], $this->propfindBody(), 1);
        if ($status >= 400) {
            throw new \RuntimeException("PROPFIND trash-bin failed with HTTP $status");
        }

        $data = $this->sabreService->parse($response);
        if (!empty($data)) array_shift($data); // descartar root collection

        foreach ($data as $entry) {
            // href del ítem en la papelera
            $remoteUrl = $entry['value'][0]['value'] ?? null;
            if (!$remoteUrl) continue;

            $props = $entry['value'][1]['value'][0]['value'] ?? [];
            $originalFilename = null;
            $originalLocation = null;
            $deleteDateTime   = null;
            $isDir            = false;

            foreach ($props as $p) {
                $k = $p['name'] ?? '';
                if (strpos($k, 'trashbin-original-filename') !== false) {
                    $originalFilename = $p['value'] ?? null;
                } elseif (strpos($k, 'trashbin-original-location') !== false) {
                    $originalLocation = $p['value'] ?? null;
                } elseif (strpos($k, 'trashbin-delete-datetime') !== false) {
                    $deleteDateTime = $p['value'] ?? null; // ISO string
                } elseif (strpos($k, 'resourcetype') !== false) {
                    $val = $p['value'] ?? [];
                    if (is_array($val)) {
                        foreach ($val as $vv) {
                            if (($vv['name'] ?? '') === '{DAV:}collection') { $isDir = true; break; }
                        }
                    }
                }
            }

            if (!$originalLocation || !$deleteDateTime) continue;

            $deletedAt = new \DateTime($deleteDateTime);
            if ($deletedAt < $this->restoreDate) continue;

            // include-prefix filter
            $destPath = ltrim($originalLocation, '/');
            if ($this->includePrefix && strpos($destPath, $this->includePrefix) !== 0) {
                continue;
            }

            $this->trashbinData[] = [
                'remoteUrl' => $remoteUrl,                   // ruta completa del ítem en trash-bin
                'destPath'  => $destPath,                    // relativo a files/<user>/
                'isDir'     => (bool)$isDir,
                'deletedAt' => $deletedAt,
                'name'      => $originalFilename ?: basename($originalLocation),
            ];
        }
    }

    private function ensureParents(string $destPath): void
    {
        $parts = array_filter(explode('/', $destPath));
        if (count($parts) <= 1) return;

        $base = $this->uri . '/remote.php/dav/files/' . rawurlencode($this->username);
        $cur = [];
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $cur[] = $parts[$i];
            $url = $base . '/' . $this->encodePathSegments(implode('/', $cur));
            // ¿existe?
            [$st, ] = $this->dav('PROPFIND', $url, [], $this->propfindBody(), 0);
            if ($st === 404 || $st === 405) {
                // crear
                [$mk, ] = $this->dav('MKCOL', $url);
                // ignorar 405/409 si otra corrida lo creó
            }
        }
    }

    private function moveFromTrash(string $remoteUrl, string $destPath): array
    {
        $src = $this->uri . $remoteUrl;
        $dst = $this->uri . '/remote.php/dav/files/' . rawurlencode($this->username) . '/' . $this->encodePathSegments($destPath);
        $headers = [
            'Overwrite'   => 'F', // no sobrescribir
            'Destination' => $dst,
        ];
        return $this->dav('MOVE', $src, $headers);
    }

    private function restoreList(array $items, string $label): array
    {
        $ok = 0; $fail = 0;

        foreach ($items as $it) {
            // asegurar padres (para archivos y subcarpetas)
            $this->ensureParents($it['destPath']);

            $max = 3;
            $try = 0;
            $last = null;
            $success = false;

            while ($try < $max) {
                [$st, ] = $this->moveFromTrash($it['remoteUrl'], $it['destPath']);
                $last = $st;
                if ($st >= 200 && $st < 300) {
                    $success = true;
                    $ok++;
                    echo "[OK] {$label} → {$it['destPath']}\n";
                    break;
                }
                $try++;
                echo "[WARN] HTTP {$st} {$label} → {$it['destPath']} (attempt {$try})\n";
                if (in_array($st, [409, 423, 502, 503], true)) {
                    usleep(300000); // 300 ms
                } else {
                    break;
                }
            }

            if (!$success) {
                $fail++;
                $code = ($last === null ? 'unknown' : $last);
                echo "[FAIL] {$label} → {$it['destPath']} (last HTTP {$code})\n";
            }
        }

        return [$ok, $fail];
    }

    private function restoreTrashbinData(): void
    {
        // separar y ordenar por profundidad (padres → hijos)
        $dirs  = array_values(array_filter($this->trashbinData, fn($x) => $x['isDir']));
        $files = array_values(array_filter($this->trashbinData, fn($x) => !$x['isDir']));

        usort($dirs,  fn($a,$b) => $this->pathDepth($a['destPath']) <=> $this->pathDepth($b['destPath']));
        usort($files, fn($a,$b) => $this->pathDepth($a['destPath']) <=> $this->pathDepth($b['destPath']));

        // Lista final: dirs primero, luego files
        $todo = array_merge($dirs, $files);

        // Orden estable adicional por ruta (hace determinísticos los rangos)
        usort($todo, fn($a,$b) => strcmp($a['destPath'], $b['destPath']));

        // Aplicar rango
        $total = count($todo);
        $from  = $this->indexFrom;
        $to    = ($this->indexTo === null) ? ($total - 1) : min($this->indexTo, $total - 1);

        if ($from >= $total) {
            echo "Range out of bounds: index-from=$from total=$total\n";
            return;
        }
        $len = max(0, $to - $from + 1);
        $chunk = array_slice($todo, $from, $len);

        echo "Total pending: {$total} | Executing range [{$from}..{$to}] (count={$len})\n";

        // Restaurar el chunk (ya mezclado DIR/FILE pero con padres creados)
        [$ok, $fail] = $this->restoreList($chunk, 'ITEM');
        echo sprintf("Range [%d..%d] done. OK=%d FAIL=%d\n", $from, $to, $ok, $fail);
    }
}

/** -------- CLI bootstrap -------- */
require_once __DIR__ . '/vendor/autoload.php';

function parse_args(array $argv): array {
    $args = [
        'url' => null,
        'username' => null,
        'password' => null,
        'date' => null,
        'include_prefix' => null,
        'index_from' => 0,
        'index_to' => null,
    ];
    foreach ($argv as $i => $a) {
        if ($i === 0) continue;
        if      (preg_match('/^--url=(.+)$/', $a, $m))          $args['url'] = rtrim($m[1], '/');
        elseif  (preg_match('/^--username=(.+)$/', $a, $m))     $args['username'] = $m[1];
        elseif  (preg_match('/^--password=(.+)$/', $a, $m))     $args['password'] = $m[1];
        elseif  (preg_match('/^--date=(.+)$/', $a, $m))         $args['date'] = $m[1];
        elseif  (preg_match('/^--include-prefix=(.+)$/', $a, $m)) $args['include_prefix'] = $m[1];
        elseif  (preg_match('/^--index-from=(\d+)$/', $a, $m))  $args['index_from'] = (int)$m[1];
        elseif  (preg_match('/^--index-to=(\d+)$/', $a, $m))    $args['index_to'] = (int)$m[1];
    }
    foreach (['url','username','password','date'] as $k) {
        if (!$args[$k]) {
            fwrite(STDERR, "Missing --$k\n");
            exit(1);
        }
    }
    // Validate date
    $ts = strtotime($args['date']);
    if ($ts === false) {
        fwrite(STDERR, "Invalid --date. Use YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS\n");
        exit(1);
    }
    return $args;
}

$args = parse_args($argv);

$rt = new RestoreTrash($args['url'], $args['username'], $args['password'], $args['date']);
$rt->setIncludePrefix($args['include_prefix'] ?? null);
$rt->setIndexRange($args['index_from'] ?? 0, $args['index_to'] ?? null);
$rt->run();
