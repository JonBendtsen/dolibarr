<?php
// --- DUMMY HANDLER DEFINITIONS ---
if (!function_exists('_fphp_dummy_open')) {
    function _fphp_dummy_open($path, $name) { return true; }
    function _fphp_dummy_close() { return true; }
    function _fphp_dummy_read($id) { return ''; }
    function _fphp_dummy_write($id, $data) { return true; }
    function _fphp_dummy_destroy($id) { return true; }
    function _fphp_dummy_gc($max) { return 0; }
}

if (function_exists('frankenphp_handle_request')) {
    while (frankenphp_handle_request(function() {
        // 1. Set Dummy Handler
        session_set_save_handler(
            '_fphp_dummy_open', '_fphp_dummy_close', '_fphp_dummy_read',
            '_fphp_dummy_write', '_fphp_dummy_destroy', '_fphp_dummy_gc'
        );

        // 2. Close previous session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // 3. Load the REQUESTED script, NOT hardcoded index.php
        // Caddy sets SCRIPT_FILENAME to the actual file being requested (e.g., api/index.php)
        // But we need to be careful.
        // The safest way is to let PHP's default entry point logic run.
        // But since we are in a worker, we must include the file.
        
        // Check if the requested file exists
        $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
        if (file_exists($script)) {
            require $script;
        } else {
            // Fallback to index.php if SCRIPT_FILENAME is weird
            require __DIR__ . '/index.php';
        }

        // 4. Close session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    })) {
        // Loop
    }
} else {
    // Fallback
    $script = $_SERVER['SCRIPT_FILENAME'] ?? __DIR__ . '/index.php';
    require $script;
}