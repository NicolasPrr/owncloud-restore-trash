<?php

class RestoreTrash
{
    private $uri;
    private $username;
    private $password;
    private $sabreService;
    private $restoreDate;
    private $trashbinData;

    public function __construct($uri, $username, $password, $restoreDate)
    {
        $this->trashbinData = [];
        $this->sabreService = new Sabre\Xml\Service();
        $this->uri = rtrim($uri, '/');
        $this->username = $username;
        $this->password = $password;
        $this->restoreDate = new DateTime($restoreDate);
    }

    public function run()
    {
        echo "Collection files to restore\n";
        $this->collectTrashbinData();
        echo sprintf("Found %s items to restore\n", count($this->trashbinData));
        $this->restoreTrashbinData();
    }

    private function dav($method, $url, array $headers = [], $body = null, $depth = null)
    {
        $ch = curl_init();
        $h = [];
        foreach ($headers as $k => $v) $h[] = "$k: $v";
        if ($depth !== null) $h[] = "Depth: $depth";
        if ($body !== null && !isset($headers['Content-Type'])) {
            $h[] = "Content-Type: application/xml; charset=UTF-8";
        }

        $opts = [
            CURLOPT_FAILONERROR => 0,                 // queremos leer el código exacto (409/423)
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $url,
            CURLOPT_USERPWD => "{$this->username}:{$this->password}",
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $h,
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;

        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("cURL error: $err");
        }
        curl_close($ch);
        return [$status, $resp];
    }

    private function propfindBody()
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

    private function collectTrashbinData()
    {
        $url = $this->uri . "/remote.php/dav/trash-bin/" . rawurlencode($this->username) . "/";
        [$status, $response] = $this->dav(
            "PROPFIND",
            $url,
            ['Connection' => 'Keep-Alive', 'charset' => 'UTF-8'],
            $this->propfindBody(),
            1 // depth 1
        );

        if ($status >= 400) {
            throw new \RuntimeException("PROPFIND trash-bin failed with HTTP $status");
        }

        $data = $this->sabreService->parse($response);
        // El primer elemento suele ser el propio collection root -> lo descartamos
        if (!empty($data)) array_shift($data);

        foreach ($data as $entry) {
            // La estructura exacta de $entry varía por Sabre\Xml; navegamos por claves conocidas
            $remoteUrl = $entry['value'][0]['value'] ?? null;
            if (!$remoteUrl) {
                // algunos Sabre devuelven href en otra posición; intenta buscarlo
                continue;
            }

            $props = $entry['value'][1]['value'][0]['value'] ?? [];
            // Default
            $originalFilename = null;
            $originalLocation = null;
            $deleteDateTime   = null;
            $isDir            = false;

            foreach ($props as $p) {
                $k = $p['name'] ?? '';
                // claves esperadas:
                // {http://owncloud.org/ns}trashbin-original-filename
                // {http://owncloud.org/ns}trashbin-original-location
                // {http://owncloud.org/ns}trashbin-delete-datetime
                // {DAV:}resourcetype (collection?)
                // {DAV:}getcontenttype
                if (strpos($k, 'trashbin-original-filename') !== false) {
                    $originalFilename = $p['value'] ?? null;
                } elseif (strpos($k, 'trashbin-original-location') !== false) {
                    $originalLocation = $p['value'] ?? null;
                } elseif (strpos($k, 'trashbin-delete-datetime') !== false) {
                    $deleteDateTime = $p['value'] ?? null; // ISO date string
                } elseif (strpos($k, 'resourcetype') !== false) {
                    // directory si contiene collection
                    $val = $p['value'] ?? [];
                    if (is_array($val)) {
                        foreach ($val as $vv) {
                            if (($vv['name'] ?? '') === '{DAV:}collection') {
                                $isDir = true;
                                break;
                            }
                        }
                    }
                }
            }

            if (!$originalLocation || !$deleteDateTime) {
                continue;
            }

            $deletedAt = new \DateTime($deleteDateTime);
            if ($deletedAt < $this->restoreDate) {
                continue;
            }

            $this->trashbinData[] = [
                'remoteUrl' => $remoteUrl,                         // p.ej. /remote.php/dav/trash-bin/user/xyz.d12345
                'destPath'  => ltrim($originalLocation, '/'),      // relativo dentro de files/<user>/
                'isDir'     => (bool)$isDir,
                'deletedAt' => $deletedAt,
                'name'      => $originalFilename ?: basename($originalLocation),
            ];
        }
    }

    private function pathDepth($p)
    {
        return substr_count(trim($p, '/'), '/');
    }

    private function encodePathSegments($path)
    {
        $parts = array_filter(explode('/', $path), fn($s) => $s !== '');
        return implode('/', array_map('rawurlencode', $parts));
    }

    private function ensureParents($destPath)
    {
        // Crea la cadena de padres bajo /remote.php/dav/files/<user>/
        $parts = array_filter(explode('/', $destPath));
        if (count($parts) <= 1) return;

        $base = $this->uri . '/remote.php/dav/files/' . rawurlencode($this->username);
        $curParts = [];
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $curParts[] = $parts[$i];
            $url = $base . '/' . $this->encodePathSegments(implode('/', $curParts));
            // ¿existe?
            [$st, ] = $this->dav('PROPFIND', $url, [], $this->propfindBody(), 0);
            if ($st === 404 || $st === 405) {
                // crear
                [$mk, ] = $this->dav('MKCOL', $url);
                // ignorar 405/409 si hubo carrera o ya existe
            }
        }
    }

    private function moveFromTrash($remoteUrl, $destPath)
    {
        $src = $this->uri . $remoteUrl;
        $dst = $this->uri . '/remote.php/dav/files/' . rawurlencode($this->username) . '/' . $this->encodePathSegments($destPath);
        $headers = [
            'Overwrite'   => 'F',      // no sobrescribir
            'Destination' => $dst,
        ];
        return $this->dav('MOVE', $src, $headers);
    }

    private function restoreList(array $items, $label)
    {
        $ok = 0; $fail = 0;
        foreach ($items as $it) {
            // para archivos, asegurar padres
            $this->ensureParents($it['destPath']);

            // reintentos por 409/423
            $max = 3; $try = 0; $last = null;
            while ($try < $max) {
                [$st, ] = $this->moveFromTrash($it['remoteUrl'], $it['destPath']);
                if ($st >= 200 && $st < 300) {
                    $ok++;
                    echo "[OK] {$label} → {$it['destPath']}\n";
                    break;
                }
                $last = $st;
                $try++;
                echo "[WARN] HTTP {$st} {$label} → {$it['destPath']} (attempt {$try})\n";
                if (in_array($st, [409, 423, 502, 503], true)) {
                    usleep(300000); // 300 ms backoff
                    continue;
                } else {
                    break;
                }
            }
            if (!($last >= 200 && $last < 300)) {
                $fail++;
                echo "[FAIL] {$label} → {$it['destPath']} (last HTTP {$last})\n";
            }
        }
        return [$ok, $fail];
    }

    private function restoreTrashbinData()
    {
        // separar por tipo y ordenar por profundidad ascendente (padres primero)
        $dirs  = array_values(array_filter($this->trashbinData, fn($x) => $x['isDir']));
        $files = array_values(array_filter($this->trashbinData, fn($x) => !$x['isDir']));

        usort($dirs,  fn($a, $b) => $this->pathDepth($a['destPath']) <=> $this->pathDepth($b['destPath']));
        usort($files, fn($a, $b) => $this->pathDepth($a['destPath']) <=> $this->pathDepth($b['destPath']));

        echo sprintf("Restoring %d folders first…\n", count($dirs));
        [$okD, $failD] = $this->restoreList($dirs, 'DIR');

        echo sprintf("Restoring %d files…\n", count($files));
        [$okF, $failF] = $this->restoreList($files, 'FILE');

        echo sprintf("Done. Restored: %d, Failed: %d\n", ($okD + $okF), ($failD + $failF));
    }
}
