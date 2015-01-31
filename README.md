### Script to upload MODX Revolution for installation or upgrade in 1 Click

#### Always make sure to backup an existing installation before doing anything else.


Place the install.php file in the same directory as your site root. Load it in your browser and select the version you want to install. (i.e. mydomain.com/install.php)

The script will download the selected version from the MODX respository, unzip it in the same location, then forward you to the MODX setup. The install.php script will automatically delete itself.

This can be used to either upgrade an existing installation or handle a new installation.

#### Always make sure to backup your existing installation before doing anything else.

In order to be able to directly download the MODX zip archive from the MODX repository, your server must have either allow_url_fopen or cURL enabled.

In order to be able to unzip the downloaded MODX archive, your PHP must be at least version 5.2, it must have been compiled to include the zip extensions, and have the zip extension enabled.

You may need to change the permissions for newly created folders on line 145. For example, your server may require directory permissions of 0755.

Changlog:

Version 1.4 
(30-01-2015)
- updated to Revo 2.3.3
