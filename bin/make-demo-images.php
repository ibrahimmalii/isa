<?php
/**
 * Draws soft placeholder product shots for the demo catalog (GD only, no assets).
 *   docker run --rm -v "$PWD":/app wordpress:php8.3-apache php /app/bin/make-demo-images.php /app/products/demo-images
 */

$out = $argv[1] ?? __DIR__ . '/../products/demo-images';
@mkdir( $out, 0777, true );

$items = [
	'demo-cleanser.png' => [ [ 239, 230, 220 ], [ 214, 226, 219 ], 'tube' ],
	'demo-serum.png'    => [ [ 248, 226, 205 ], [ 240, 196, 170 ], 'dropper' ],
	'demo-cream.png'    => [ [ 236, 226, 236 ], [ 232, 201, 189 ], 'jar' ],
	'demo-spf.png'      => [ [ 250, 240, 214 ], [ 238, 214, 170 ], 'bottle' ],
];

$S = 900;
foreach ( $items as $file => [ $top, $bottom, $shape ] ) {
	$im = imagecreatetruecolor( $S, $S );
	imageantialias( $im, true );
	for ( $y = 0; $y < $S; $y++ ) { // vertical gradient background
		$t = $y / $S;
		$c = imagecolorallocate( $im, ...array_map( fn( $a, $b ) => (int) ( $a + ( $b - $a ) * $t ), $top, $bottom ) );
		imageline( $im, 0, $y, $S, $y, $c );
	}
	$white  = imagecolorallocate( $im, 255, 255, 255 );
	$cap    = imagecolorallocate( $im, 43, 37, 34 );
	$label  = imagecolorallocate( $im, 184, 118, 106 );
	$shadow = imagecolorallocatealpha( $im, 43, 37, 34, 105 );

	imagefilledellipse( $im, 450, 760, 360, 50, $shadow );
	switch ( $shape ) {
		case 'tube':
			imagefilledrectangle( $im, 340, 250, 560, 700, $white );
			imagefilledrectangle( $im, 390, 700, 510, 760, $cap );
			imagefilledpolygon( $im, [ 340, 250, 560, 250, 540, 210, 360, 210 ], $white );
			break;
		case 'dropper':
			imagefilledrectangle( $im, 360, 380, 540, 740, $white );
			imagefilledrectangle( $im, 405, 290, 495, 380, $cap );
			imagefilledellipse( $im, 450, 270, 70, 90, $cap );
			break;
		case 'jar':
			imagefilledrectangle( $im, 290, 470, 610, 740, $white );
			imagefilledrectangle( $im, 280, 400, 620, 470, $cap );
			break;
		default: // bottle
			imagefilledrectangle( $im, 350, 330, 550, 740, $white );
			imagefilledrectangle( $im, 415, 250, 485, 330, $white );
			imagefilledrectangle( $im, 400, 190, 500, 250, $cap );
	}
	// label band + wordmark-ish bars (no fonts needed)
	imagefilledrectangle( $im, 380, 540, 520, 548, $label );
	imagefilledrectangle( $im, 400, 565, 500, 570, $label );
	imagepng( $im, "$out/$file", 6 );
	imagedestroy( $im );
	echo "wrote $file\n";
}
