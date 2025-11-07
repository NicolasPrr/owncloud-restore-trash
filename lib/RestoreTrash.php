<?php
/**
 * ownCloud restore from trash - folders first, then files.
 * - Crea padres ausentes (MKCOL)
 * - Reintenta en 409/423/5xx
 * - Sharding estable (--shard, --shards)
 * - Rangos (--index-from, --index-to)
 */

require_once __DIR__ . '/vendor/autoload.php';

class RestoreTrash
{
    private string $uri;
    private string $username;
    private string $password;
    private \Sabre\Xml\Service $sabreService;
    private \DateTime $restoreDate;
    private $ch;

    private array $trashbinData = [];
    private int $shard = 0;
    private int $shards = 1;
    private int $indexFrom = 0;
    private ?int $indexTo = null;

    public function __construct(string $uri, string $username, string $password, string $restoreDate)
    {
        $this->sabreService = new \Sabre\Xml\Service();
        $this->uri = rtrim($uri, '/');
        $this->username = $username;
        $this->password = $password;
        $this->restoreDate = new \DateTime($restoreDate);
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [
            CURLOPT_FAILONERROR    => 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_USERPWD        => "{$this->username}:{$this->password}",
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => ['Connection: Keep-Alive', 'Accept: */*'],
        ]);
    }

    public function setShard(int $shard, int $shards): void
    {
        if ($shards < 1) $shards = 1;
        $this->shards = $shards;
        $this->shard  = max(0, min($shard, $shards - 1));
    }

    public function setIndexRange(int $from = 0, ?int $to = null): void
    {
        $this->indexFrom = max(0, $from);
        $this->indexTo   = ($to !== null && $to >= 0) ? $to : null;
    }

    public function run(): void
    {
        echo "Collecting items...\n";
        $this->collectTrashbinData();
        echo sprintf("Found %d candidates\n", count($this->trashbinData));
        $this->restoreTrashbinData();
    }

    private function dav(string $method, string $url, array $headers = [], ?string $body = null, ?int $depth = null): array
    {
        $h = [];
        foreach ($headers as $k => $v) $h[] = "$k: $v";
        if ($depth !== null) $h[] = "Depth: $depth";
        if ($body !== null && !array_key_exists('Content-Type', $headers))
            $h[] = "Content-Type: application/xml; charset=UTF-8";

        curl_setopt_array($this->ch, [
            CURLOPT_URL           => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER    => $h,
            CURLOPT_POSTFIELDS    => $body ?? '',
        ]);

        $resp   = curl_exec($this->ch);
        $status = (int) curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE);

        if ($resp === false) throw new \RuntimeException(curl_error($this->ch));
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
  </d:prop>
</d:propfind>';
    }

    private function encodePathSegments(string $path): string
    {
        return implode('/', array_map('rawurlencode', array_filter(explode('/', $path))));
    }

    private function collectTrashbinData(): void
    {
        $url = $this->uri . "/remote.php/dav/trash-bin/" . rawurlencode($this->username) . "/";
        [$status, $response] = $this->dav("PROPFIND", $url, ['charset' => 'UTF-8'], $this->propfindBody(), 1);
        if ($status >= 400) throw new \RuntimeException("PROPFIND failed ($status)");

        $data = $this->sabreService->parse($response);
        array_shift($data);

        foreach ($data as $entry) {
            $remoteUrl = $entry['value'][0]['value'] ?? null;
            if (!$remoteUrl) continue;

            $props = $entry['value'][1]['value'][0]['value'] ?? [];
            $originalLocation = null; $deleteDateTime = null; $isDir = false;

            foreach ($props as $p) {
                $n = $p['name'] ?? '';
                if (strpos($n, 'trashbin-original-location') !== false) $originalLocation = $p['value'];
                elseif (strpos($n, 'trashbin-delete-datetime') !== false) $deleteDateTime = $p['value'];
                elseif (strpos($n, 'resourcetype') !== false)
                    foreach (($p['value'] ?? []) as $v)
                        if (($v['name'] ?? '') === '{DAV:}collection') $isDir = true;
            }
            if (!$originalLocation || !$deleteDateTime) continue;
            $deletedAt = new \DateTime($deleteDateTime);
            if ($deletedAt < $this->restoreDate) continue;

            $this->trashbinData[] = [
                'remoteUrl' => $remoteUrl,
                'destPath'  => ltrim($originalLocation, '/'),
                'isDir'     => $isDir,
            ];
        }
    }

    private function ensureParents(string $destPath): void
    {
        $parts = explode('/', $destPath);
        if (count($parts) <= 1) return;

        $base = $this->uri . '/remote.php/dav/files/' . rawurlencode($this->username);
        $cur = [];
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $cur[] = $parts[$i];
            $url = $base . '/' . $this->encodePathSegments(implode('/', $cur));
            [$st, ] = $this->dav('PROPFIND', $url, [], $this->propfindBody(), 0);
            if ($st === 404) $this->dav('MKCOL', $url);
        }
    }

    private function moveFromTrash(string $remoteUrl, string $destPath): int
    {
        $src = $this->uri . $remoteUrl;
        $dst = $this->uri . '/remote.php/dav/files/' . rawurlencode($this->username) . '/' . $this->encodePathSegments($destPath);
        [$st, ] = $this->dav('MOVE', $src, ['Overwrite'=>'F', 'Destination'=>$dst]);
        return $st;
    }

    private function restoreTrashbinData(): void
    {
        $dirs  = array_filter($this->trashbinData, fn($x) => $x['isDir']);
        $files = array_filter($this->trashbinData, fn($x) => !$x['isDir']);
        usort($dirs, fn($a,$b)=>substr_count($a['destPath'],'/')<=>substr_count($b['destPath'],'/'));
        usort($files,fn($a,$b)=>substr_count($a['destPath'],'/')<=>substr_count($b['destPath'],'/'));
        $todo = array_merge($dirs,$files);
        usort($todo,fn($a,$b)=>strcmp($a['destPath'],$b['destPath']));

        // Shard
        if ($this->shards>1)
            $todo = array_values(array_filter($todo, fn($i)=>crc32(strtolower($i['destPath']))%$this->shards===$this->shard));

        $total=count($todo);
        $from=$this->indexFrom;
        $to=($this->indexTo===null)?$total-1:min($this->indexTo,$total-1);
        $chunk=array_slice($todo,$from,$to-$from+1);

        echo "Shard {$this->shard}/{$this->shards} | Range [$from..$to] | Items: ".count($chunk)."\n";

        foreach($chunk as $it){
            $this->ensureParents($it['destPath']);
            $st=$this->moveFromTrash($it['remoteUrl'],$it['destPath']);
            if($st>=200&&$st<300)
                echo "[OK] {$it['destPath']}\n";
            else
                echo "[FAIL $st] {$it['destPath']}\n";
        }
    }
}

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--url=(.+)$/',$a,$m)) $args['url']=$m[1];
    elseif (preg_match('/^--username=(.+)$/',$a,$m)) $args['username']=$m[1];
    elseif (preg_match('/^--password=(.+)$/',$a,$m)) $args['password']=$m[1];
    elseif (preg_match('/^--date=(.+)$/',$a,$m)) $args['date']=$m[1];
    elseif (preg_match('/^--shard=(\d+)$/',$a,$m)) $args['shard']=(int)$m[1];
    elseif (preg_match('/^--shards=(\d+)$/',$a,$m)) $args['shards']=(int)$m[1];
    elseif (preg_match('/^--index-from=(\d+)$/',$a,$m)) $args['from']=(int)$m[1];
    elseif (preg_match('/^--index-to=(\d+)$/',$a,$m)) $args['to']=(int)$m[1];
}

$rt = new RestoreTrash($args['url'],$args['username'],$args['password'],$args['date']);
$rt->setShard($args['shard']??0,$args['shards']??1);
$rt->setIndexRange($args['from']??0,$args['to']??null);
$rt->run();
