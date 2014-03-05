<?php
downloadFile('https://github.com/dmi3yy/modx.evo.custom/archive/master.zip' ,"modx.zip");
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
	unlink('modx.zip');
	unlink('instal.php');
	header("Location: /install/index.php?action=connection");
?>