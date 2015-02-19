<?php
/**
 * LatestPhotos Extension created based on Oasis module
 *
 * @author Bogna 'bognix' Knychała
 *
 */

$wgExtensionCredits[ 'specialpage' ][] = [
	'name' => 'LatestPhotos',
	'author' => 'Bogna "bognix" Knychała',
	'version' => 1.0,
	'url' => 'https://github.com/Wikia/app/tree/dev/extensions/wikia/LatestPhotos'
];

$wgAutoloadClasses[ 'LatestPhotosController' ] =  __DIR__ . '/LatestPhotosController.class.php';
$wgAutoloadClasses[ 'LatestPhotosHelper' ] =  __DIR__ . '/LatestPhotosHelper.class.php';
$wgAutoloadClasses[ 'LatestPhotosHooks' ] =  __DIR__ . '/LatestPhotosHooks.class.php';


$wgHooks['FileDeleteComplete'][] = 'LatestPhotosHooks::onImageDelete';
$wgHooks['MessageCacheReplace'][] = 'LatestPhotosHooks::onMessageCacheReplace';
$wgHooks['UploadComplete'][] = 'LatestPhotosController::onImageUploadComplete';
$wgHooks['FileUpload'][] = 'LatestPhotosController::onImageUpload';
$wgHooks['SpecialMovepageAfterMove'][] = 'LatestPhotosController::onImageRenameCompleated';



$wgExtensionMessagesFiles[ 'LatestPhotos' ] = __DIR__ . '/LatestPhotos.i18n.php';

