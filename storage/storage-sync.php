<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$storageDir = __DIR__ . '/storage';
$storageFile = $storageDir . '/storage-data.json';
$backupFile = $storageDir . '/storage-data.backup.json';

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureStorageDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            respond(500, [
                'ok' => false,
                'error' => 'No se pudo crear la carpeta de almacenamiento'
            ]);
        }
    }

    if (!is_writable($dir)) {
        @chmod($dir, 0775);
    }

    if (!is_writable($dir)) {
        respond(500, [
            'ok' => false,
            'error' => 'La carpeta de almacenamiento no tiene permisos de escritura'
        ]);
    }
}

function readStoredData(string $file, string $backupFile): ?array
{
    if (!is_file($file)) {
        if (is_file($backupFile)) {
            $rawBackup = file_get_contents($backupFile);
            if ($rawBackup !== false && trim($rawBackup) !== '') {
                $decodedBackup = json_decode($rawBackup, true);
                if (is_array($decodedBackup)) {
                    return $decodedBackup;
                }
            }
        }
        return null;
    }

    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        if (is_file($backupFile)) {
            $rawBackup = file_get_contents($backupFile);
            if ($rawBackup !== false && trim($rawBackup) !== '') {
                $decodedBackup = json_decode($rawBackup, true);
                if (is_array($decodedBackup)) {
                    return $decodedBackup;
                }
            }
        }
        return null;
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    if (is_file($backupFile)) {
        $rawBackup = file_get_contents($backupFile);
        if ($rawBackup !== false && trim($rawBackup) !== '') {
            $decodedBackup = json_decode($rawBackup, true);
            if (is_array($decodedBackup)) {
                return $decodedBackup;
            }
        }
    }

    respond(500, [
        'ok' => false,
        'error' => 'Los datos almacenados son inválidos'
    ]);
}

function normalizePayload(array $payload): array
{
    $allowed = ['clients', 'bookings', 'lawyers', 'profiles'];
    $clean = [];

    foreach ($allowed as $key) {
        $clean[$key] = isset($payload[$key]) && is_array($payload[$key])
            ? array_values($payload[$key])
            : [];
    }

    $clean['updatedAt'] = gmdate('c');

    return $clean;
}

function writeStoredData(string $file, string $backupFile, array $data): void
{
    $encoded = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($encoded === false) {
        respond(500, [
            'ok' => false,
            'error' => 'No se pudieron codificar los datos'
        ]);
    }

    $tempFile = $file . '.tmp';

    $handle = @fopen($tempFile, 'cb');
    if ($handle === false) {
        respond(500, [
            'ok' => false,
            'error' => 'No se pudo abrir el archivo temporal para escritura'
        ]);
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        respond(500, [
            'ok' => false,
            'error' => 'No se pudo bloquear el archivo para escritura segura'
        ]);
    }

    ftruncate($handle, 0);
    rewind($handle);

    $written = fwrite($handle, $encoded . PHP_EOL);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    if ($written === false) {
        @unlink($tempFile);
        respond(500, [
            'ok' => false,
            'error' => 'No se pudieron guardar los datos'
        ]);
    }

    if (is_file($file)) {
        @copy($file, $backupFile);
    }

    if (!@rename($tempFile, $file)) {
        @unlink($tempFile);
        respond(500, [
            'ok' => false,
            'error' => 'No se pudo reemplazar el archivo de datos'
        ]);
    }

    @chmod($file, 0664);
}

ensureStorageDirectory($storageDir);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $data = readStoredData($storageFile, $backupFile);

    respond(200, [
        'ok' => true,
        'data' => $data
    ]);
}

if ($method === 'POST') {
    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?: '', true);

    if (!is_array($payload)) {
        respond(400, [
            'ok' => false,
            'error' => 'El cuerpo JSON es inválido'
        ]);
    }

    $clean = normalizePayload($payload);
    writeStoredData($storageFile, $backupFile, $clean);

    respond(200, [
        'ok' => true,
        'message' => 'Datos guardados correctamente',
        'updatedAt' => $clean['updatedAt']
    ]);
}

respond(405, [
    'ok' => false,
    'error' => 'Método no permitido'
]);