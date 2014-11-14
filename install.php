<?php
$version = '0.2';
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);
ini_set('max_execution_time',0);
header('Content-Type: text/html; charset=utf-8');

if(extension_loaded('xdebug')){
    ini_set('xdebug.max_nesting_level', 100000);
}

$InstallData = array(
	'evo1.0.15' => array(
		'tree' => 'Evolution',
		'name' => 'MODX Evolution 1.0.15 (31.10.2014)',
		'link' => 'https://github.com/modxcms/evolution/archive/v1.0.15.zip',
	    'location' =>'install/index.php'
	),
	'evodmi3yy1.1b-d7.0.18' => array(
		'tree' => 'Evolution',
		'name' => 'MODX Evolution by Dmi3yy 1.1b-d7.0.18 (14.11.2014)',
		'link' => 'https://github.com/dmi3yy/modx.evo.custom/archive/1.1b-d7.0.18.zip',
	    'location' => 'install/index.php'
	),
	'evodmi3yy1.0.15-d6.0.18' => array(
		'tree' => 'Evolution',
		'name' => 'MODX Evolution by Dmi3yy 1.0.15-d6.0.18 (14.11.2014)',
		'link' => 'https://github.com/dmi3yy/modx.evo.custom/archive/1.0.15-d6.18.zip',
	    'location' => 'install/index.php'
	),
	'evojp1.0.12j-r1' => array(
		'tree' => 'Evolution',
		'name' => 'MODX Evolution 1.0.12J-r1 (31.12.2013)',
	    'link' => 'http://modx.jp/?dl=evo.zip',
	    'location' => 'install/index.php'
	),
	'clipper1.2.6' => array(
		'tree' => 'Evolution',
		'name' => 'ClipperCMS 1.2.6 (30.11.2013)',
	    'link' => 'https://github.com/ClipperCMS/ClipperCMS/archive/clipper_1.2.6.zip',
	    'location' => 'install/index.php'
	),
	'revo2.2.14-pl' => array(
		'tree' => 'Revolution',
		'name' => 'MODX Revolution 2.2.14-pl Standard Traditional (04.04.2014)',
	    'link' => 'http://modx.com/download/direct/modx-2.2.14-pl.zip',
	    'location' =>'setup/index.php'
	),
	'revo2.2.14-pl-ad' => array(
		'tree' => 'Revolution',
		'name' => 'MODX Revolution 2.2.14-pl Standard Advanced (04.04.2014)',
	    'link' => 'http://modx.com/download/direct/modx-2.2.14-pl-advanced.zip',
	    'location' =>'setup/index.php'
	),
	'revo2.2.14-pl-sdk' => array(
		'tree' => 'Revolution',
		'name' => 'MODX Revolution 2.2.14-pl Standard SDK (04.04.2014)',
	    'link' => 'http://modx.com/download/direct/modx-2.2.14-pl-sdk.zip',
	    'location' => 'setup/index.php'
	),
	'revo2.3.0-pl' => array(
		'tree' => 'Revolution',
		'name' => 'MODX Revolution 2.3.0 Traditional (28.05.2014)',
	    'link' => 'http://modx.s3.amazonaws.com/releases/nightlies/modx-2.3.0-dev-052814.zip',
	    'location' => 'setup/index.php'
	),
	'revo2.3.0-ad' => array(
		'tree' => 'Revolution',
		'name' => 'MODX Revolution 2.3.0 Advanced (28.05.2014)',
	    'link' => 'http://modx.s3.amazonaws.com/releases/nightlies/modx-2.3.0-dev-advanced-052814.zip',
	    'location' => 'setup/index.php'
	)
);

class ModxInstaller{
	static public function downloadFile ($url, $path) {
		$newfname = $path;
		try {
			$file = fopen ($url, "rb");
			if ($file) {
				$newf = fopen ($newfname, "wb");
				if ($newf)
				while(!feof($file)) {
					fwrite($newf, fread($file, 1024 * 8 ), 1024 * 8 );
				}
			}			
		} catch(Exception $e) {
			$this->errors[] = array('ERROR:Download',$e->getMessage());
			return false;
		}
		if ($file) fclose($file);
		if ($newf) fclose($newf);
		return true;
	}	
	static public function removeFolder($path){
		$dir = realpath($path);
		if ( !is_dir($dir)) return;
		$it = new RecursiveDirectoryIterator($dir);
		$files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
		foreach($files as $file) {
			if ($file->getFilename() === '.' || $file->getFilename() === '..') {
				continue;
			}
			if ($file->isDir()){
				rmdir($file->getRealPath());
			} else {
				unlink($file->getRealPath());
			}
		}
		rmdir($dir);
	}
	static public function copyFolder($src, $dest) {
		$path = realpath($src);
		$dest = realpath($dest);
		$objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::SELF_FIRST);
		foreach($objects as $name => $object)
		{			
			$startsAt = substr(dirname($name), strlen($path));
			self::mmkDir($dest.$startsAt);
			if ( $object->isDir() ) {
				self::mmkDir($dest.substr($name, strlen($path)));
			}

			if(is_writable($dest.$startsAt) and $object->isFile())
			{
				copy((string)$name, $dest.$startsAt.DIRECTORY_SEPARATOR.basename($name));
			}
		}
	}
	static public function mmkDir($folder, $perm=0777) {
		if(!is_dir($folder)) {
			mkdir($folder, $perm);
		}
	}
}
	
if (!empty($_GET['modx']) && is_scalar($_GET['modx']) && isset($InstallData[$_GET['modx']])) {
	$rowInstall = $InstallData[$_GET['modx']];
		
	//run unzip and install
	ModxInstaller::downloadFile($rowInstall['link'] ,"modx.zip");
	$zip = new ZipArchive;
	$res = $zip->open(dirname(__FILE__)."/modx.zip");
	$zip->extractTo(dirname(__FILE__).'/temp' );
	$zip->close();
	unlink(dirname(__FILE__).'/modx.zip');

	if ($handle = opendir(dirname(__FILE__).'/temp')) {
		while (false !== ($name = readdir($handle))) if ($name != "." && $name != "..") $dir = $name;
		closedir($handle);
	}

	ModxInstaller::copyFolder(dirname(__FILE__).'/temp/'.$dir, dirname(__FILE__).'/');
	ModxInstaller::removeFolder(dirname(__FILE__).'/temp');
	unlink(basename(__FILE__));
	header('Location: '.$rowInstall['location']);

}else{
$ItemGrid = array(); 
foreach($InstallData as $ver=>$item){
	$ItemGrid[$item['tree']][$ver] = $item;
}

//@TODO : add check installer version	
echo '
<!DOCTYPE html>
<html>
<head>
	<title>MODX Installer v'.$version.'</title>
	<meta charset="utf-8">
	<style type="text/css">
		@import url(http://fonts.googleapis.com/css?family=PT+Serif:400,700&subset=latin,cyrillic);article,aside,audio,b,body,canvas,dd,details,div,dl,dt,em,fieldset,figcaption,figure,footer,form,h1,h2,h3,h4,h5,h6,header,hgroup,html,i,img,label,li,mark,menu,nav,ol,p,section,span,strong,summary,table,tbody,td,tfoot,th,thead,time,tr,u,ul,video{margin:0;padding:0;border:0;outline:0;vertical-align:baseline;background:0 0;font-size:100%}a{margin:0;padding:0;font-size:100%;vertical-align:baseline;background:0 0}table{border-collapse:collapse;border-spacing:0}td,td img{vertical-align:top}button,input,select,textarea{margin:0;font-size:100%}input[type=password],input[type=text],textarea{padding:0}input[type=checkbox]{vertical-align:bottom}input[type=radio]{vertical-align:text-bottom}article,aside,details,figcaption,figure,footer,header,hgroup,menu,nav,section{display:block}html{overflow-y:scroll}body{color:#111;text-align:left;font:12px Verdana,"Geneva CY","DejaVu Sans",sans-serif}button,input,select,textarea{font-family:Verdana,"Geneva CY","DejaVu Sans",sans-serif}a,a:active,a:focus,a:hover,a:visited,button,input[type=button],input[type=submit],label{cursor:pointer}::selection{background:#84d5e8;color:#fff;text-shadow:none}html{position:relative;background:#f8f8f8 url(http://installer.evolution-cms.com/img/base.png)}body{background:0 0;font-size:14px;line-height:22px;font-family:"Helvetica Neue",helvetica,arial,sans-serif;text-shadow:0 1px 0 #fff}a{color:#0f7096}.button,button{color:#fff;display:inline-block;padding:15px;font-size:20px;text-decoration:none;border:5px solid #fff;border-radius:8px;background-color:#67a749;background-image:linear-gradient(to top,#67a749 0,#67a749 27.76%,#a1c755 100%);text-shadow:0 0 2px rgba(0,0,0,.64)}a.button{padding:5px 15px}h1,h2,h3,h4,h5{font-family:"PT Serif",helvetica,arial,sans-serif;line-height:28px}h1{font-size:26px}h2{font-size:22px}h3{font-size:18px}h4{font-size:16px}h5{font-size:14px}.header{-moz-box-sizing: border-box;float:left;width:100%;box-sizing:border-box;background:#fff;background:linear-gradient(to bottom,#fff,#f2f2f2);padding:20px;border-bottom:1px solid #fff}.header img{float:left;width:180px;margin:0 5px 0 0}.header h1.main-heading{color:#137899;font-size:32px;line-height:40px}.header-button-wrapper{float:right}.main-heading>span{display:none}.main-heading>sup{color:#ccc;font-weight:400}.content{float:left;padding:30px}.content h2{margin:0;line-height:20px}.content form{margin:10px 0 50px}.content form .column{float:left;box-sizing:border-box;width:500px;margin:20px 0}.column h3{display:inline-block;padding:0 0 5px;margin:0 0 20px;border-bottom:2px solid #000}.column label{float:left;width:100%;clear:both;padding:3px 0}form button{float:left;width:200px;clear:both}label>span{border-bottom:1px dotted #555}label>input{margin:0 5px 0 0}.footer{position:absolute;bottom:20px;right:20px;font-size:10px;color:#ccc}.footer a{color:#aaa}
	</style>
</head>
<body>
	<div class="header">
		<img src="http://installer.evolution-cms.com/img/logo.png">
		<h1 class="main-heading"><span>MODX</span> Installer <sup>v'.$version.'</sup> </h1>
		<div class="header-button-wrapper">
			<!--<a href="#" class="button">New version</a>&nbsp;-->
			<a href="https://github.com/evolution-cms/installer" class="button">GitHub</a>
		</div>
	</div>
</div>
<div class="content">';
echo '<h2>Choose MODX version for Install</h2>
	<form>';
	foreach($ItemGrid as $tree=>$item){
		echo '<div class="column">
			<h3>'.strtoupper($tree).'</h3>';
			foreach($item as $version => $itemInfo){
				echo '<label><input type="radio" name="modx" value="'.$version.'">            <span>'.$itemInfo['name'].'</span></label><br>';
			}
		echo '</div>';
	}
	
if(ini_get('allow_url_fopen') ) {
echo '<br><button>Install &rarr;</button>'; 
} else {
echo '<h2>Cannot download the files - url_fopen is not enabled on this server.</h2>';
}
echo '</form>
	<div class="footer">
		<p>Created by <a href="http://ga-alex.com" title="">Bumkaka</a> & <a href="http://dmi3yy.com" title="">Dmi3yy</a></p>
		<p>Designed by <a href="http://a-sharapov.com" title="">Sharapov</a></p>
	</div>
</body>
</html>
';
}	
?>
