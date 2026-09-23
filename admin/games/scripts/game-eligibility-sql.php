<?php
declare(strict_types=1);
// Export the public API predicates for the Python enrichment query; no DB writes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../includes/game-visibility.php';
echo json_encode([
    'eligible' => game_public_eligibility_sql(),
    'eligible_unprocessed' => 'NOT EXISTS (SELECT 1 FROM game_module_settings WHERE id=1 AND enabled=0) AND games.published = 1 AND (' . game_public_slug_sql() . ') AND (' . games_ph_allowed_sql('games.restrictions') . ') AND (' . game_visibility_sql() . ')',
    'provider' => game_provider_approved_sql(),
    'ph_allowed' => games_ph_allowed_sql('games.restrictions'),
], JSON_THROW_ON_ERROR);
