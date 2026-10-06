<?php
if (!is_file(__DIR__ . '/plugin/games/edit.php')) { http_response_code(404); exit('Games plugin unavailable.'); }
require __DIR__ . '/plugin/games/edit.php';
