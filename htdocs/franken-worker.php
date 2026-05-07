<?php
// --- DUMMY HANDLER DEFINITIONS (Defined Once) ---
if (!function_exists('_fphp_dummy_open')) {
    function _fphp_dummy_open($path, $name) { return true; }
    function _fphp_dummy_close() { return true; }
    function _fphp_dummy_read($id) { return ''; }
    function _fphp_dummy_write($id, $data) { return true; }
    function _fphp_dummy_destroy($id) { return true; }
    function _fphp_dummy_gc($max) { return 0; }
}

// --- REGISTER HANDLER ONCE (Outside the Loop) ---
// This ensures the dummy handler is active for ALL requests in this worker process.
// No need to re-register it every time.
session_set_save_handler(
    '_fphp_dummy_open',
    '_fphp_dummy_close',
    '_fphp_dummy_read',
    '_fphp_dummy_write',
    '_fphp_dummy_destroy',
    '_fphp_dummy_gc'
);

// --- MAIN WORKER LOOP ---
if (function_exists('frankenphp_handle_request')) {
    while (frankenphp_handle_request(function() {
        // 1. Load the REQUESTED script dynamically
        // Caddy sets SCRIPT_FILENAME to the actual file (e.g., api/index.php)
        $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
        
        if (file_exists($script)) {
            require $script;
        } else {
            // Fallback if SCRIPT_FILENAME is missing or invalid
            require __DIR__ . '/index.php';
        }
        
        // NOTE: No session_write_close() needed.
        // The dummy handler does not write to disk, so there is nothing to close.
        // Removing this call saves CPU cycles per request.
        
    })) {
        // Loop continues
    }
} else {
    // Fallback: Not running in worker mode
    $script = $_SERVER['SCRIPT_FILENAME'] ?? __DIR__ . '/index.php';
    require $script;
}