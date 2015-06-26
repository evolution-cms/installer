### Script to upload MODX Revolution for installation or upgrade in 1 Click

#### Always make sure to backup an existing installation before doing anything else.


Place the install.php file in the same directory as your site root. Load it in your browser and select the version you want to install. (i.e. mydomain.com/install.php)

The script will download the selected version from the MODX respository, unzip it in the same location, then forward you to the MODX setup. The install.php script will automatically delete itself.

This can be used to either upgrade an existing installation or handle a new installation.

#### Always make sure to backup your existing installation before doing anything else.

In order to be able to directly download the MODX zip archive from the MODX repository, your server must have either allow_url_fopen or cURL enabled.

In order to be able to unzip the downloaded MODX archive, your PHP must be at least version 5.2, it must have been compiled to include the zip extensions, and have the zip extension enabled.

You may need to change the permissions for newly created folders on line 145. For example, your server may require directory permissions of 0755.

Changelog:

Version 1.5.0-beta
(26-06-2015)
- Change links back to MODX repository direct links
- Modified cURL code to emulate CURLOPT_FOLLOWLOCATION for systems with open_basedir and safe_mode

Version 1.4.3
(24-06-2015)
- Changed links to direct links to AWS to solve problems with servers that have open_basedir or safe_mode enabled.
- Dropped support for 2.2.16 due to some of the archive having issues unzipping.

Version 1.4.2
(24-06-2015)
- Updated to Revolution 2.3.4

Version 1.4.1
(19-02-2015)
- Fixed failure to extract error - temp directory needed trailing slash

Version 1.4 
(30-01-2015)
- updated to Revolution 2.3.3
