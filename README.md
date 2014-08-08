### Script to upload MODX Revolution for installation or upgrade in 1 Click

#### Always make sure to backup an existing installation before doing anything else.


Place the install.php file in the same directory as your site root. Load it in your browser and select the version you want to install. (i.e. mydomain.com/install.php)

The script will download the selected version from the MODX respository, unzip it in the same location, then forward you to the MODX setup. The install.php script will automatically delete itself.

This can be used to either upgrade an existing installation or handle a new installation.

#### Always make sure to backup your existing installation before doing anything else.


In order to be able to directly download the MODX zip archive from the MODX repository, your server must have either allow_url_fopen or cURL enabled.

