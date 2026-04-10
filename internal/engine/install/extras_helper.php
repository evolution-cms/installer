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
        $installFunctions = $projectPath . '/core/.evo-installer-runtime/install/src/functions.php';
    }
    if (!is_file($installFunctions)) {
        helper_fail("install/src/functions.php is not available.");
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

function helper_parse_csv(string $value): array
{
    return array_values(array_filter(array_map('trim', explode(',', $value)), static function ($value) {
        return $value !== '';
    }));
}

function helper_install_template(array $item, string $path): void
{
    $name = trim((string) ($item['name'] ?? ''));
    if ($name === '') {
        return;
    }
    $desc = trim((string) ($item['description'] ?? ''));
    $category = getCreateDbCategory(trim((string) ($item['category'] ?? '')));
    $locked = !empty($item['locked']) ? 1 : 0;
    $code = helper_chunk_code($path);
    $template = \EvolutionCMS\Models\SiteTemplate::query()->where('templatename', $name)->first();
    if ($template !== null) {
        \EvolutionCMS\Models\SiteTemplate::query()->where('templatename', $name)->update([
            'content' => $code,
            'description' => $desc,
            'category' => $category,
            'locked' => $locked,
        ]);
    } else {
        \EvolutionCMS\Models\SiteTemplate::query()->create([
            'templatename' => $name,
            'content' => $code,
            'description' => $desc,
            'category' => $category,
            'locked' => $locked,
        ]);
    }

    helper_log("Installed template: {$name}");
}

function helper_install_tv(array $item, string $path): void
{
    unset($path);

    $name = trim((string) ($item['name'] ?? ''));
    if ($name === '') {
        return;
    }
    $desc = trim((string) ($item['description'] ?? ''));
    $caption = trim((string) ($item['caption'] ?? ''));
    $category = getCreateDbCategory(trim((string) ($item['category'] ?? '')));
    $locked = !empty($item['locked']) ? 1 : 0;
    $assignments = $item['assignments'] ?? [];
    if (!is_array($assignments)) {
        $assignments = helper_parse_csv((string) $assignments);
    }

    $tv = \EvolutionCMS\Models\SiteTmplvar::query()->updateOrCreate(
        ['name' => $name],
        [
            'type' => trim((string) ($item['inputType'] ?? '')),
            'caption' => $caption,
            'description' => $desc,
            'category' => $category,
            'locked' => $locked,
            'elements' => trim((string) ($item['inputOptions'] ?? '')),
            'display' => trim((string) ($item['outputWidget'] ?? '')),
            'display_params' => trim((string) ($item['outputWidgetParams'] ?? '')),
            'default_text' => trim((string) ($item['inputDefault'] ?? '')),
        ]
    );

    \EvolutionCMS\Models\SiteTmplvarTemplate::query()->where('tmplvarid', $tv->getKey())->delete();
    foreach ($assignments as $assignment) {
        $templateName = trim((string) $assignment);
        if ($templateName === '') {
            continue;
        }
        $template = \EvolutionCMS\Models\SiteTemplate::query()->where('templatename', $templateName)->first();
        if ($template === null) {
            continue;
        }
        \EvolutionCMS\Models\SiteTmplvarTemplate::query()->firstOrCreate([
            'tmplvarid' => $tv->getKey(),
            'templateid' => $template->getKey(),
        ]);
    }

    helper_log("Installed TV: {$name}");
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
    $overwrite = strtolower(trim((string) ($item['overwrite'] ?? 'true')));
    $code = helper_chunk_code($path);
    $chunkRecord = \EvolutionCMS\Models\SiteHtmlsnippet::query()->where('name', $name)->first();
    $countNewName = 0;
    if ($overwrite === 'false') {
        $newName = $name . '-' . str_replace('.', '_', (string) evo()->getVersionData('version'));
        $countNewName = \EvolutionCMS\Models\SiteHtmlsnippet::query()->where('name', $newName)->count();
    }
    $update = $chunkRecord !== null && $overwrite === 'true';
    if ($update) {
        \EvolutionCMS\Models\SiteHtmlsnippet::query()->where('name', $name)->update([
            'snippet' => $code,
            'description' => $desc,
            'category' => $category,
        ]);
        helper_log("Installed chunk: {$name}");
        return;
    }
    if ($countNewName === 0) {
        if ($chunkRecord !== null && $overwrite === 'false') {
            $name = $newName;
        }
        \EvolutionCMS\Models\SiteHtmlsnippet::query()->create([
            'name' => $name,
            'snippet' => $code,
            'description' => $desc,
            'category' => $category,
        ]);
        helper_log("Installed chunk: {$name}");
        return;
    }

    helper_log("Skipped chunk (already exists and overwrite=false): {$name}");
}

function helper_docblock_entries(string $dir): array
{
    $entries = [];
    if (!is_dir($dir)) {
        return $entries;
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
        $entries[] = [
            'path' => $dir . DIRECTORY_SEPARATOR . $file,
            'params' => $params,
            'description' => $description,
        ];
    }
    return $entries;
}

function helper_collect_install_assets(string $assetRoot): array
{
    $items = [];
    $dependencies = [];
    $templateNames = [];

    foreach (helper_docblock_entries($assetRoot . '/templates') as $entry) {
        $params = $entry['params'];
        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $templateNames[] = $name;
        $items[] = [
            'kind' => 'template',
            'name' => $name,
            'description' => $entry['description'],
            'path' => $entry['path'],
            'category' => trim((string) ($params['modx_category'] ?? '')),
            'locked' => (int) trim((string) ($params['lock_template'] ?? '0')),
        ];
    }

    foreach (helper_docblock_entries($assetRoot . '/tvs') as $entry) {
        $params = $entry['params'];
        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $assignments = trim((string) ($params['template_assignments'] ?? ''));
        if ($assignments === '*') {
            $assignments = $templateNames;
        } else {
            $assignments = helper_parse_csv($assignments);
        }
        $items[] = [
            'kind' => 'tv',
            'name' => $name,
            'caption' => trim((string) ($params['caption'] ?? '')),
            'description' => $entry['description'],
            'path' => $entry['path'],
            'category' => trim((string) ($params['modx_category'] ?? '')),
            'locked' => (int) trim((string) ($params['lock_tv'] ?? '0')),
            'inputType' => trim((string) ($params['input_type'] ?? '')),
            'inputOptions' => trim((string) ($params['input_options'] ?? '')),
            'inputDefault' => trim((string) ($params['input_default'] ?? '')),
            'outputWidget' => trim((string) ($params['output_widget'] ?? '')),
            'outputWidgetParams' => trim((string) ($params['output_widget_params'] ?? '')),
            'assignments' => $assignments,
        ];
    }

    foreach (helper_docblock_entries($assetRoot . '/chunks') as $entry) {
        $params = $entry['params'];
        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $items[] = [
            'kind' => 'chunk',
            'name' => $name,
            'description' => $entry['description'],
            'path' => $entry['path'],
            'category' => trim((string) ($params['modx_category'] ?? '')),
            'overwrite' => trim((string) ($params['overwrite'] ?? 'true')),
        ];
    }

    foreach (helper_docblock_entries($assetRoot . '/snippets') as $entry) {
        $params = $entry['params'];
        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $items[] = [
            'kind' => 'snippet',
            'name' => $name,
            'description' => $entry['description'],
            'path' => $entry['path'],
            'properties' => trim((string) ($params['properties'] ?? '')),
            'category' => trim((string) ($params['modx_category'] ?? '')),
        ];
    }

    foreach (helper_docblock_entries($assetRoot . '/plugins') as $entry) {
        $params = $entry['params'];
        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $items[] = [
            'kind' => 'plugin',
            'name' => $name,
            'description' => $entry['description'],
            'path' => $entry['path'],
            'properties' => trim((string) ($params['properties'] ?? '')),
            'events' => trim((string) ($params['events'] ?? '')),
            'guid' => trim((string) ($params['guid'] ?? '')),
            'category' => trim((string) ($params['modx_category'] ?? '')),
            'legacyNames' => trim((string) ($params['legacy_names'] ?? '')),
            'disabled' => trim((string) ($params['disabled'] ?? '')) === '1',
        ];
    }

    foreach (helper_docblock_entries($assetRoot . '/modules') as $entry) {
        $params = $entry['params'];
        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $items[] = [
            'kind' => 'module',
            'name' => $name,
            'description' => $entry['description'],
            'path' => $entry['path'],
            'properties' => trim((string) ($params['properties'] ?? '')),
            'guid' => trim((string) ($params['guid'] ?? '')),
            'category' => trim((string) ($params['modx_category'] ?? '')),
            'shareParams' => (int) trim((string) ($params['shareparams'] ?? '0')),
            'icon' => trim((string) ($params['icon'] ?? '')),
        ];

        $moduleDependencies = helper_parse_csv((string) ($params['dependencies'] ?? ''));
        foreach ($moduleDependencies as $dependency) {
            $parts = array_map('trim', explode(':', $dependency, 2));
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                continue;
            }
            switch ($parts[0]) {
                case 'template':
                    $dependencies[] = ['module' => $name, 'kind' => 'template', 'name' => $parts[1], 'type' => 50];
                    break;
                case 'tv':
                case 'tmplvar':
                    $dependencies[] = ['module' => $name, 'kind' => 'tv', 'name' => $parts[1], 'type' => 60];
                    break;
                case 'chunk':
                case 'htmlsnippet':
                    $dependencies[] = ['module' => $name, 'kind' => 'chunk', 'name' => $parts[1], 'type' => 10];
                    break;
                case 'snippet':
                    $dependencies[] = ['module' => $name, 'kind' => 'snippet', 'name' => $parts[1], 'type' => 40];
                    break;
                case 'plugin':
                    $dependencies[] = ['module' => $name, 'kind' => 'plugin', 'name' => $parts[1], 'type' => 30];
                    break;
                case 'resource':
                    $dependencies[] = ['module' => $name, 'kind' => 'resource', 'name' => $parts[1], 'type' => 20];
                    break;
            }
        }
    }

    return [
        'items' => $items,
        'dependencies' => $dependencies,
    ];
}

function helper_import_items(array $items): void
{
    foreach ($items as $item) {
        $path = (string) ($item['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            continue;
        }
        switch ((string) ($item['kind'] ?? '')) {
            case 'template':
                helper_install_template($item, $path);
                break;
            case 'tv':
                helper_install_tv($item, $path);
                break;
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

function helper_install_module_dependencies(array $dependencies): void
{
    foreach ($dependencies as $dependency) {
        $moduleName = trim((string) ($dependency['module'] ?? ''));
        $dependencyName = trim((string) ($dependency['name'] ?? ''));
        if ($moduleName === '' || $dependencyName === '') {
            continue;
        }

        $module = \EvolutionCMS\Models\SiteModule::query()->where('name', $moduleName)->first();
        if ($module === null) {
            continue;
        }

        $resourceId = null;
        switch ((string) ($dependency['kind'] ?? '')) {
            case 'template':
                $resource = \EvolutionCMS\Models\SiteTemplate::query()->where('templatename', $dependencyName)->first();
                $resourceId = $resource?->getKey();
                break;
            case 'tv':
                $resource = \EvolutionCMS\Models\SiteTmplvar::query()->where('name', $dependencyName)->first();
                $resourceId = $resource?->getKey();
                break;
            case 'chunk':
                $resource = \EvolutionCMS\Models\SiteHtmlsnippet::query()->where('name', $dependencyName)->first();
                $resourceId = $resource?->getKey();
                break;
            case 'snippet':
                $resource = \EvolutionCMS\Models\SiteSnippet::query()->where('name', $dependencyName)->first();
                $resourceId = $resource?->getKey();
                break;
            case 'plugin':
                $resource = \EvolutionCMS\Models\SitePlugin::query()->where('name', $dependencyName)->first();
                $resourceId = $resource?->getKey();
                break;
            case 'resource':
                $resource = \EvolutionCMS\Models\SiteContent::query()->where('pagetitle', $dependencyName)->first();
                $resourceId = $resource?->getKey();
                break;
        }

        if ($resourceId === null) {
            continue;
        }

        \EvolutionCMS\Models\SiteModuleDepobj::query()->updateOrCreate([
            'module' => (int) $module->getKey(),
            'resource' => (int) $resourceId,
            'type' => (int) ($dependency['type'] ?? 0),
        ]);

        if ((int) ($dependency['type'] ?? 0) === 30) {
            \EvolutionCMS\Models\SitePlugin::query()->where('id', $resourceId)->update(['moduleguid' => (string) $module->guid]);
        }
        if ((int) ($dependency['type'] ?? 0) === 40) {
            \EvolutionCMS\Models\SiteSnippet::query()->where('id', $resourceId)->update(['moduleguid' => (string) $module->guid]);
        }

        helper_log("Linked module dependency: {$moduleName} -> {$dependencyName}");
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
        if (
            is_dir($path . '/plugins') ||
            is_dir($path . '/modules') ||
            is_dir($path . '/snippets') ||
            is_dir($path . '/chunks') ||
            is_dir($path . '/templates') ||
            is_dir($path . '/tvs')
        ) {
            if (basename($path) === 'assets') {
                return $path;
            }
        }
    }
    return null;
}

function helper_detect_legacy_install_profile(array $items, array $dependencies, string $packageRoot): string
{
    $kinds = [];
    foreach ($items as $item) {
        $kind = trim((string) ($item['kind'] ?? ''));
        if ($kind !== '') {
            $kinds[$kind] = true;
        }
    }
    if (isset($kinds['template']) || isset($kinds['tv'])) {
        return 'full';
    }
    if (count($items) > 1 || count($kinds) > 1 || count($dependencies) > 0) {
        return 'full';
    }
    if (is_file($packageRoot . '/setup.sql') || is_file($packageRoot . '/setup.data.sql')) {
        return 'full';
    }
    return 'fast';
}

function helper_process_sql_file(string $path): void
{
    $parserPath = EVO_BASE_PATH . 'assets/modules/store/installer/sqlParser.class.php';
    if (!is_file($parserPath)) {
        helper_fail('Legacy SQL payload detected, but SqlParser is not available in the project.');
    }
    require_once $parserPath;
    $sqlParser = new SqlParser();
    $sqlParser->mode = 'upd';
    $sqlParser->ignoreDuplicateErrors = true;
    $sqlParser->process($path);
    if (!empty($sqlParser->installFailed)) {
        $error = $sqlParser->mysqlErrors[0]['error'] ?? 'unknown SQL import error';
        helper_fail('Legacy package SQL import failed: ' . $error);
    }
    helper_log('Applied legacy SQL payload: ' . basename($path));
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
    $setupSql = $packageRoot . '/setup.sql';
    $setupDataSql = $packageRoot . '/setup.data.sql';

    $assetRoot = helper_find_asset_root($packageRoot);
    if ($assetRoot === null) {
        if (is_file($setupSql)) {
            helper_process_sql_file($setupSql);
        }
        if (is_file($setupDataSql)) {
            helper_log('Legacy sample-data SQL detected and skipped by default: ' . basename($setupDataSql));
        }
        helper_log('Legacy package copied; no install/assets payload detected for inline import.');
        return;
    }

    $payload = helper_collect_install_assets($assetRoot);
    $items = $payload['items'] ?? [];
    $dependencies = $payload['dependencies'] ?? [];
    $profile = helper_detect_legacy_install_profile($items, $dependencies, $packageRoot);
    helper_log('Legacy package install profile: ' . $profile);
    helper_import_items($items);
    helper_install_module_dependencies($dependencies);

    if (is_file($setupSql)) {
        helper_process_sql_file($setupSql);
    }

    if (is_file($setupDataSql)) {
        helper_log('Legacy sample-data SQL detected and skipped by default: ' . basename($setupDataSql));
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
