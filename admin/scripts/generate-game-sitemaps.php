<?php
if (!defined('GAMES_SCRIPT_ENTRY') && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) define('GAMES_SCRIPT_ENTRY', 'generate-game-sitemaps.php');
require_once __DIR__ . '/../games/scripts/generate-game-sitemaps.php';
