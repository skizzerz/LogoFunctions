<?php
/**
 * LogoFunctions
 *
 * Add parser function about wiki's logo
 *
 * @link https://www.mediawiki.org/wiki/Extension:LogoFunctions
 *
 * @author Devunt <devunt@devunt.kr>
 * @author Ryan Schmidt <skizzerz@gmail.com>
 * @authorlink https://www.mediawiki.org/wiki/User:Devunt
 * @authorlink https://www.mediawiki.org/wiki/User:Skizzerz
 * @copyright Copyright © 2010 Devunt (Bae June Hyeon) and Ryan Schmidt.
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */
 
if ( !defined( 'MEDIAWIKI' ) ) die('define error!');

/* Known Bugs:
 * Doesn't work on default $wgLogo (or any $wgLogo that isn't inside of the upload directory)
 */

$wgExtensionCredits[ 'parserhook' ][] = array(
	'path'            => __FILE__,
	'name'           => 'LogoFunctions',
	'author'         => array( 'Devunt (Bae June Hyeon)', 'Ryan Schmidt' ),
	'url'            => 'https://www.mediawiki.org/wiki/Extension:LogoFunctions',
	'descriptionmsg' => 'logofunctions-desc',
	'version'        => '0.10.3',
);

$dir = dirname( __FILE__ ) . '/';

// config
// map of namespace name => logo URL
$wgNamespaceLogos = array();

// internationalization
$wgExtensionMessagesFiles['LogoFunctions'] = $dir . 'LogoFunctions.i18n.php';
$wgExtensionMessagesFiles['LogoFunctionsMagic'] = $dir . 'LogoFunctions.i18n.magic.php';

// Hooks
$wgHooks['SkinTemplateOutputPageBeforeExec'][] = 'efLogoFunctions_setLogo';
$wgHooks['ParserFirstCallInit'][] = 'efLogoFunctions_Setup';

// state variables (TODO: move to a class instead of global vars/functions)
$egLogoFunctionsPrev = false;
$egLogoFunctionsChain = '';
$egLogoFunctionsLogo = false;

function efLogoFunctions_setLogo( &$skin, &$tpl ) {
	global $egLogoFunctionsLogo, $wgNamespaceLogos;
	// $egLogoFunctionsLogo is only set if we parsed a page (so preview or whatnot)
	// if set, override value from page_props so that page previews are correct
	if ( $egLogoFunctionsLogo !== false ) {
		$tpl->set( 'logopath', $egLogoFunctionsLogo );
		return true;
	}
	$dbr = wfGetDB( DB_SLAVE );
	$logopath = $dbr->selectField( 'page_props', 'pp_value',
		array( 'pp_page' => $skin->getTitle()->getArticleID(), 'pp_propname' => 'logopath' ),
		__METHOD__ );
	if ( $logopath !== false ) {
		$tpl->set( 'logopath', $logopath );
		return true;
	}
	// grab namespace logo (if set)
	$ns = $skin->getTitle()->getNamespace();
	$logodata = $logopath = $logourl = false;
	if ( isset( $wgNamespaceLogos[$ns] ) ) {
		if ( is_array( $wgNamespaceLogos[$ns] ) ) {
			$tpl->set( 'logopath', $wgNamespaceLogos[$ns]['url'] );
		} else {
			$tpl->set( 'logopath', $wgNamespaceLogos[$ns] );
		}
	}
	return true;
}
 
function efLogoFunctions_Setup( &$parser ) {
	$parser->setFunctionHook( 'setlogo', 'efSetLogo_Render' );
	$parser->setFunctionHook( 'getlogo', 'efGetLogo_Render' );
	$parser->setFunctionHook( 'stamplogo', 'efStampLogo_Render' );
	return true;
}

function efStampLogo_Render( $parser, $logo = '', $offx = 0, $offy = 0, $canvx = 0, $canvy = 0 ) {
	global $wgLogo, $wgUploadPath, $wgUploadDirectory, $wgNamespaceLogos;
	global $wgStylePath, $wgStyleDirectory;
	global $egLogoFunctionsPrev, $egLogoFunctionsChain, $egLogoFunctionsLogo;
	$imageobj = wfFindFile( $logo );
	if ( $imageobj == null ) {
		return Html::element( 'strong', array( 'class' => 'error' ),
			wfMsgForContent( 'logofunctions-filenotexist', htmlspecialchars( $logo ) )
		);
	}
	if ( $egLogoFunctionsPrev === false ) {
		// grab current logo
		// 2 checks: first we check namespace logo, then we check $wgLogo
		// namely if $wgLogo is in the default $wgStylePath dir instead of $wgUploadPath and act
		// accordingly for filename
		$ns = $parser->getTitle()->getNamespace();
		$logodata = $logopath = $logourl = false;
		if ( isset( $wgNamespaceLogos[$ns] ) ) {
			$logodata = $wgNamespaceLogos[$ns];
		} else {
			$logodata = $wgLogo;
		}
		if ( is_array( $logodata ) ) {
			$logopath = $logodata['path'];
			$logourl = $logodata['url'];
		} else {
			$logourl = $logodata;
			if ( strpos( $logourl, $wgUploadPath ) === 0 ) {
				$logopath = $wgUploadDirectory . substr( $logourl, strlen( $wgUploadPath ) );
			} elseif ( strpos( $logourl, $wgStylePath ) === 0 ) {
				$logopath = $wgStyleDirectory . substr( $logourl, strlen( $wgStylePath ) );
			} else {
				$logopath = $logourl;
			}
		}
		$egLogoFunctionsPrev = $logopath;
		$egLogoFunctionsChain = $egLogoFunctionsPrev;
	}
	// time to have fun :D
	wfMkdirParents( $wgUploadDirectory . '/logos' );
	$old = false;
	$ext = strtolower( substr( $egLogoFunctionsPrev, -4 ) );
	wfSuppressWarnings();
	if ( $ext == '.png' ) {
		$old = imagecreatefrompng( $egLogoFunctionsPrev );
	} elseif ( $ext == '.jpg' || $ext == 'jpeg' ) {
		$old = imagecreatefromjpeg( $egLogoFunctionsPrev );
	} elseif ( $ext == '.gif' ) {
		$old = imagecreatefromgif( $egLogoFunctionsPrev );
	}
	wfRestoreWarnings();
	if ( !$old ) {
		return Html::element( 'strong', array( 'class' => 'error' ),
			wfMsgForContent( 'logofunctions-badstamptype', htmlspecialchars( $egLogoFunctionsPrev ) )
		);
	}

	// hackery follows, ensure that each image (old and new) are on a 135x135 transparent canvas
	$canvx = ( is_numeric( $canvx ) && $canvx > 0 ) ? $canvx : 0;
	$canvy = ( is_numeric( $canvy ) && $canvy > 0 ) ? $canvy : 0;
	$canvas_x = max( imagesx( $old ), $canvx, 135 );
	$canvas_y = max( imagesy( $old ), $canvy, 135 );
	$old_canvas = imagecreatetruecolor( $canvas_x, $canvas_y );
	$t1 = imagecolorallocatealpha( $old_canvas, 0, 0, 0, 127 );
	imagefill( $old_canvas, 0, 0, $t1 );
	imagealphablending( $old_canvas, true );
	imagesavealpha( $old_canvas, true );
	imagecopy( $old_canvas, $old, 0, 0, 0, 0, imagesx( $old ), imagesy( $old ) );
	// resize to canvas size (yay hackiness)
	$thumb_arr = array(
		'width' => $canvas_x,
		'height' => $canvas_y
	);
	if ( !is_numeric( $offx ) ) $offx = 0;
	if ( !is_numeric( $offy ) ) $offy = 0;
	$thumb = $imageobj->transform( $thumb_arr, File::RENDER_NOW );
	$new = false;
	$loc = $wgUploadDirectory . substr( $thumb->getUrl(), strlen( $wgUploadPath ) );
	$ext = strtolower( substr( $loc, -4 ) );
	wfSuppressWarnings();
	if ( $ext == '.png' ) {
		$new = imagecreatefrompng( $loc );
	} elseif ( $ext == '.jpg' || $ext == 'jpeg' ) {
		$new = imagecreatefromjpeg( $loc );
	} elseif ( $ext == '.gif' ) {
		$new = imagecreatefromgif( $loc );
	}
	wfRestoreWarnings();
	if ( !$new ) {
		imagedestroy( $old );
		iamgedestroy( $old_canvas );
		return Html::element( 'strong', array( 'class' => 'error' ),
			wfMsgForContent( 'logofunctions-badstamptype', htmlspecialchars( $loc ) )
		);
	}

	$new_canvas = imagecreatetruecolor( $canvas_x, $canvas_y );
	$t2 = imagecolorallocatealpha( $new_canvas, 0, 0, 0, 127 );
	imagefill( $new_canvas, 0, 0, $t2 );
	imagealphablending( $new_canvas, true );
	imagesavealpha( $new_canvas, true );
	imagecopy( $new_canvas, $new, $offx, $offy, 0, 0, imagesx( $new ), imagesy( $new ) );

	imagecopy( $old_canvas, $new_canvas, 0, 0, 0, 0, $canvas_x, $canvas_y );
	$egLogoFunctionsChain .= '##' . $logo;
	$egLogoFunctionsPrev = $wgUploadDirectory . DIRECTORY_SEPARATOR . 'logos' . DIRECTORY_SEPARATOR . md5( $egLogoFunctionsChain ) . '.png';
	imagepng( $old_canvas, $egLogoFunctionsPrev );

	// Save the new logo
	$parser->getOutput()->setProperty( 'logopath', $wgUploadPath . '/logos/' . md5( $egLogoFunctionsChain ) . '.png' );
	$wgLogo = $wgUploadPath . '/logos/' . md5( $egLogoFunctionsChain ) . '.png';
	$egLogoFunctionsLogo = $wgLogo;
	
	imagedestroy( $old );
	imagedestroy( $old_canvas );
	imagedestroy( $new );
	imagedestroy( $new_canvas );
}


function efSetLogo_Render( $parser, $logo = '', $width = 135, $height = 135 ) {
	global $wgLogo, $wgUploadPath, $wgUploadDirectory;
	global $egLogoFunctionsPrev, $egLogoFunctionsChain, $egLogoFunctionsLogo;
	$imageobj = wfFindFile( $logo );
	if ( $imageobj == null ) {
		return Html::element( 'strong', array( 'class' => 'error' ), 
			wfMsgForContent( 'logofunctions-filenotexist', htmlspecialchars( $logo ) )
		);
	}
	$thumb_arr = array(
		'width' => ( is_numeric( $width ) && $width > 0 ) ? $width : 135,
		'height' => ( is_numeric( $height ) && $height > 0 ) ? $height : 135
	);
	$thumb = $imageobj->transform( $thumb_arr, File::RENDER_NOW );
	
	$parser->getOutput()->setProperty( 'logopath', $thumb->getUrl() );
	$wgLogo = $thumb->getUrl();
	$egLogoFunctionsLogo = $wgLogo;
	
	$egLogoFunctionsPrev = $wgUploadDirectory . substr( $thumb->getUrl(), strlen( $wgUploadPath ) );
	$egLogoFunctionsChain = $egLogoFunctionsPrev;
}

function efGetLogo_Render( $parser, $prefix = false ) {
	global $wgLogo;
	return ($prefix?$prefix.':':'').basename($wgLogo);
}
