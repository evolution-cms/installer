<?php

declare(strict_types=1);

function helper_fail(string $message, int $code = 1): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

function helper_log(string $message): void
{
    echo trim($message) . PHP_EOL;
}

function helper_read_json(string $path): array
{
    if (!is_file($path)) {
        helper_fail("Payload file not found: {$path}");
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        helper_fail("Unable to read payload: {$path}");
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        helper_fail("Payload is not valid JSON.");
    }
    return $data;
}

function helper_bootstrap(string $projectPath): void
{
    defined('EVO_API_MODE') || define('EVO_API_MODE', true);
    defined('IN_INSTALL_MODE') || define('IN_INSTALL_MODE', true);
    defined('EVO_CLI') || define('EVO_CLI', true);
    defined('EVO_BASE_PATH') || define('EVO_BASE_PATH', rtrim($projectPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    defined('EVO_CORE_PATH') || define('EVO_CORE_PATH', EVO_BASE_PATH . 'core' . DIRECTORY_SEPARATOR);
    defined('EVO_SITE_URL') || define('EVO_SITE_URL', '/');

    chdir($projectPath);
    $bootstrap = is_file($projectPath . '/core/bootstrap.php')
        ? $projectPath . '/core/bootstrap.php'
        : $projectPath . '/bootstrap.php';
    if (!is_file($bootstrap)) {
        helper_fail("Project bootstrap not found.");
    }
    require_once $bootstrap;

    $installFunctions = $projectPath . '/install/src/functions.php';
    if (!is_file($installFunctions)) {
        helper_fail("install/src/functions.php is not available. Run extras before finalize removes install/.");
    }
    require_once $installFunctions;
}

function helper_remove_installer_docblock(string $code): string
{
    return preg_replace("/^.*?\/\*\*.*?\*\/\s+/s", '', $code, 1) ?? $code;
}

function helper_plugin_code(string $path): string
{
    $parts = preg_split("/(\/\/)?\s*\<\?php/", (string) file_get_contents($path), 2);
    $code = end($parts);
    return helper_remove_installer_docblock((string) $code);
}

function helper_module_code(string $path): string
{
    $parts = preg_split("/(\/\/)?\s*\<\?php/", (string) file_get_contents($path), 2);
    $code = end($parts);
    return helper_remove_installer_docblock((string) $code);
}

function helper_snippet_code(string $path): string
{
    return helper_remove_installer_docblock((string) file_get_contents($path));
}

function helper_chunk_code(string $path): string
{
    return helper_remove_installer_docblock((string) file_get_contents($path));
}

function helper_install_plugin(array $item, string $path): void
{
    $name = trim((string) ($item['name'] ?? ''));
    if ($name === '') {
        return;
    }
    $desc = trim((string) ($item['description'] ?? ''));
    $properties = trim((string) ($item['properties'] ?? ''));
    $guid = trim((string) ($item['guid'] ?? ''));
    $category = getCreateDbCategory(trim((string) ($item['category'] ?? '')));
    $legacyNames = array_filter(array_map('trim', explode(',', (string) ($item['legacyNames'] ?? ''))));
    $events = array_filter(array_map('trim', explode(',', (string) ($item['events'] ?? ''))));
    $disabled = !empty($item['disabled']) ? 1 : 0;

    if (count($legacyNames) > 0) {
        \EvolutionCMS\Models\SitePlugin::query()->whereIn('name', $legacyNames)->update(['disabled' => 1]);
    }

    $plugin = helper_plugin_code($path);
    $pluginDbRecord = \EvolutionCMS\Models\SitePlugin::where('name', $name)->orderBy('id');
    $prevId = null;

    if ($pluginDbRecord->count() > 0) {
        $insert = true;
        foreach ($pluginDbRecord->get()->toArray() as $row) {
            $props = propUpdate($properties, $row['properties']);
            if ($row['description'] == $desc) {
                \EvolutionCMS\Models\SitePlugin::query()->where('id', $row['id'])->update([
                    'plugincode' => $plugin,
                    'description' => $desc,
                    'properties' => $props,
                ]);
                $insert = false;
            } else {
                \EvolutionCMS\Models\SitePlugin::query()->where('id', $row['id'])->update(['disabled' => 1]);
            }
            $prevId = $row['id'];
        }
        if ($insert === true) {
            $props = propUpdate($properties, $row['properties'] ?? '');
            \EvolutionCMS\Models\SitePlugin::query()->create([
                'name' => $name,
                'plugincode' => $plugin,
                'description' => $desc,
                'properties' => $props,
                'moduleguid' => $guid,
                'disabled' => 0,
                'category' => $category,
            ]);
        }
    } else {
        \EvolutionCMS\Models\SitePlugin::query()->create([
            'name' => $name,
            'plugincode' => $plugin,
            'description' => $desc,
            'properties' => parseProperties($properties, true),
            'moduleguid' => $guid,
            'disabled' => $disabled,
            'category' => $category,
        ]);
    }

    if (count($events) > 0) {
        $sitePlugin = \EvolutionCMS\Models\SitePlugin::where('name', $name)->where('description', $desc)->first();
        if ($sitePlugin !== null) {
            $id = $sitePlugin->id;
            foreach ($events as $event) {
                $eventName = \EvolutionCMS\Models\SystemEventname::where('name', $event)->first();
                if ($eventName === null) {
                    continue;
                }
                $prevPriority = null;
                if ($prevId) {
                    $pluginEvent = \EvolutionCMS\Models\SitePluginEvent::query()
                        ->where('pluginid', $prevId)
                        ->where('evtid', $eventName->getKey())
                        ->first();
                    if ($pluginEvent !== null) {
                        $prevPriority = $pluginEvent->priority;
                    }
                }
                if ($prevPriority === null) {
                    $pluginEvent = \EvolutionCMS\Models\SitePluginEvent::query()
                        ->where('evtid', $eventName->getKey())
                        ->orderBy('priority', 'DESC')
                        ->first();
                    if ($pluginEvent !== null) {
                        $prevPriority = $pluginEvent->priority + 1;
                    }
                }
                if ($prevPriority === null) {
                    $prevPriority = 0;
                }
                \EvolutionCMS\Models\SitePluginEvent::query()->firstOrCreate([
                    'pluginid' => $id,
                    'evtid' => $eventName->getKey(),
                    'priority' => $prevPriority,
                ]);
            }

            \EvolutionCMS\Models\SitePluginEvent::query()
                ->join('system_eventnames', function ($join) use ($events) {
                    $join->on('site_plugin_events.evtid', '=', 'system_eventnames.id')
                        ->whereIn('name', $events);
                })
                ->whereNull('name')
                ->where('pluginid', $id)
                ->delete();
        }
    }

    helper_log("Installed plugin: {$name}");
}

function helper_install_module(array $item, string $path): void
{
    $name = trim((string) ($item['name'] ?? ''));
    if ($name === '') {
        return;
    }
    $desc = trim((string) ($item['description'] ?? ''));
    $properties = trim((string) ($item['properties'] ?? ''));
    $guid = trim((string) ($item['guid'] ?? ''));
    $shared = (int) ($item['shareParams'] ?? 0);
    $icon = trim((string) ($item['icon'] ?? ''));
    $category = getCreateDbCategory(trim((string) ($item['category'] ?? '')));
    $module = helper_module_code($path);
    $moduleDb = \EvolutionCMS\Models\SiteModule::query()->where('name', $name)->first();
    if ($moduleDb !== null) {
        $props = propUpdate($properties, $moduleDb->properties);
        \EvolutionCMS\Models\SiteModule::query()->where('name', $name)->update([
            'modulecode' => $module,
            'description' => $desc,
            'properties' => $props,
            'enable_sharedparams' => $shared,
            'icon' => $icon,
        ]);
    } else {
        \EvolutionCMS\Models\SiteModule::query()->create([
            'name' => $name,
            'guid' => $guid,
            'category' => $category,
            'modulecode' => $module,
            'description' => $desc,
            'properties' => parseProperties($properties, true),
            'enable_sharedparams' => $shared,
            'icon' => $icon,
        ]);
    }

    helper_log("Installed module: {$name}");
}

function helper_install_snippet(array $item, string $path): void
{
    $name = trim((string) ($item['name'] ?? ''));
    if ($name === '') {
        return;
    }
    $desc = trim((string) ($item['description'] ?? ''));
    $properties = trim((string) ($item['properties'] ?? ''));
    $category = getCreateDbCategory(trim((string) ($item['category'] ?? '')));
    $code = helper_snippet_code($path);
    $snippet = \EvolutionCMS\Models\SiteSnippet::query()->where('name', $name)->first();
    if ($snippet !== null) {
        $props = propUpdate($properties, $snippet->properties);
        \EvolutionCMS\Models\SiteSnippet::query()->where('name', $name)->update([
            'snippet' => $code,
            'description' => $desc,
            'properties' => $props,
            'category' => $category,
        ]);
    } else {
        \EvolutionCMS\Models\SiteSnippet::query()->create([
            'name' => $name,
            'snippet' => $code,
            'description' => $desc,
            'properties' => parseProperties($properties, true),
            'category' => $category,
        ]);
    }

    helper_log("Installed snippet: {$name}");
}

function helper_install_chunk(array $item, string $path): void
{
    $name = trim((string) ($item['name'] ?? ''));
    if ($name === '') {
        return;
    }
    $desc = trim((string) ($item['description'] ?? ''));
    $category = getCreateDbCategory(trim((string) ($item['category'] ?? '')));
    $code = helper_chunk_code($path);
    $chunk = \EvolutionCMS\Models\SiteHtmlsnippet::query()->where('name', $name)->first();
    if ($chunk !== null) {
        \EvolutionCMS\Models\SiteHtmlsnippet::query()->where('name', $name)->update([
            'snippet' => $code,
            'description' => $desc,
            'category' => $category,
        ]);
    } else {
        \EvolutionCMS\Models\SiteHtmlsnippet::query()->create([
            'name' => $name,
            'snippet' => $code,
            'description' => $desc,
            'category' => $category,
        ]);
    }

    helper_log("Installed chunk: {$name}");
}

function helper_scan_tpl_dir(string $dir, string $kind): array
{
    $items = [];
    if (!is_dir($dir)) {
        return $items;
    }
    $files = scandir($dir) ?: [];
    foreach ($files as $file) {
        if (!str_ends_with($file, '.tpl')) {
            continue;
        }
        $params = parse_docblock($dir, $file);
        if (!is_array($params) || count($params) === 0) {
            continue;
        }
        $description = trim((string) ($params['description'] ?? ''));
        $version = trim((string) ($params['version'] ?? ''));
        if ($version !== '') {
            $description = "<strong>{$version}</strong> {$description}";
        }
        $items[] = [
            'kind' => $kind,
            'name' => trim((string) ($params['name'] ?? '')),
            'description' => $description,
            'path' => $dir . DIRECTORY_SEPARATOR . $file,
            'properties' => trim((string) ($params['properties'] ?? '')),
            'events' => trim((string) ($params['events'] ?? '')),
            'guid' => trim((string) ($params['guid'] ?? '')),
            'category' => trim((string) ($params['modx_category'] ?? '')),
            'legacyNames' => trim((string) ($params['legacy_names'] ?? '')),
            'disabled' => trim((string) ($params['disabled'] ?? '')) === '1',
            'shareParams' => (int) trim((string) ($params['shareparams'] ?? '0')),
            'icon' => trim((string) ($params['icon'] ?? '')),
        ];
    }
    return $items;
}

function helper_import_items(array $items): void
{
    foreach ($items as $item) {
        $path = (string) ($item['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            continue;
        }
        switch ((string) ($item['kind'] ?? '')) {
            case 'plugin':
                helper_install_plugin($item, $path);
                break;
            case 'module':
                helper_install_module($item, $path);
                break;
            case 'snippet':
                helper_install_snippet($item, $path);
                break;
            case 'chunk':
                helper_install_chunk($item, $path);
                break;
        }
    }
}

function helper_recursive_copy(string $src, string $dest): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $target = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0775, true);
            }
            continue;
        }
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        copy($item->getPathname(), $target);
    }
}

function helper_download_file(string $url, string $target): void
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
            'follow_location' => 1,
            'user_agent' => 'EvolutionCMS-Installer',
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false || $raw === '') {
        helper_fail("Unable to download legacy package: {$url}");
    }
    if (file_put_contents($target, $raw) === false) {
        helper_fail("Unable to write downloaded package.");
    }
}

function helper_find_package_root(string $extractDir): string
{
    $entries = array_values(array_filter(scandir($extractDir) ?: [], function ($name) use ($extractDir) {
        return $name !== '.' && $name !== '..' && is_dir($extractDir . DIRECTORY_SEPARATOR . $name);
    }));
    if (count($entries) === 1) {
        return $extractDir . DIRECTORY_SEPARATOR . $entries[0];
    }
    return $extractDir;
}

function helper_find_asset_root(string $baseDir): ?string
{
    $candidates = [
        $baseDir . '/install/assets',
        $baseDir . '/assets',
    ];
    foreach ($candidates as $candidate) {
        if (is_dir($candidate)) {
            return $candidate;
        }
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if (!$item->isDir()) {
            continue;
        }
        $path = $item->getPathname();
        if (is_dir($path . '/plugins') || is_dir($path . '/modules') || is_dir($path . '/snippets') || is_dir($path . '/chunks')) {
            if (basename($path) === 'assets') {
                return $path;
            }
        }
    }
    return null;
}

function helper_install_legacy_store_package(string $projectPath, array $item): void
{
    $url = trim((string) ($item['downloadUrl'] ?? ''));
    if ($url === '') {
        helper_fail('Legacy store package is missing a download URL.');
    }

    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evo-installer-legacy-' . bin2hex(random_bytes(6));
    mkdir($tempDir, 0775, true);
    $zipPath = $tempDir . '/package.zip';
    $extractDir = $tempDir . '/extract';
    mkdir($extractDir, 0775, true);

    helper_download_file($url, $zipPath);
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        helper_fail("Unable to open legacy package archive.");
    }
    $zip->extractTo($extractDir);
    $zip->close();

    $packageRoot = helper_find_package_root($extractDir);
    helper_recursive_copy($packageRoot, $projectPath);

    $assetRoot = helper_find_asset_root($packageRoot);
    if ($assetRoot === null) {
        helper_log('Legacy package copied; no install/assets payload detected for inline import.');
        return;
    }

    $items = [];
    $items = array_merge($items, helper_scan_tpl_dir($assetRoot . '/chunks', 'chunk'));
    $items = array_merge($items, helper_scan_tpl_dir($assetRoot . '/snippets', 'snippet'));
    $items = array_merge($items, helper_scan_tpl_dir($assetRoot . '/plugins', 'plugin'));
    $items = array_merge($items, helper_scan_tpl_dir($assetRoot . '/modules', 'module'));
    helper_import_items($items);

    $sqlCandidates = [
        dirname($assetRoot) . '/setup.sql',
        dirname($assetRoot) . '/setup.data.sql',
    ];
    foreach ($sqlCandidates as $candidate) {
        if (is_file($candidate)) {
            helper_log('SQL payload detected and skipped for now: ' . basename($candidate));
        }
    }

    helper_log('Installed legacy package: ' . trim((string) ($item['name'] ?? 'package')));
}

$projectPath = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$payloadPath = $argv[3] ?? '';

if ($projectPath === '' || $mode === '' || $payloadPath === '') {
    helper_fail('Usage: php extras_helper.php <project-path> <mode> <payload.json>');
}

$projectPath = rtrim((string) realpath($projectPath), DIRECTORY_SEPARATOR);
if ($projectPath === '') {
    helper_fail('Project path is invalid.');
}

helper_bootstrap($projectPath);
$payload = helper_read_json($payloadPath);

switch ($mode) {
    case 'bundled-inline':
        $items = $payload['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            helper_fail('No bundled inline items were provided.');
        }
        foreach ($items as &$item) {
            if (!isset($item['path'])) {
                continue;
            }
            $item['path'] = $projectPath . DIRECTORY_SEPARATOR . ltrim((string) $item['path'], DIRECTORY_SEPARATOR);
        }
        unset($item);
        helper_import_items($items);
        break;

    case 'legacy-store':
        $item = $payload['item'] ?? null;
        if (!is_array($item)) {
            helper_fail('No legacy package payload was provided.');
        }
        helper_install_legacy_store_package($projectPath, $item);
        break;

    default:
        helper_fail('Unsupported helper mode: ' . $mode);
}
