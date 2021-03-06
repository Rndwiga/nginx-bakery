<?php
/**
 * An "old-school-style" php script to create nginx configuration from a bunch of includes and a master configuration.
 *
 * @author Kevin Papst
 * @see https://github.com/kevinpapst/nginx-bakery
 */
define('NXB_EOL', PHP_EOL);
define('NXB_TAB', "    ");
define('NXB_INC', __DIR__.'/ingredients/');
define('NXB_VERSION', '0.2');
define('NXB_VERBOSE', true);

// sites.php will not be commited to the git repository, so you can safely keep your site configurations there
if (!file_exists(__DIR__ . '/sites.php')) {
    die(NXB_EOL . 'There is no "sites.php". Please rename "sites.php.dist" to "sites.php"'.NXB_EOL);
}

// bakery configs
$CONFIG = include_once(__DIR__ . '/config.php');
// allow to override or add new settings
if (file_exists(__DIR__ . '/config.local.php')) {
    $CONFIG2 = include_once(__DIR__ . '/config.local.php');
    $CONFIG = array_replace_recursive($CONFIG, $CONFIG2);
    unset($CONFIG2);
}

// users site config
$SITES  = include_once(__DIR__ . '/sites.php');

// make sure we have all directories we need
if (!file_exists($CONFIG['target']['includes'])) { mkdir($CONFIG['target']['includes']);}
if (!file_exists($CONFIG['target']['sites'])) { mkdir($CONFIG['target']['sites']);}

// check if we can write the directories
if (!is_writable($CONFIG['target']['includes']) || !is_writable($CONFIG['target']['sites'])) {
    die('Make sure the target directories are writable.'.PHP_EOL.'Check "' . $CONFIG['target']['includes'] . '" and "'.$CONFIG['target']['sites'].'".');
}

// ====================================================================
// fetch all include files
$ritit = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(NXB_INC, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
$r = array();
foreach ($ritit as $splFileInfo) {
    $path = $splFileInfo->isDir() ? array($splFileInfo->getFilename() => array()) : array($splFileInfo->getFilename());
    for ($depth = $ritit->getDepth() - 1; $depth >= 0; $depth--) {
        $path = array($ritit->getSubIterator($depth)->current()->getFilename() => $path);
    }
    $r = array_merge_recursive($r, $path);
}

// ====================================================================
// header, just to let people know where they can find more usage infos
echo NXB_EOL . 'nginx-bakery '.NXB_VERSION.' - more infos at https://github.com/kevinpapst/nginx-bakery' . NXB_EOL;

// ====================================================================
// now generate all of them and save them in the target directory
foreach($r as $incFolder => $includes)
{
    // README.md for example
    if (!is_array($includes)) continue;

    // target directory to store "includes"
    $includeTargetDir = $CONFIG['target']['includes'] . '/' . $incFolder;

    // make sure the target directory exists
    if (!file_exists($includeTargetDir)) {
        if (!mkdir($includeTargetDir)) {
            die('Could not create target directory: ' . $includeTargetDir);
        }
    }

    // render includes
    foreach($includes as $incFilename)
    {
        if ($incFilename == '.gitignore') {
            continue;
        }

        ob_start();

        // do the magic
        nginx_bakery_render_include($incFolder . '/' .$incFilename);

        // get output buffer and save it as "include" config file
        $incTarget = $includeTargetDir . '/' . $incFilename;
        file_put_contents($incTarget, ob_get_clean());
        nginx_bakery_log("Rendered include: " . $incFolder . '/' .$incFilename);
    }
}

// ====================================================================
// generate server configs
foreach($SITES as $sitename => $siteServers)
{
    ob_start();

    // do the magix
    nginx_bakery_render_site($sitename, $siteServers);

    // get output buffer and save it as "server" config file
    $tplTarget = $CONFIG['target']['sites'] . '/' . $sitename;
    file_put_contents($tplTarget, ob_get_clean());
    nginx_bakery_log("Rendered config [$sitename] to: " . $tplTarget);
}

echo NXB_EOL . NXB_EOL;

// ====================================================================
// Script stuff ends here - functions follow
// ====================================================================

function nginx_bakery_log($msg)
{
    if (NXB_VERBOSE) {
        echo NXB_EOL . $msg;
    }
}

/**
 * Render a site with all its servers.
 *
 * @param $sitename
 * @param $siteServers
 * @throws Exception
 */
function nginx_bakery_render_site($sitename, $siteServers)
{
    global $CONFIG;

    foreach($siteServers as $server)
    {
        if (!isset($server['recipe']) && !isset($server['cookbook'])) {
            throw new Exception('Site '.$sitename.' needs either a "cookbook" or a "recipe"');
        }

        // recipe handling
        if (isset($server['recipe']))
        {
            if (!is_array($server['recipe'])) {
                $server['recipe'] = array($server['recipe']);
            }

            foreach($server['recipe'] as $serverType) {
                nginx_bakery_render_type($sitename, $serverType, $server['config']);
            }
        }
        // cookbooks are handled with recursion
        else {
            // allow people to overrides single entries in cookbooks
            if (!isset($server['overrides'])) {
                $server['overrides'] = array();
            }

            $cookbookConfig = nginx_bakery_config_from_cookbook($server['cookbook'], $server['config'], $server['overrides']);
            nginx_bakery_render_site($sitename, $cookbookConfig);
            return;
        }
    }
}

/**
 * Return a full set of server configs for a cookbook and a config.
 *
 * @param $cookbook
 * @param array $server
 * @return array
 * @throws Exception
 */
function nginx_bakery_config_from_cookbook($cookbook, array $server, array $overrides)
{
    global $CONFIG;

    $fileName = null;

    if (isset($CONFIG['cookbooks'][$cookbook])) {
        $fileName = $CONFIG['cookbooks'][$cookbook];
    } else if (file_exists(__DIR__ . '/cookbooks/' . $cookbook . '.php')) {
        $fileName = 'cookbooks/' . $cookbook . '.php';
    } else {
        $fileName = $cookbook;
    }

    if ($fileName === null || !file_exists(__DIR__ . '/' . $fileName)) {
        throw new Exception('Cookbook does not exist or file is missing:' . $cookbook);
    }

    $configBook = include __DIR__ . '/' . $fileName;

    // override the default cookbook values with the supplied configuration
    // TODO - find a more elegant way
    if (!empty($overrides)) {
        foreach($overrides as $k => $v) {
            if (isset($overrides[$k])) {
                $configBook[$k] = array_merge($configBook[$k], $overrides[$k]);
            }
        }
    }

    // dynamic replacer in the style %somekey%
    array_walk_recursive($configBook, function(&$item, $key) use ($server) {
        if (preg_match("/\%(.*)\%/", $item, $matches)) {
            if(isset($server[$matches[1]])) {
                $item = str_replace($matches[0], $server[$matches[1]], $item);
            }
        }
    });

    return $configBook;
}

/**
 * Renders an nginx "include".
 *
 * $CONFIG can be used in the included server-recipe.
 *
 * @param $filename
 */
function nginx_bakery_render_include($filename)
{
    // $CONFIG might be used within the included nginx-include
    global $CONFIG;

    if (!file_exists(NXB_INC . $filename)) {
        throw new Exception('Include does not exist: '.$filename);
    }

    echo NXB_EOL;
    include NXB_INC . $filename;
    echo NXB_EOL;
}

/**
 * Renders a server part.
 *
 * Both $BAKERY and $CONFIG can be used in the included server-recipe.
 *
 * @param $serverName
 * @param $type
 * @param array $BAKERY the configuration from the user "site"
 * @throws Exception
 */
function nginx_bakery_render_type($serverName, $type, array $BAKERY)
{
    // $CONFIG might be used within the included "server recipe"
    global $CONFIG;

    if (!isset($CONFIG['server'][$type])) {
        throw new Exception('Server has no recipe: '. $serverName);
    }

    // inject the $serverName as new field "_project_key"
    $BAKERY['_project_key'] = $serverName;

    echo NXB_EOL;
    include $CONFIG['server'][$type];
    echo NXB_EOL;
}

/**
 * Renders the contents within a "server".
 *
 * @param array $siteConfig
 * @throws Exception
 */
function nginx_bakery_render_server(array $siteConfig)
{
    global $CONFIG;

    foreach($siteConfig as $configKey => $configValue)
    {
        switch ($configKey) {
            case 'listen':
            case 'server_name':
            case 'index':
            case 'root':
                echo NXB_TAB . $configKey . ' ' . $configValue . ';' . NXB_EOL;
                break;
            case 'nginx':
                foreach($configValue as $nginxKey => $nginxValue) {
                    echo NXB_EOL . NXB_TAB . $nginxKey . ' ' . $nginxValue . ';';
                }
                echo NXB_EOL;
                break;
            case 'includes':
                foreach($configValue as $incName) {
                    nginx_bakery_render_include_tag($incName);
                }
                break;
            case '_project_key':
                break;
            default:
                throw new Exception('Unsupported configuration key: ' . $configKey);
                break;
        }
    }
    echo NXB_EOL;
}

function nginx_bakery_render_include_tag($name)
{
    global $CONFIG;

    if (isset($CONFIG['includes'][$name])) {
        $name = $CONFIG['includes'][$name];
    }
    echo NXB_EOL . NXB_TAB . 'include' . ' ' . $name . ';';
}

