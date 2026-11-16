<?php
$root = dirname(__DIR__);
chdir($root);

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uriPath = '/' . ltrim((string)$uriPath, '/');

if ($uriPath === '/' || $uriPath === '/index.php' || $uriPath === '/api/index.php') {
    $target = $root . '/index.php';
} else {
    $candidate = realpath($root . $uriPath);
    if (
        $candidate !== false &&
        $candidate !== __FILE__ &&
        strpos($candidate, $root . DIRECTORY_SEPARATOR) === 0 &&
        is_file($candidate) &&
        strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) === 'php'
    ) {
        $target = $candidate;
    } else {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 Not Found';
        exit;
    }
}

$_SERVER['SCRIPT_FILENAME'] = $target;
$_SERVER['SCRIPT_NAME'] = '/' . ltrim(str_replace('\\', '/', substr($target, strlen($root))), '/');
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];

require $target;
