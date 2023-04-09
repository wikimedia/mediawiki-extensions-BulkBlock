# BulkBlock MediaWiki Extension

The BulkBlock MediaWiki extension allows administrators to easily block multiple users at once on a MediaWiki website.

## Requirements

* MediaWiki 1.35 or later
* PHP 7.2 or later

## Installation

1. Download the latest release of the extension from the [releases page](https://github.com/WikiTeq/mediawiki-extension-BulkBlock/releases) on GitHub.
2. Extract the downloaded file to the `extensions` directory of your MediaWiki installation.
3. Add the following line to your `LocalSettings.php` file:

```php
wfLoadExtension( 'BulkBlock' );
```

4. Navigate to the Special:Version page on your MediaWiki website and verify that the extension is listed.

## Usage

1. Navigate to the Special:BulkBlock page on your MediaWiki website.
2. Enter the usernames of the users you wish to block in the text box.
3. Enter the reason for the block in the text box.
4. Press the "Block" button.

