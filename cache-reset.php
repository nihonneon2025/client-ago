<?php
if (($_GET['key'] ?? '') === 'ago2026reset') {
    $result = opcache_reset();
    echo "OPcache reset: " . ($result ? 'OK' : 'FAILED') . " / " . date('Y-m-d H:i:s');
} else {
    http_response_code(403);
    echo "403";
}
