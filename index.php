<?php
/**
 * HLS segment downloader + Catbox uploader
 * - Mengunduh segmen .ts dari playlist M3U8
 * - Setiap segmen yang sukses diunduh langsung di-upload ke Catbox
 * - Menyimpan log URL hasil upload di file upload_log.txt
 * - Menghindari re-download & re-upload segmen yang sama
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

// ====== KONFIGURASI CATBOX ======
$CATBOX_ENDPOINT = "https://catbox.moe/user/api.php";
$USERHASH = "578e5c319d525e236a516b876"; // ganti bila perlu
$DELETE_LOCAL_AFTER_UPLOAD = false;      // true = hapus file lokal setelah sukses upload

// ====== INPUT DARI USER ======
echo "Masukkan URL playlist (.m3u8): ";
$handle = fopen("php://stdin", "r");
$playlistUrl = trim(fgets($handle));

echo "Masukkan nama folder untuk menyimpan segmen: ";
$folderName = trim(fgets($handle));
fclose($handle);

if (empty($playlistUrl) || empty($folderName)) {
    die("❌ URL dan nama folder tidak boleh kosong.\n");
}

$saveDir = __DIR__ . "/" . $folderName;
if (!is_dir($saveDir)) {
    mkdir($saveDir, 0777, true);
}

$logFile = $saveDir . "/upload_log.txt";

// ====== STATE ======
$downloaded = []; // nama file yang sudah diunduh (untuk cegah duplikat)
$uploaded   = []; // nama file yang sudah di-upload (untuk cegah duplikat)

// Muat state dari log, jika ada
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Format log: TIMESTAMP | FILENAME | STATUS | URL/ERROR
        $parts = explode(" | ", $line);
        if (count($parts) >= 4) {
            $fname = $parts[1];
            $status = $parts[2];
            if ($status === "UPLOADED") {
                $uploaded[$fname] = true;
            }
        }
    }
}

// ====== HTTP CONTEXT UNTUK DOWNLOAD ======
$context = stream_context_create([
    "http" => [
        "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n"
    ]
]);

/**
 * Upload file ke Catbox
 * @param string $filePath
 * @param string $userhash
 * @param string $endpoint
 * @param int    $maxRetry
 * @return array [success(bool), message_or_url(string)]
 */
function catbox_upload(string $filePath, string $userhash, string $endpoint, int $maxRetry = 3): array {
    if (!file_exists($filePath)) {
        return [false, "File tidak ditemukan: $filePath"];
    }

    $mime = mime_content_type($filePath) ?: 'application/octet-stream';
    $filename = basename($filePath);

    $shouldFallback = false;

    if (function_exists('curl_init')) {
        for ($attempt = 1; $attempt <= $maxRetry; $attempt++) {
            $ch = curl_init();
            $cfile = curl_file_create($filePath, $mime, $filename);
            $postFields = [
                'reqtype'      => 'fileupload',
                'userhash'     => $userhash,
                'fileToUpload' => $cfile,
            ];

            curl_setopt_array($ch, [
                CURLOPT_URL            => $endpoint,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $postFields,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 90,
                // Kunci: paksa HTTP/1.1 + buang Expect:100-continue
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_HTTPHEADER     => [
                    'Expect:',           // disable 100-continue
                    'Connection: close',
                    'User-Agent: PHP-cURL/1.1',
                ],
                // (opsional) jika jaringan rewel:
                // CURLOPT_SSL_VERIFYPEER => true,
                // CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($ch);
            $err      = curl_error($ch);
            $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($err) {
                // Kalau error HTTP/2/protocol, tandai untuk fallback stream
                if (stripos($err, 'HTTP/2') !== false || stripos($err, 'PROTOCOL_ERROR') !== false) {
                    $shouldFallback = true;
                    break;
                }
                if ($attempt < $maxRetry) { usleep(400000); continue; }
                return [false, "cURL error: $err"];
            }

            if ($code >= 200 && $code < 300 && is_string($response) && strlen(trim($response)) > 0) {
                $resp = trim($response);
                if (filter_var($resp, FILTER_VALIDATE_URL)) return [true, $resp];
                if ($attempt < $maxRetry) { usleep(400000); continue; }
                return [false, "Catbox response: $resp"];
            } else {
                if ($attempt < $maxRetry) { usleep(400000); continue; }
                return [false, "HTTP $code, response: ".(string)$response];
            }
        }
    } else {
        $shouldFallback = true;
    }

    // ===== Fallback tanpa cURL: multipart manual via streams (HTTP/1.1 default) =====
    for ($attempt = 1; $attempt <= $maxRetry; $attempt++) {
        try {
            $boundary = '----WebKitFormBoundary' . bin2hex(random_bytes(16));
            $EOL = "\r\n";

            $fileData = file_get_contents($filePath);
            if ($fileData === false) {
                return [false, "Gagal baca file: $filePath"];
            }

            $body  = '';
            // reqtype
            $body .= "--$boundary$EOL";
            $body .= 'Content-Disposition: form-data; name="reqtype"' . $EOL . $EOL;
            $body .= "fileupload$EOL";
            // userhash
            $body .= "--$boundary$EOL";
            $body .= 'Content-Disposition: form-data; name="userhash"' . $EOL . $EOL;
            $body .= $userhash . $EOL;
            // fileToUpload
            $body .= "--$boundary$EOL";
            $body .= 'Content-Disposition: form-data; name="fileToUpload"; filename="' . addslashes($filename) . '"' . $EOL;
            $body .= 'Content-Type: ' . $mime . $EOL . $EOL;
            $body .= $fileData . $EOL;
            // closing boundary
            $body .= "--$boundary--$EOL";

            $headers = [
                "Content-Type: multipart/form-data; boundary=$boundary",
                "Content-Length: " . strlen($body),
                "User-Agent: PHP-StreamUploader/1.0",
                "Connection: close",
            ];

            $context = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => implode("\r\n", $headers),
                    'content'       => $body,
                    'timeout'       => 90,
                    'ignore_errors' => true, // dapatkan status walau error
                ]
            ]);

            $response = @file_get_contents($endpoint, false, $context);

            $status = 0;
            if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
                $status = (int)$m[1];
            }

            if ($response === false) {
                if ($attempt < $maxRetry) { usleep(400000); continue; }
                return [false, "Koneksi gagal (stream)"];
            }

            $resp = trim((string)$response);
            if ($status >= 200 && $status < 300 && $resp !== '') {
                if (filter_var($resp, FILTER_VALIDATE_URL)) return [true, $resp];
                if ($attempt < $maxRetry) { usleep(400000); continue; }
                return [false, "Catbox response: $resp"];
            } else {
                if ($attempt < $maxRetry) { usleep(400000); continue; }
                return [false, "HTTP $status, response: $resp"];
            }
        } catch (Throwable $e) {
            if ($attempt < $maxRetry) { usleep(400000); continue; }
            return [false, "Exception: " . $e->getMessage()];
        }
    }

    return [false, "Gagal upload setelah $maxRetry percobaan."];
}

/**
 * Tulis log baris
 */
function write_log(string $logFile, string $filename, string $status, string $info): void {
    $ts = date('Y-m-d H:i:s');
    $line = "$ts | $filename | $status | $info\n";
    file_put_contents($logFile, $line, FILE_APPEND);
}

echo "▶️  Mulai memantau playlist: $playlistUrl\n";

while (true) {
    $m3u8Content = @file_get_contents($playlistUrl, false, $context);

    if ($m3u8Content === false) {
        echo "❌ Gagal mengambil playlist. Coba lagi...\n";
        sleep(5);
        continue;
    }

    // Ambil baris yang berakhiran .ts (boleh ada querystring)
    preg_match_all('/^(.*\.ts.*)$/m', $m3u8Content, $matches);

    foreach ($matches[1] as $segment) {
        $segment = trim($segment);
        $path = parse_url($segment, PHP_URL_PATH);
        $filename = basename($path ?: $segment);

        // Cegah re-download
        if (in_array($filename, $downloaded, true)) {
            // Jika sudah diunduh namun belum di-upload (misal upload sempat gagal), cek lagi
            $localPath = "$saveDir/$filename";
            if (file_exists($localPath) && empty($uploaded[$filename])) {
                echo "↻ Coba upload ulang: $filename\n";
                [$ok, $msg] = catbox_upload($localPath, $USERHASH, $CATBOX_ENDPOINT);
                if ($ok) {
                    echo "☁️  Uploaded: $filename -> $msg\n";
                    write_log($logFile, $filename, "UPLOADED", $msg);
                    $uploaded[$filename] = true;
                    if ($DELETE_LOCAL_AFTER_UPLOAD) {
                        @unlink($localPath);
                    }
                } else {
                    echo "⚠️  Upload gagal: $filename | $msg\n";
                    write_log($logFile, $filename, "UPLOAD_FAIL", $msg);
                }
            }
            continue;
        }

        // Bentuk URL absolut bila relatif
        if (strpos($segment, "http") !== 0) {
            $baseUrl = rtrim(dirname($playlistUrl), '/');
            $segmentUrl = $baseUrl . '/' . ltrim($segment, '/');
        } else {
            $segmentUrl = $segment;
        }

        // Download segmen
        $segmentData = @file_get_contents($segmentUrl, false, $context);

        if ($segmentData !== false) {
            $localPath = "$saveDir/$filename";
            file_put_contents($localPath, $segmentData);
            $downloaded[] = $filename;
            echo "✅ Downloaded: $filename\n";

            // Upload ke Catbox
            [$ok, $msg] = catbox_upload($localPath, $USERHASH, $CATBOX_ENDPOINT);
            if ($ok) {
                echo "☁️  Uploaded: $filename -> $msg\n";
                write_log($logFile, $filename, "UPLOADED", $msg);
                $uploaded[$filename] = true;
                if ($DELETE_LOCAL_AFTER_UPLOAD) {
                    @unlink($localPath);
                }
            } else {
                echo "⚠️  Upload gagal: $filename | $msg\n";
                write_log($logFile, $filename, "UPLOAD_FAIL", $msg);
            }

        } else {
            echo "⚠️  Gagal download: $filename\n";
            // Boleh tulis log gagal download jika mau
        }

        flush();
    }

    echo "⏳ Menunggu segmen baru...\n";
    sleep(5);
}
