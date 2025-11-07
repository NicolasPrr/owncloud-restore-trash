<?php

use Sabre\Xml\Service;

class RestoreTrash
{
    private $uri;
    private $username;
    private $password;
    private $sabreService;
    private $restoreDate;
    private $trashbinData;

    private int $ocShard  = 0;
    private int $ocShards = 1;
    private int $ocIndexFrom = 0;
    private ?int $ocIndexTo  = null;

    public function __construct($uri, $username, $password, $restoreDate)
    {
        $this->trashbinData = [];
        $this->sabreService = new Service();
        $this->uri = $uri;
        $this->username = $username;
        $this->password = $password;
        $this->restoreDate = new DateTime($restoreDate);

        $shards = getenv('OC_SHARDS');
        $shard  = getenv('OC_SHARD');
        $from   = getenv('OC_INDEX_FROM');
        $to     = getenv('OC_INDEX_TO');

        if ($shards !== false && ctype_digit((string)$shards)) $this->ocShards = max(1, (int)$shards);
        if ($shard  !== false && ctype_digit((string)$shard))  $this->ocShard  = max(0, min((int)$shard, $this->ocShards - 1));
        if ($from   !== false && ctype_digit((string)$from))   $this->ocIndexFrom = max(0, (int)$from);
        if ($to     !== false && ctype_digit((string)$to))     $this->ocIndexTo = (int)$to;
    }

    private function depth(string $p): int {
        return substr_count(trim($p, '/'), '/');
    }

    private function encodePathSegments(string $path): string {
        $parts = array_filter(explode('/', $path), fn($s) => $s !== '');
        return implode('/', array_map('rawurlencode', $parts));
    }

    private function ensureParents(string $destPath): void {
        $parts = array_filter(explode('/', $destPath));
        if (count($parts) <= 1) return;

        $base = rtrim($this->uri, '/') . '/remote.php/dav/files/' . rawurlencode($this->username);
        $cur = [];
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $cur[] = $parts[$i];
            $url = $base . '/' . $this->encodePathSegments(implode('/', $cur));

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_USERPWD => "{$this->username}:{$this->password}",
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0,
                CURLOPT_CUSTOMREQUEST => 'PROPFIND',
                CURLOPT_HTTPHEADER => ['Depth: 0'],
            ]);
            curl_exec($ch);
            $st = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($st === 404) {
                $mk = curl_init();
                curl_setopt_array($mk, [
                    CURLOPT_URL => $url,
                    CURLOPT_USERPWD => "{$this->username}:{$this->password}",
                    CURLOPT_RETURNTRANSFER => 1,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_SSL_VERIFYPEER => 0,
                    CURLOPT_CUSTOMREQUEST => 'MKCOL',
                ]);
                curl_exec($mk);
                curl_close($mk);
            }
        }
    }

    public function run()
    {
        echo "Collecting trash data...\n";
        $this->collectTrashbinData();
        echo sprintf("Found %d items\n", count($this->trashbinData));
        $this->restoreTrashbinData();
    }

    private function collectTrashbinData()
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_FAILONERROR => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $this->uri . "/remote.php/dav/trash-bin/" . $this->username,
            CURLOPT_USERPWD => "{$this->username}:{$this->password}",
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_CUSTOMREQUEST => "PROPFIND",
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/xml',
                'Depth: 1',
            ],
            CURLOPT_POSTFIELDS => '<?xml version="1.0"?>
                <d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
                    <d:prop>
                        <oc:trashbin-original-filename/>
                        <oc:trashbin-original-location/>
                        <oc:trashbin-delete-datetime/>
                        <d:resourcetype/>
                    </d:prop>
                </d:propfind>'
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        $data = $this->sabreService->parse($response);
        array_shift($data);

        foreach ($data as $entry) {
            $props = $entry['value'][1]['value'][0]['value'];
            $file  = $props[0]['value'][0]['value'];
            $loc   = $props[1]['value'];
            $date  = new DateTime($props[2]['value']);
            $type  = $props[3]['value'][0]['name'] ?? null;

            if ($date < $this->restoreDate) continue;

            $this->trashbinData[] = [
                'remoteUrl' => $entry['value'][0]['value'],
                'trashbinOriginalLocation' => $loc,
                'trashbinOriginalFilename' => $file,
                'isDir' => ($type === '{DAV:}collection'),
            ];
        }
    }

    private function restoreTrashbinData()
    {
        $dirs  = array_filter($this->trashbinData, fn($x) => $x['isDir']);
        $files = array_filter($this->trashbinData, fn($x) => !$x['isDir']);

        usort($dirs,  fn($a,$b)=>$this->depth($a['trashbinOriginalLocation']) <=> $this->depth($b['trashbinOriginalLocation']));
        usort($files, fn($a,$b)=>$this->depth($a['trashbinOriginalLocation']) <=> $this->depth($b['trashbinOriginalLocation']));

        $todo = array_merge($dirs, $files);

        if ($this->ocShards > 1) {
            $todo = array_values(array_filter($todo, function($it){
                $k = strtolower(ltrim($it['trashbinOriginalLocation'], '/'));
                return (crc32($k) % $this->ocShards) === $this->ocShard;
            }));
            echo sprintf("Shard %d/%d: %d items\n", $this->ocShard, $this->ocShards, count($todo));
        }

        $total = count($todo);
        $from  = $this->ocIndexFrom;
        $to    = $this->ocIndexTo ?? ($total - 1);
        $todo  = array_slice($todo, $from, $to - $from + 1);
        echo sprintf("Processing [%d..%d] (%d items)\n", $from, $to, count($todo));

        foreach ($todo as $item) {
            $dest = ltrim($item['trashbinOriginalLocation'], '/');
            $this->ensureParents($dest);

            $src = rtrim($this->uri, '/') . $item['remoteUrl'];
            $dst = rtrim($this->uri, '/') . '/remote.php/dav/files/' . rawurlencode($this->username) . '/' . $this->encodePathSegments($dest);

            $max = 3; $try = 0; $ok = false;
            while ($try < $max && !$ok) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $src,
                    CURLOPT_USERPWD => "{$this->username}:{$this->password}",
                    CURLOPT_RETURNTRANSFER => 1,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_SSL_VERIFYPEER => 0,
                    CURLOPT_CUSTOMREQUEST => 'MOVE',
                    CURLOPT_HTTPHEADER => [
                        'Overwrite: F',
                        'Destination: ' . $dst,
                    ],
                ]);
                curl_exec($ch);
                $st = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);

                if ($st >= 200 && $st < 300) {
                    echo "[OK] $dest\n"; $ok = true;
                } else {
                    echo "[WARN $st] $dest (try " . ($try+1) . ")\n";
                    if (in_array($st, [409,423,502,503], true)) usleep(300000);
                    else break;
                }
                $try++;
            }

            if (!$ok) echo "[FAIL] $dest\n";
        }
    }
}
