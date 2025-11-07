<?php

class RestoreTrash
{
    private $uri;
    private $username;
    private $password;
    private $restoreDate;

    /** @var array<int, array{remoteUrl:string, trashbinOriginalLocation:string, trashbinOriginalFilename:string, isDir:bool}> */
    private $trashbinData;

    // Opcional: límites de reintento para MOVE / MKCOL
    private $maxRetries = 4;
    private $retryBaseSleepMs = 300;

    public function __construct($uri, $username, $password, $restoreDate)
    {
        $this->trashbinData = [];
        $this->uri = rtrim((string)$uri, '/');
        $this->username = (string)$username;
        $this->password = (string)$password;
        $this->restoreDate = new DateTime($restoreDate);
    }

    public function run()
    {
        echo "Collection files to restore\n";
        $this->collectTrashbinData();

        // Sharding por variables de entorno (opcional)
        $shard  = getenv('OC_SHARD')  !== false ? (int)getenv('OC_SHARD')  : 0;
        $shards = getenv('OC_SHARDS') !== false ? (int)getenv('OC_SHARDS') : 1;
        if ($shards < 1) $shards = 1;
        if ($shard < 0 || $shard >= $shards) $shard = 0;

        // Orden: primero directorios, luego archivos
        $dirs  = [];
        $files = [];
        foreach ($this->trashbinData as $idx => $item) {
            // Filtrar por shard si corresponde
            if (($idx % $shards) !== $shard) continue;
            if ($item['isDir']) $dirs[] = $item;
            else $files[] = $item;
        }

        echo sprintf("Found %d items in shard %d/%d (dirs=%d, files=%d)\n",
            count($dirs) + count($files), $shard, $shards, count($dirs), count($files));

        // Restaurar: dirs primero
        $this->restoreList($dirs);
        // Luego archivos
        $this->restoreList($files);
    }

    private function restoreList(array $list)
    {
        foreach ($list as $trashbinRecord) {
            $dstPath = ltrim($trashbinRecord['trashbinOriginalLocation'], '/');

            // Asegura que las carpetas destino existan (para archivos y también para carpetas anidadas)
            $parent = $this->getParentPath($dstPath);
            if ($parent !== '') {
                try {
                    $this->ensureDestinationDirs($parent);
                } catch (\Throwable $e) {
                    $this->logFail($trashbinRecord, "MKCOL parent fail: " . $e->getMessage());
                    continue;
                }
            }

            // MOVE desde trash hacia files
            $ok = $this->moveFromTrash(
                $trashbinRecord['remoteUrl'],
                $dstPath,
                $trashbinRecord['isDir']
            );

            if ($ok) $this->logOk($trashbinRecord);
            else     $this->logFail($trashbinRecord, "(last HTTP )");
        }
    }

    private function logOk(array $rec)
    {
        $kind = $rec['isDir'] ? 'DIR ' : 'FILE';
        echo sprintf("[OK]  %s \xE2\x86\x92 %s\n", $kind, $rec['trashbinOriginalLocation']);
    }

    private function logFail(array $rec, string $reason)
    {
        $kind = $rec['isDir'] ? 'DIR ' : 'FILE';
        echo sprintf("[FAIL] %s \xE2\x86\x92 %s %s\n", $kind, $rec['trashbinOriginalLocation'], $reason);
    }

    private function getParentPath(string $path): string
    {
        $norm = trim($path, '/');
        if ($norm === '') return '';
        $parts = explode('/', $norm);
        array_pop($parts);
        return implode('/', $parts);
    }

    /**
     * Crea recursivamente las carpetas bajo /remote.php/dav/files/<user>/
     */
    private function ensureDestinationDirs(string $relativeDirPath): void
    {
        $relativeDirPath = trim($relativeDirPath, '/');
        if ($relativeDirPath === '') return;

        $segments = explode('/', $relativeDirPath);
        $current = '';
        foreach ($segments as $seg) {
            $current = ($current === '' ? $seg : $current . '/' . $seg);
            $this->mkcolIfNotExists($current);
        }
    }

    private function mkcolIfNotExists(string $relativePath): void
    {
        // PROPFIND Depth:0 para ver si existe
        $exists = $this->existsAtDestination($relativePath);
        if ($exists) return;

        $dstUrl = $this->uri . '/remote.php/dav/files/' . rawurlencode($this->username) . '/' . $this->encodePath($relativePath);
        // MKCOL con reintentos
        $attempt = 0;
        while (true) {
            $attempt++;
            $ch = curl_init();
            $headers = [
                'Content-Length: 0'
            ];
            $opts = [
                CURLOPT_URL => $dstUrl,
                CURLOPT_CUSTOMREQUEST => 'MKCOL',
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => "{$this->username}:{$this->password}",
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0,
                CURLOPT_HTTPHEADER => $headers,
            ];
            curl_setopt_array($ch, $opts);
            curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_errno($ch) ? curl_error($ch) : '';
            curl_close($ch);

            if ($http >= 200 && $http < 300) {
                return; // creado
            }
            if ($http === 405 || $http === 301 || $http === 302) {
                // ya existe o redirigió: tratar como OK
                return;
            }
            if ($http === 409 || $http === 423) {
                // Conflicto / Locked: reintenta con backoff
                if ($attempt < $this->maxRetries) {
                    usleep($this->sleepBackoffUs($attempt));
                    continue;
                }
            }
            if ($err !== '') {
                throw new \RuntimeException("MKCOL '$relativePath' curl error: $err (HTTP $http)");
            }
            throw new \RuntimeException("MKCOL '$relativePath' HTTP $http");
        }
    }

    private function existsAtDestination(string $relativePath): bool
    {
        $url = $this->uri . '/remote.php/dav/files/' . rawurlencode($this->username) . '/' . $this->encodePath($relativePath);
        $ch = curl_init();
        $headers = ['Depth: 0'];
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'PROPFIND',
            CURLOPT_NOBODY => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$this->username}:{$this->password}",
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_HTTPHEADER => $headers,
        ];
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($http >= 200 && $http < 300);
    }

    private function moveFromTrash(string $remoteUrl, string $dstRelativePath, bool $isDir): bool
    {
        $src = $this->uri . $remoteUrl;
        $dst = $this->uri . '/remote.php/dav/files/' . rawurlencode($this->username) . '/' . $this->encodePath($dstRelativePath);

        $attempt = 0;
        while (true) {
            $attempt++;
            $ch = curl_init();
            $headers = [
                'Overwrite: F',
                'Destination: ' . $dst,
            ];
            $opts = [
                CURLOPT_URL => $src,
                CURLOPT_CUSTOMREQUEST => 'MOVE',
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => "{$this->username}:{$this->password}",
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0,
                CURLOPT_HTTPHEADER => $headers,
            ];
            curl_setopt_array($ch, $opts);
            curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_errno($ch) ? curl_error($ch) : '';
            curl_close($ch);

            if ($http >= 200 && $http < 300) {
                return true;
            }

            // Manejo de 409/423 con reintentos y MKCOL del parent por si acaso
            if ($http === 409 || $http === 423) {
                // Refuerza MKCOL del padre y reintenta
                $parent = $this->getParentPath($dstRelativePath);
                if ($parent !== '') {
                    try { $this->ensureDestinationDirs($parent); } catch (\Throwable $e) { /* continúa */ }
                }
                if ($attempt < $this->maxRetries) {
                    usleep($this->sleepBackoffUs($attempt));
                    continue;
                }
            }

            // 412 Precondition Failed si existe y Overwrite:F
            if ($http === 412 || $http === 405) {
                // Ya existe; considera como restaurado (no sobrescribir)
                return true;
            }

            if ($err !== '') {
                // error de curl; reintento si quedan intentos
                if ($attempt < $this->maxRetries) {
                    usleep($this->sleepBackoffUs($attempt));
                    continue;
                }
                return false;
            }

            // Otros errores: sin reintento adicional
            return false;
        }
    }

    private function sleepBackoffUs(int $attempt): int
    {
        // backoff exponencial simple: 300ms, 600ms, 900ms, 1200ms...
        $ms = $this->retryBaseSleepMs * $attempt;
        return $ms * 1000;
    }

    private function encodePath(string $path): string
    {
        // Codifica cada segmento con rawurlencode para evitar problemas con espacios/acentos
        $clean = trim($path, '/');
        if ($clean === '') return '';
        $segments = explode('/', $clean);
        $enc = array_map('rawurlencode', $segments);
        return implode('/', $enc);
    }

    /**
     * PROPFIND al trash-bin para recolectar items (directorios y archivos)
     */
    private function collectTrashbinData()
    {
        $ch = curl_init();

        $curlOptions = [
            CURLOPT_FAILONERROR    => 0, // mejor reportar que abortar
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL            => $this->uri . "/remote.php/dav/trash-bin/" . rawurlencode($this->username),
            CURLOPT_USERPWD        => "{$this->username}:{$this->password}",
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_CUSTOMREQUEST  => "PROPFIND",
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/xml; charset=UTF-8',
                'Depth: 1',
            ],
            CURLOPT_POSTFIELDS     => '<?xml version="1.0" encoding="utf-8"?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:prop>
    <oc:trashbin-original-filename/>
    <oc:trashbin-original-location/>
    <oc:trashbin-delete-datetime/>
    <d:getcontentlength/>
    <d:resourcetype/>
  </d:prop>
</d:propfind>'
        ];

        curl_setopt_array($ch, $curlOptions);
        $response = curl_exec($ch);

        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("PROPFIND error: $err");
        }

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            // guardar para debug
            @file_put_contents(__DIR__ . '/last-propfind.xml', $response);
            throw new \RuntimeException("PROPFIND HTTP $httpCode. XML guardado en lib/last-propfind.xml");
        }

        // --- Parse robusto con SimpleXML ---
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response);
        if ($xml === false) {
            @file_put_contents(__DIR__ . '/last-propfind.xml', $response);
            $errs = array_map(function($e){ return trim($e->message); }, libxml_get_errors());
            libxml_clear_errors();
            throw new \RuntimeException("XML inválido. Errores: " . implode(' | ', $errs));
        }

        $xml->registerXPathNamespace('d',  'DAV:');
        $xml->registerXPathNamespace('oc', 'http://owncloud.org/ns');

        $responses = $xml->xpath('//d:multistatus/d:response');
        if ($responses === false) $responses = [];

        foreach ($responses as $r) {
            // href
            $hrefArr = $r->xpath('./d:href');
            if (!$hrefArr || !isset($hrefArr[0])) continue;
            $href = (string)$hrefArr[0];

            // props
            $propArr = $r->xpath('.//d:propstat/d:prop');
            if (!$propArr || !isset($propArr[0])) continue;
            $prop = $propArr[0];

            $origNameArr = $prop->xpath('./oc:trashbin-original-filename');
            $origLocArr  = $prop->xpath('./oc:trashbin-original-location');
            $delAtArr    = $prop->xpath('./oc:trashbin-delete-datetime');

            $origName = $origNameArr && isset($origNameArr[0]) ? (string)$origNameArr[0] : '';
            $origLoc  = $origLocArr  && isset($origLocArr[0])  ? (string)$origLocArr[0]  : '';
            $delAtStr = $delAtArr    && isset($delAtArr[0])    ? (string)$delAtArr[0]    : '';

            // Tipado (archivo o directorio) mirando resourcetype
            $isDir = false;
            $rtArr = $prop->xpath('./d:resourcetype');
            if ($rtArr && isset($rtArr[0])) {
                $rtXml = $rtArr[0]->asXML();
                if (is_string($rtXml) && stripos($rtXml, '<d:collection') !== false) {
                    $isDir = true;
                }
            }

            // Saltar entradas vacías (p. ej., raíz)
            if ($origName === '' && $origLoc === '' && $delAtStr === '') {
                continue;
            }

            // fecha de borrado
            try {
                $delAt = new \DateTime($delAtStr);
            } catch (\Throwable $e) {
                continue;
            }

            // Only observe data which has been deleted after certain date
            if ($delAt < $this->restoreDate) {
                continue;
            }

            // Normaliza remoteUrl (quitar host si viene absoluta)
            $remoteUrl = $href;
            if (preg_match('#^https?://[^/]+(/.*)$#i', $remoteUrl, $m)) {
                $remoteUrl = $m[1];
            }

            $this->trashbinData[] = [
                'remoteUrl'                => $remoteUrl,
                'trashbinOriginalLocation' => ltrim($origLoc, '/'),
                'trashbinOriginalFilename' => $origName,
                'isDir'                    => $isDir,
            ];
        }
    }
}
