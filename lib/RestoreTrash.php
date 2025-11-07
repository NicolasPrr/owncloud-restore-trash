<?php
/**
 * ownCloud restore from trash - folders first, then files.
 * - Crea padres ausentes (MKCOL) para reducir 409
 * - Reintenta en 409/423/5xx con backoff
 * - Reutiliza un único cURL (Keep-Alive) para acelerar
 * - Sharding estable por hash de ruta: --shard=N --shards=K
 * - Rangos por índice dentro del shard: --index-from= --index-to=
 *
 * Uso típico:
 * php restore.php \
 *   --url="http://owncloud:8080" \
 *   --username="admin" \
 *   --password="APP_PASSWORD" \
 *   --date="2025-11-06T00:00:00" \
 *   --shard=0 --shards=6 \
 *   --index-from=0 --index-to=1999
 */

require_once __DIR__ . '/vendor/autoload.php';

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

    // Sharding
    private int $shard = 0;    // este worker
    private int $shards = 1;   // total de workers

    // Rangos por índice (dentro del shard)
    private int $indexFrom = 0;
    private ?int $indexTo = null; // inclusive

    public function __construct(string $uri, string $username, string $password, string $restoreDate)
    {
        $this->sabreService = new \Sabre\Xml\Service();
        $this->uri = rtrim($uri, '/');
        $this->username = $username;
        $this->password = $password;
        $this->restoreDate = new \DateTime($restoreDate);

        // cURL (Keep-Alive)
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [
            CURLOPT_FAILONERROR    => 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_USERPWD        => "{$this->username}:{$this->password}",
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => ['Connection: Keep-Alive','Accept: */*'],
        ]);
    }

    public function setShard(int $shard, int $shards): void {
        if ($shards < 1) $shards = 1;
        $this->shards = $shards;
        $this->shard  = max(0, min($shard, $shards - 1));
    }

    public function setIndexRange(int $from = 0, ?int $to = null): void {
        $this->indexFrom = max(0, $from);
        $this->indexTo   = ($to !== null && $to >= 0) ? $to : null;
    }

    public function run(): void
    {
        echo "Collecting items to restore…\n";
        $this->collectTrashbinData();
        echo sprintf("Found %d candidates (after date filter)\n", count($this->trashbinData));
        $this->restoreTrashbinData();
    }

    private function dav(string $method, string $url, array $headers = [], ?string $body = null, ?int $depth = null): array
    {
        $h = [];
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
        if (!empty($data)) array_shift($data); // quita el root collection

        foreach ($data as $entry) {
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

            $this->trashbinData[] = [
                'remoteUrl' => $remoteUrl,                   // ruta en trash-bin
                'destPath'  => ltrim($originalLocation, '/'),
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

            [$st, ] = $this->dav('PROPFIND', $url, [], $this->propfindBody(), 0);
            if ($st === 404 || $st === 405) {
                $this->dav('MKCOL', $url); // ignora 405/409 si ya existe
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
        // 1) separa y ordena por profundidad (padres → hijos)
        $dirs  = array_values(array_filter($this->trashbinData, fn($x) => $x['isDir']));
        $files = array_values(array_filter($this->trashbinData, fn($x) => !$x['isDir']));

        usort($dirs,  fn($a,$b) => $this->pathDepth($a['destPath'])  <=> $this->pathDepth($b['destPath']));
        usort($files, fn($a,$b) => $this->pathDepth($a['destPath'])  <=> $this->pathDepth($b['destPath']));

        // 2) cola final: dirs primero, luego files
        $todo = array_merge($dirs, $files);

        // 3) orden estable adicional por ruta (para sharding/rangos determinísticos)
        usort($todo, fn($a,$b) => strcmp($a['destPath'], $b['destPath']));

        // 4) sharding estable por hash(destPath)
        if ($this->shards > 1) {
            $todo = array_values(array_filter($todo, function($it) {
                $k = strtolower($it['destPath']);
                $h = crc32($k);
                return ($h % $this->shards) === $this->shard;
            }));
            echo "Shard {$this->shard}/{$this->shards} → items: " . count($todo) . PHP_EOL;
        }

        // 5) rango por índice dentro del shard
        $total = count($todo);
        $from  = $this->indexFrom;
        $to    = ($this->indexTo === null) ? ($total - 1) : min($this->indexTo, $total - 1);

        if ($from >= $total) {
            echo "Range out of bounds: index-from=$from total=$total\n";
            return;
        }
        $len = max(0, $to - $from + 1);
        $chunk = array_slice($todo, $from, $len);

        echo "Total pending (this shard): {$total} | Executing range [{$from}..{$to}] (count={$len})\n";

        [$ok, $fail] = $this->restoreList($chunk, 'ITEM');
        echo sprintf("Range [%d..%d] done. OK=%d FAIL=%d\n", $from, $to, $ok, $fail);
    }
}

/* ---------- CLI bootstrap ---------- */
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Console\Input\InputArgument;

$app = new SingleCommandApplication('owncloud-restore-trash', 'folders-first-sharded');

$app->addArgument('noop', InputArgument::OPTIONAL); // para compatibilidad

$app->setCode(function(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output){
    $argv = $_SERVER['argv'] ?? [];

    $args = [
        'url' => null, 'username' => null, 'password' => null, 'date' => null,
        'shard' => 0, 'shards' => 1,
        'index_from' => 0, 'index_to' => null,
    ];

    foreach ($argv as $i => $a) {
        if ($i === 0) continue;
        if      (preg_match('/^--url=(.+)$/', $a, $m))          $args['url'] = rtrim($m[1], '/');
        elseif  (preg_match('/^--username=(.+)$/', $a, $m))     $args['username'] = $m[1];
        elseif  (preg_match('/^--password=(.+)$/', $a, $m))     $args['password'] = $m[1];
        elseif  (preg_match('/^--date=(.+)$/', $a, $m))         $args['date'] = $m[1];
        elseif  (preg_match('/^--shard=(\d+)$/', $a, $m))       $args['shard'] = (int)$m[1];
        elseif  (preg_match('/^--shards=(\d+)$/', $a, $m))      $args['shards'] = (int)$m[1];
        elseif  (preg_match('/^--index-from=(\d+)$/', $a, $m))  $args['index_from'] = (int)$m[1];
        elseif  (preg_match('/^--index-to=(\d+)$/', $a, $m))    $args['index_to'] = (int)$m[1];
    }

    foreach (['url','username','password','date'] as $k) {
        if (!$args[$k]) { fwrite(STDERR, "Missing --$k\n"); return 1; }
    }
    if (false === strtotime($args['date'])) {
        fwrite(STDERR, "Invalid --date. Use YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS\n");
        return 1;
    }

    $rt = new RestoreTrash($args['url'], $args['username'], $args['password'], $args['date']);
    $rt->setShard($args['shard'], $args['shards']);
    $rt->setIndexRange($args['index_from'], $args['index_to']);
    $rt->run();
    return 0;
});

$app->run();
