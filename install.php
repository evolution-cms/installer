<?php

error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);
ini_set('max_execution_time',0);

$installer_version = '1';
$default = '3.2.5';

if(extension_loaded('xdebug')) {
    ini_set('xdebug.max_nesting_level', 100000);
}

if (!empty($_GET['target']) && Installer::doInstall($_GET['target'])) {
    exit;
}

header('Content-Type: text/html; charset=utf-8');

//@TODO : add check installer version

class Installer{
    public static $packageInfo = [
        '3.2.5' => [
            'tree' => 'Evolution',
            'name' => 'Evolution CMS 3.2.5',
            'link' => 'https://github.com/evolution-cms/evolution/archive/3.2.5.zip',
            'location' => 'install/index.php'
        ],
        '3.x' => [
            'tree' => 'Evolution',
            'name' => 'Evolution CMS 3(3.x develop version)',
            'link' => 'https://github.com/evolution-cms/evolution/archive/3.x.zip',
            'location' => 'install/index.php'
        ],
        '1.4.17' => [
            'tree' => 'Evolution',
            'name' => 'Evolution CMS 1.4.17',
            'link' => 'https://github.com/evolution-cms/evolution/archive/1.4.17.zip',
            'location' =>'install/index.php'
        ],
        '1.4.x' => [
            'tree' => 'Evolution',
            'name' => 'Evolution CMS (1.4.x develop version)',
            'link' => 'https://github.com/evolution-cms/evolution/archive/1.4.x.zip',
            'location' => 'install/index.php'
        ],
        '2.0.x' => [
            'tree' => 'Evolution',
            'name' => 'Evolution CMS (2.0.x develop version, depricated and not supported)',
            'link' => 'https://github.com/evolution-cms/evolution/archive/2.0.x.zip',
            'location' => 'install/index.php'
        ],
    ];

    public static function items($default=null) {
        $ItemGrid = [];
        foreach(static::$packageInfo as $ver=>$item){
            $ItemGrid[$item['tree']][$ver] = $item;
        }
        $rs = [];
        foreach($ItemGrid as $tree=>$item){
            $rs[] = '<div class="column">'.strtoupper($tree);
            foreach($item as $version => $itemInfo){
                $rs[] = sprintf(
                    '<label><input type="radio" name="target" value="%s"> <span>%s</span></label><br>',
                    $version,
                    $itemInfo['name']
                );
            }
            $rs[] = '</div>';
        }

        if(!$default) {
            return implode("\n", $rs);
        }

        return str_replace(
            sprintf('value="%s"', $default),
            sprintf('value="%s" checked', $default),
            implode("\n", $rs)
        );
    }

    public static function hasProblem() {
        if (!ini_get('allow_url_fopen')) {
            return '<h2 class="warning">Cannot download the files - url_fopen is not enabled on this server.</h2>';
        }
        if (!Installer::hasDirPerm()) {
            return '<h2 class="warning">Cannot download the files - The directory does not have write permission.</h2>';
        }
        return false;
    }

    private static function downloadFile ($url, $path) {
        $rs = file_get_contents($url);
        if(!$rs) {
            return false;

        }
        return file_put_contents($path, $rs);
    }

    private static function moveFiles($src, $dest) {
        $path = realpath($src);
        $dest = realpath($dest);
        $objects = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach($objects as $name => $object) {
            $startsAt = substr(dirname($name), strlen($path));
            self::mmkDir($dest.$startsAt);
            if ( $object->isDir() ) {
                self::mmkDir($dest.substr($name, strlen($path)));
            }

            if(is_writable($dest.$startsAt) && $object->isFile()) {
                rename((string)$name, $dest.$startsAt.'/'.basename($name));
            }
        }
    }

    private static function mmkDir($folder, $perm=0777) {
        if(is_dir($folder)) {
            return;
        }
        if (mkdir($folder, $perm) || is_dir($folder)) {
            return;
        }
        throw new \RuntimeException(
            sprintf(
                'Directory "%s" was not created', $folder
            )
        );
    }

    public static function doInstall($target_version=null) {

        if (empty($target_version) || !is_scalar($target_version)) {
            return false;
        }
        if (!isset(static::$packageInfo[$target_version])) {
            return false;
        }

        $rowInstall = static::$packageInfo[$target_version];
        $base_dir = str_replace('\\','/',__DIR__);
        $temp_dir = str_replace('\\','/',__DIR__).'/_temp'.md5(time());

        //run unzip and install
        static::downloadFile($rowInstall['link'] ,'fetch.zip');
        $zip = new ZipArchive;
        $zip->open($base_dir.'/fetch.zip');
        $zip->extractTo($temp_dir);
        $zip->close();
        unlink($base_dir.'/fetch.zip');

        $dir = '';
        if ($handle = opendir($temp_dir)) {
            while ($name = readdir($handle)) {
                if (!$name) {
                    break;
                }
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $dir = $name;
            }
            closedir($handle);
        }

        static::moveFiles($temp_dir.'/'.$dir, $base_dir.'/');
        static::rmdirs($temp_dir);
        unlink(__FILE__);
        header('Location: '.$rowInstall['location']);
        return true;
    }

    private static function rmdirs($dir) {
        if (!is_dir($dir)) {
            return;
        }
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object === '.' || $object === '..') {
                continue;
            }
            $path = sprintf('%s/%s', $dir, $object);
            if (is_dir($path) && !is_link($path)) {
                self::rmdirs($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private static function hasDirPerm() {

        if (basename(__FILE__) !== 'install.php') {
            return false;
        }

        $r = __DIR__.'/_index_tmp.php';
        if (!@ copy(__FILE__,$r)) {
            return false;
        }
        if (!@ unlink(__FILE__)) {
            return false;
        }
        if (!@ copy($r,__FILE__)) {
            return false;
        }
        if (!@ unlink($r)) {
            return false;
        }

        return  true;
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>EVO Installer v<?= $installer_version ?></title>
    <meta charset="utf-8">
    <style>
        @import url(https://fonts.googleapis.com/css?family=Quicksand:300,400&subset=latin,cyrillic);
        article,aside,audio,b,body,canvas,dd,details,div,dl,dt,em,fieldset,figcaption,figure,footer,form,h1,h2,h3,h4,h5,h6,header,hgroup,html,i,img,label,li,mark,menu,nav,ol,p,section,span,strong,summary,table,tbody,td,tfoot,th,thead,time,tr,u,ul,video{margin:0;padding:0;border:0;outline:0;vertical-align:baseline;background:0 0;font-size:100%}
        a{margin:0;padding:0;font-size:100%;vertical-align:baseline;background:0 0}table{border-collapse:collapse;border-spacing:0}
        td,td img{vertical-align:top}
        button,input,select,textarea{margin:0;font-size:100%}
        input[type=password],input[type=text],textarea{padding:0}
        input[type=checkbox]{vertical-align:bottom}
        input[type=radio]{vertical-align:text-bottom}
        article,aside,details,figcaption,figure,footer,header,hgroup,menu,nav,section{display:block}
        html{overflow-y:scroll}
        body{color:#111;text-align:left;font:12px "Quicksand",sans-serif}
        button,input,select,textarea{font-family:"Quicksand",sans-serif}
        a,a:active,a:focus,a:hover,a:visited,button,input[type=button],input[type=submit],label{cursor:pointer}
        ::selection{background:#84d5e8;color:#fff;text-shadow:none}
        html{position:relative;background:#f8f8f8 url(https://installer.evolution-cms.com/img/base.png)}
        body{background:0 0;font-size:14px;line-height:22px;font-family:"Quicksand",sans-serif;text-shadow:0 1px 0 #fff}
        a{color:#0f7096}
        .button,button{color:#fff;display:inline-block;padding:15px;font-size:20px;text-decoration:none;border:5px solid #fff;border-radius:8px;background-color:#67a749;background-image:linear-gradient(to top,#67a749 0,#67a749 27.76%,#a1c755 100%);text-shadow:0 0 2px rgba(0,0,0,.64)}
        a.button{padding:5px 15px; float: right;}
        h1,h2,h3,h4,h5{font-family:"Quicksand",sans-serif;line-height:28px; font-weight:300;}
        h1{font-size:26px;font-weight: 300;}
        h2{font-size:22px}
        h3{font-size:18px}
        h4{font-size:16px}
        h5{font-size:14px}
        .header{-moz-box-sizing: border-box;float:left;width:100%;box-sizing:border-box;background:#fff;background:linear-gradient(to bottom,#fff,#f2f2f2);padding:20px;border-bottom:1px solid #fff}
        .header img{float:left;width:256px;margin: 0 20px 0 0}
        .header h1.main-heading{color:#137899;font-size:24px;line-height:30px; float: left;}
        .header-button-wrapper{float:right}
        .main-heading>span{display:none}
        .main-heading>sup{color:#ccc;font-weight:400}
        .content{float:left;padding:30px}
        .content h2{margin:0;line-height:20px}
        .content form{margin:10px 0 50px}
        .content form .column{float:left;box-sizing:border-box;width:500px;margin:20px 0}
        .column h3{display:inline-block;padding:0 0 5px;margin:0 0 20px;border-bottom:2px solid #000}
        .column label{float:left;width:100%;clear:both;padding:5px 0;font-size:16px}
        form button{float:left;width:200px;clear:both; margin-top:15px;}
        label>span{border-bottom:1px dotted #555}
        label>input{margin:0 5px 0 0}
        .footer{position:absolute;bottom:20px;right:20px;font-size:10px;color:#ccc}
        .footer a{color:#aaa}
        .warning{float:left;padding:10px;background-color:#f9caca;}
    </style>
</head>
<body>
<div class="header">
    <img src="data:image/png;base64,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">
    <h1 class="main-heading"><span>EVO</span> Installer <sup>v<?= $installer_version ?></sup> </h1>
    <div class="header-button-wrapper">
        <!--<a href="#" class="button">New version</a>&nbsp;-->
        <a href="https://github.com/evolution-cms/installer" class="button">GitHub</a>
    </div>
</div>
<div class="content">
    <h2>Choose Evolution CMS version for install:</h2>
    <form>';
        <?= Installer::items($default) ?>
        <?= Installer::hasProblem() ?: '<br><button>Install &rarr;</button>' ?>
    </form>
</div>
<div class="footer">
    <p>Created by Bumkaka &amp; <a href="https://dmi3yy.com/">Dmi3yy</a></p>
    <p>Designed by <a href="https://a-sharapov.com">Sharapov</a></p>
</div>
</body>
</html>
