<?php
declare(strict_types=1);
require_once __DIR__ . '/game-images.php';

/** SlotsLaunch uses thumb; keep older payload aliases as fallbacks. */
function provider_image_from_payload(int $apiId, array $provider, ?callable $download = null, ?bool &$failed = null): string
{
    $failed = false;
    $source = '';
    foreach (['thumb','thumbnail','image','logo','icon'] as $field) {
        if (is_string($provider[$field] ?? null) && trim($provider[$field]) !== '') {
            $source = trim($provider[$field]);
            break;
        }
    }
    if ($source === '') return '';
    try { return provider_image_localize($apiId,$source,$download); }
    catch (Throwable $error) {
        $failed = true;
        fwrite(STDERR,'Provider ' . $apiId . ': thumbnail unavailable; retry the sync.' . "\n");
        return '';
    }
}

function provider_image_directory(): string
{
    return env_value('PROVIDER_IMAGE_UPLOAD_DIR') ?: site_root_path() . '/uploads/providers';
}

function provider_image_local_valid(string $url): bool
{
    if (!preg_match('~^/uploads/providers/([a-zA-Z0-9._-]+\.(?:jpe?g|png|webp|avif))$~D',$url,$match)) return false;
    $path = provider_image_directory() . '/' . $match[1];
    $extension = game_image_valid($path);
    return $extension !== null && ($extension === strtolower(pathinfo($path,PATHINFO_EXTENSION)) || ($extension === 'jpg' && str_ends_with(strtolower($path),'.jpeg')));
}

function provider_image_localize(int $apiId, string $source, ?callable $download = null): string
{
    $source = trim($source);
    if (provider_image_local_valid($source)) return $source;
    if (!game_image_source_valid($source)) throw new RuntimeException('missing or invalid image URL');
    $directory = provider_image_directory();
    if (!is_dir($directory) && !@mkdir($directory,0755,true) && !is_dir($directory)) throw new RuntimeException('image directory cannot be created');
    $stem = 'provider-' . abs($apiId) . '-' . substr(hash('sha256',$source),0,24);
    foreach (['jpg','jpeg','png','webp','avif'] as $extension) {
        $local = '/uploads/providers/' . $stem . '.' . $extension;
        if (provider_image_local_valid($local)) return $local;
    }
    $temp = tempnam($directory,'.image-');
    if (!$temp) throw new RuntimeException('temporary image cannot be created');
    try {
        ($download ?? 'game_image_download')($source,$temp);
        $extension = game_image_valid($temp);
        if ($extension === null) throw new RuntimeException('download is not a supported valid image');
        $target = $directory . '/' . $stem . '.' . $extension;
        if (is_link($target)) throw new RuntimeException('unsafe image destination');
        if (!chmod($temp,0644) || !rename($temp,$target)) throw new RuntimeException('validated image could not be stored');
        return '/uploads/providers/' . $stem . '.' . $extension;
    } finally { if (is_file($temp)) unlink($temp); }
}
