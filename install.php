<?php
if ($_GET['modx'] != '') {
	//switch version and setting
	switch ($_GET['modx']) {
	    //EVO
	    case 'evo1.0.13':
	        $link = 'https://github.com/modxcms/evolution/archive/v1.0.13.zip';
	        $location = '/install/index.php';
	        break;

	    case 'evodmi3yy1.0.13-d6.7':
	        $link = 'https://github.com/dmi3yy/modx.evo.custom/archive/master.zip';
	        $location = '/install/index.php?action=connection';
	        break;

	    case 'evojp1.0.12j-r1':
	        $link = 'http://modx.jp/?dl=evo.zip';
	        $location = '/install/index.php';
	        break;
	        
	    case 'clipper1.2.6':
	        $link = 'https://github.com/ClipperCMS/ClipperCMS/archive/clipper_1.2.6.zip';
	        $location = '/install/index.php';
	        break;   

	     //REVO
	     case 'revo2.2.12-pl':
	        $link = 'https://github.com/modxcms/revolution/archive/v2.2.12-pl.zip';
	        $location = '/setup/index.php';
	        break;   
	        
	     case 'revo2.2.12-pl-ad':
	        $link = 'http://modx.com/download/direct/modx-2.2.12-pl-advanced.zip';
	        $location = '/setup/index.php';
	        break;   
	        
	     case 'revo2.2.12-pl-sdk':
	        $link = 'http://modx.com/download/direct/modx-2.2.12-pl-sdk.zip';
	        $location = '/setup/index.php';
	        break;   
	        
	     case 'revo2.3.0-pl':
	        $link = 'http://modx.s3.amazonaws.com/releases/nightlies/modx-2.3.0-dev-020214.zip';
	        $location = '/setup/index.php';
	        break;   
	        
	     case 'revo2.3.0-ad':
	        $link = 'http://modx.s3.amazonaws.com/releases/nightlies/modx-2.3.0-dev-advanced-020214.zip';
	        $location = '/setup/index.php';
	        break;                               
	}


	function downloadFile ($url, $path) {
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
	function removeFolder($path){
		$dir = realpath($path);
		if ( !is_dir($dir)) return;
		$it = new RecursiveDirectoryIterator($dir);
		$files = new RecursiveIteratorIterator($it,
		RecursiveIteratorIterator::CHILD_FIRST);
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
	function copyFolder($src, $dest) {
		$path = realpath($src);
		$dest = realpath($dest);
		$objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::SELF_FIRST);
		foreach($objects as $name => $object)
		{			
			$startsAt = substr(dirname($name), strlen($path));
			mmkDir($dest.$startsAt);
			if ( $object->isDir() ) {
				mmkDir($dest.substr($name, strlen($path)));
			}

			if(is_writable($dest.$startsAt) and $object->isFile())
			{
				copy((string)$name, $dest.$startsAt.DIRECTORY_SEPARATOR.basename($name));
			}
		}
	}
	function mmkDir($folder, $perm=0777) {
		if(!is_dir($folder)) {
			mkdir($folder, $perm);
		}
	}

	//run unzip and install
	downloadFile($link ,"modx.zip");
	$zip = new ZipArchive;
	$res = $zip->open(dirname(__FILE__)."/modx.zip");
	$zip->extractTo(dirname(__FILE__).'/temp' );
	$zip->close();
	unlink(dirname(__FILE__).'/modx.zip');

	if ($handle = opendir(dirname(__FILE__).'/temp')) {
		while (false !== ($name = readdir($handle))) if ($name != "." && $name != "..") $dir = $name;
		closedir($handle);
	}

	copyFolder(dirname(__FILE__).'/temp/'.$dir, dirname(__FILE__).'/');
	removeFolder(dirname(__FILE__).'/temp');
	unlink('modx.zip');
	unlink('install.php');
	header('Location: '.$location);

}else{
//@TODO : add check installer version	
echo '
<!DOCTYPE html>
<html>
<head>
	<title>MODX Installer v0.1</title><meta charset="utf-8">
</head>
<body>
	<div class="header">
		<img src="logo.png">
		<h1>MODX Installer <sup>v0.1</sup> </h1>
	</div>
	<h2>Choose MODX version for Install</h2>
</div>
<div class="content">
	<form method="get">
		<h3>EVOLUTION</h3>
		<label><input type="radio" name="modx" value="evo1.0.13">            <span>MODX Evolution 1.0.13 (03.03.2014)</span></label><br>
		<label><input type="radio" name="modx" value="evodmi3yy1.0.13-d6.7"> <span>MODX Evolution by Dmi3yy 1.0.13-d6.7 (07.03.2014)</span></label><br>
		<label><input type="radio" name="modx" value="evojp1.0.12j-r1">      <span>MODX Evolution 1.0.12J-r1 (31.12.2013)</span></label><br>
		<label><input type="radio" name="modx" value="clipper1.2.6">         <span>ClipperCMS 1.2.6 (30.11.2011)</span></label><br>

		<h3>REVOLUTION</h3>
		<label><input type="radio" name="modx" value="revo2.2.12-pl">        <span>MODX Revolution 2.2.12-pl Standard Traditional (19.02.2014)</span></label><br>
		<label><input type="radio" name="modx" value="revo2.2.12-pl-ad">     <span>MODX Revolution 2.2.12-pl Standard Advanced (19.02.2014)</span></label><br>
		<label><input type="radio" name="modx" value="revo2.2.12-pl-sdk">    <span>MODX Revolution 2.2.12-pl Standard SDK (19.02.2014)</span></label><br>
		<label><input type="radio" name="modx" value="revo2.3.0-pl">         <span>MODX Revolution 2.3.0 Traditional (02.02.2014)</span></label><br>
		<label><input type="radio" name="modx" value="revo2.3.0-ad">         <span>MODX Revolution 2.3.0 Advanced (02.02.2014)</span></label><br>

		<button>Install</button>
	</form>
	<div class="footer">
		<p >Created by <a href="http://www.dallasbass.com" title="Dallas Bass">Bumkaka</a> & <a href="#">Dmi3yy</a></p>
		<p >Designed by <a href="http://www.dallasbass.com" title="Dallas Bass">Sharapov</a></p>
	</div>
</body>
</html>
';
}	
?>