<?php
/**
 * Cuts web assets out of brand/logo-original.jpg (taupe logo on a cream square):
 *   logo-full.png   ISA + leaf + SKINCARE, transparent, taupe
 *   logo-mark.png   ISA + leaf only (header), transparent, taupe
 *   logo-light.png  full lockup in cream, for the dark footer
 *   icon.png        512² site icon / favicon (mark on cream)
 *
 *   docker run --rm -v "$PWD":/app wordpress:php8.3-apache php /app/bin/make-brand.php
 */

$dir = __DIR__ . '/../brand';
$src = imagecreatefromjpeg( "$dir/logo-original.jpg" );
$W   = imagesx( $src );
$H   = imagesy( $src );

$bg  = [ 0xf1, 0xe5, 0xd9 ]; // sampled cream
$ink = [ 0x96, 0x7a, 0x64 ]; // sampled taupe
$lum = fn( array $c ) => 0.299 * $c[0] + 0.587 * $c[1] + 0.114 * $c[2];
$bgL = $lum( $bg );
$inL = $lum( $ink );

/** Coverage 0..1 per pixel: how far from the cream background toward the ink. */
$cov = [];
for ( $y = 0; $y < $H; $y++ ) {
	for ( $x = 0; $x < $W; $x++ ) {
		$c = imagecolorat( $src, $x, $y );
		$l = $lum( [ ( $c >> 16 ) & 255, ( $c >> 8 ) & 255, $c & 255 ] );
		$a = ( $bgL - $l ) / ( $bgL - $inL );
		$a = $a < 0.06 ? 0 : min( 1, $a ); // kill JPEG noise in the background
		$cov[ $y ][ $x ] = $a;
	}
}

/** Tight bounding box of inked pixels inside a row band. */
$bbox = function ( int $y0, int $y1 ) use ( $cov, $W ) {
	$minx = $W; $maxx = 0; $miny = $y1; $maxy = $y0;
	for ( $y = $y0; $y < $y1; $y++ ) {
		for ( $x = 0; $x < $W; $x++ ) {
			if ( $cov[ $y ][ $x ] > 0.35 ) {
				$minx = min( $minx, $x ); $maxx = max( $maxx, $x );
				$miny = min( $miny, $y ); $maxy = max( $maxy, $y );
			}
		}
	}
	return [ $minx, $miny, $maxx, $maxy ];
};

/** Row where the mark ends and SKINCARE begins: first empty row band after the letters. */
$split = 0;
for ( $y = 820; $y < $H; $y++ ) {
	$empty = true;
	for ( $x = 0; $x < $W; $x++ ) {
		if ( $cov[ $y ][ $x ] > 0.35 ) { $empty = false; break; }
	}
	if ( $empty ) { $split = $y; break; }
}

$render = function ( array $box, array $rgb, int $pad, int $targetH, string $out ) use ( $cov ) {
	[ $x0, $y0, $x1, $y1 ] = $box;
	$x0 -= $pad; $y0 -= $pad; $x1 += $pad; $y1 += $pad;
	$w = $x1 - $x0 + 1;
	$h = $y1 - $y0 + 1;
	$im = imagecreatetruecolor( $w, $h );
	imagealphablending( $im, false );
	imagesavealpha( $im, true );
	for ( $y = 0; $y < $h; $y++ ) {
		for ( $x = 0; $x < $w; $x++ ) {
			$a = $cov[ $y0 + $y ][ $x0 + $x ] ?? 0;
			imagesetpixel( $im, $x, $y, imagecolorallocatealpha( $im, $rgb[0], $rgb[1], $rgb[2], (int) round( 127 - 127 * $a ) ) );
		}
	}
	if ( $targetH && $targetH < $h ) {
		$im = imagescale( $im, (int) round( $w * $targetH / $h ), $targetH, IMG_BICUBIC );
		imagesavealpha( $im, true );
	}
	imagepng( $im, $out, 9 );
	printf( "wrote %s (%dx%d)\n", basename( $out ), imagesx( $im ), imagesy( $im ) );
	return $im;
};

$full = $bbox( 200, $H - 150 );
$mark = $bbox( 200, $split );

$render( $full, $ink, 12, 0, "$dir/logo-full.png" );
$render( $mark, $ink, 8, 0, "$dir/logo-mark.png" );
$render( $full, [ 0xf1, 0xe5, 0xd9 ], 12, 0, "$dir/logo-light.png" );

// Square icon: mark centred on cream with breathing room.
$markIm = imagecreatefrompng( "$dir/logo-mark.png" );
$S      = 512;
$icon   = imagecreatetruecolor( $S, $S );
imagefill( $icon, 0, 0, imagecolorallocate( $icon, ...$bg ) );
imagealphablending( $icon, true );
$mw = imagesx( $markIm ); $mh = imagesy( $markIm );
$scale = min( ( $S * 0.78 ) / $mw, ( $S * 0.78 ) / $mh );
$dw = (int) ( $mw * $scale ); $dh = (int) ( $mh * $scale );
imagecopyresampled( $icon, $markIm, (int) ( ( $S - $dw ) / 2 ), (int) ( ( $S - $dh ) / 2 ), 0, 0, $dw, $dh, $mw, $mh );
imagepng( $icon, "$dir/icon.png", 9 );
echo "wrote icon.png (512x512)\n";
