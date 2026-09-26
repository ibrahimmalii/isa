<?php
/**
 * Search-engine copy: product descriptions, category descriptions, and the Google title and
 * description ("Search engines" box, English + Arabic) for products, categories and pages.
 * Arabic for the descriptions themselves is in bin/translations-ar.php (run seed-translations.php after this).
 *
 *   sudo -u www-data wp --path=/var/www/isa eval-file /bin-isa/seo-content.php
 *
 * Only fills what is empty, so edits made in wp-admin are kept. Add `force` after the file name to overwrite.
 * Products, pages and categories are matched by slug; missing ones are skipped.
 */

defined( 'ABSPATH' ) || exit;

$force   = (bool) array_intersect( [ 'force', '--force' ], $args ?? [] );
$written = 0;

/** Set a value only when it's empty (or forced). */
$fill = function ( string $current, string $new, callable $save ) use ( $force, &$written ) {
	if ( '' === trim( $current ) || $force ) {
		if ( trim( $current ) !== $new ) {
			$save( $new );
			$written++;
		}
	}
};

// Copy key => meta key of the "Search engines" box.
$seo_keys = [ 'title' => '_isa_seo_title', 'desc' => '_isa_seo_desc', 'title_ar' => '_isa_seo_title_ar', 'desc_ar' => '_isa_seo_desc_ar' ];

$link = function ( string $slug ): string {
	$post = get_page_by_path( $slug, OBJECT, 'product' );
	return $post ? wp_make_link_relative( get_permalink( $post ) ) : '/shop/';
};

/* -------------------------------------------------------------------------
 * Products
 * ---------------------------------------------------------------------- */

$products = [
	'tint'        => [
		'title' => 'Lip & Cheek Tint – Red, Orange & Rose | isa Egypt',
		'desc'  => 'isa Tint: one tint for lips and cheeks in Red, Orange and Rose. Wear it sheer for a natural flush or build it up for bold colour. Delivered anywhere in Egypt.',
		'title_ar' => 'تينت للشفايف والخدود – أحمر، برتقالي، روز | isa مصر',
		'desc_ar'  => 'تينت isa للشفايف والخدود بثلاثة ألوان: أحمر وبرتقالي وروز. طبقة خفيفة للون طبيعي، أو أكثر للون أقوى. توصيل لكل محافظات مصر.',
		'short' => '<p>One tint for your lips and cheeks, in Red, Orange and Rose. Sheer or bold, you decide.</p>',
		'long'  => '<p>isa Tint is a lip and cheek tint: colour for your lips and a flush for your cheeks from one small pack that fits in any bag.</p>
<h3>Shades</h3>
<ul>
<li>Red: a classic red for lips and a warm glow on cheeks.</li>
<li>Orange: a bright, sunny orange for a fresh summer look.</li>
<li>Rose: a soft rose pink for everyday wear.</li>
</ul>
<h3>How to use</h3>
<ul>
<li>Lips: dab a little on the centre of your lips, then press them together or blend with your fingertip.</li>
<li>Cheeks: dot it on the apples of your cheeks and blend straight away with your fingertips.</li>
<li>Build it up: one layer for a sheer look, a second layer for more colour.</li>
</ul>
<h3>More than one shade?</h3>
<p>Choose a quantity for each shade and add them all to your cart in one go.</p>
<p>We deliver to every governorate in Egypt. Pay by InstaPay or Vodafone Cash.</p>',
	],
	'body-splash' => [
		'title' => 'Body Splash – Tropical & Coconut Vanilla | isa Egypt',
		'desc'  => 'isa Body Splash in two scents: Tropical and Warm Coconut & Vanilla. Spray it on after your shower or any time you want to feel fresh. Delivered anywhere in Egypt.',
		'title_ar' => 'بودي سبلاش – تروبيكال وجوز الهند والفانيليا | isa مصر',
		'desc_ar'  => 'بودي سبلاش من isa برائحتين: تروبيكال، وجوز الهند الدافئ مع الفانيليا. استخدمه بعد الاستحمام أو في أي وقت تحب أن تشعر فيه بالانتعاش. توصيل لكل محافظات مصر.',
		'short' => '<p>A body splash in two scents: Tropical, and Warm Coconut &amp; Vanilla.</p>',
		'long'  => '<p>isa Body Splash is a fragrance mist for your body. Spray it on after your shower, before you go out, or any time you want to smell good during the day.</p>
<h3>Scents</h3>
<ul>
<li>Tropical: fruity and sunny, like a summer holiday by the sea.</li>
<li>Warm Coconut &amp; Vanilla: creamy coconut with soft, sweet vanilla.</li>
</ul>
<h3>How to use</h3>
<ul>
<li>Spray from about 20 cm onto your arms, neck and body.</li>
<li>For a stronger scent, apply to clean skin after your shower and spray again during the day.</li>
<li>Keep away from your eyes and from irritated skin.</li>
</ul>
<h3>Match your hair</h3>
<p>isa Hair Mist comes in the same scents, so your hair and body smell the same.</p>
<p><a href="' . $link( 'hair-mist' ) . '">Shop isa Hair Mist</a></p>
<p>We deliver to every governorate in Egypt. Pay by InstaPay or Vodafone Cash.</p>',
	],
	'hair-mist'   => [
		'title' => 'Hair Mist – Tropical, Coconut Vanilla & Cheesecake | isa',
		'desc'  => 'isa Hair Mist: a fragrance spray for your hair in Tropical, Warm Coconut & Vanilla and Cheesecake scents. For hair worn down or under a hijab. Delivered in Egypt.',
		'title_ar' => 'هير ميست – تروبيكال، جوز الهند والفانيليا، تشيز كيك | isa',
		'desc_ar'  => 'هير ميست من isa: عطر للشعر بروائح تروبيكال، وجوز الهند الدافئ مع الفانيليا، وتشيز كيك. لشعرك سواء كان ظاهرًا أو تحت الحجاب. توصيل لكل محافظات مصر.',
		'short' => '<p>A fragrance mist for your hair, in fruity, warm and sweet scents.</p>',
		'long'  => '<p>isa Hair Mist is a fragrance spray made for your hair. A few sprays and your hair smells good, whether you wear it down, tied up or under a hijab.</p>
<h3>Scents</h3>
<ul>
<li>Tropical: fruity and sunny.</li>
<li>Warm Coconut &amp; Vanilla: creamy coconut with soft, sweet vanilla.</li>
<li>Bound Cheesecake: a sweet, creamy dessert scent.</li>
</ul>
<h3>How to use</h3>
<ul>
<li>Spray 2 or 3 times from about 20 cm onto dry hair, mostly on the lengths and ends.</li>
<li>Spray again during the day whenever you like.</li>
<li>Keep away from your eyes.</li>
</ul>
<h3>Match your body</h3>
<p>isa Body Splash comes in Tropical and Warm Coconut &amp; Vanilla too, for one scent from head to toe.</p>
<p><a href="' . $link( 'body-splash' ) . '">Shop isa Body Splash</a></p>
<p>We deliver to every governorate in Egypt. Pay by InstaPay or Vodafone Cash.</p>',
	],
];

foreach ( $products as $slug => $copy ) {
	$post = get_page_by_path( $slug, OBJECT, 'product' );
	if ( ! $post ) {
		WP_CLI::warning( "product '$slug' not found, skipped" );
		continue;
	}
	$id = $post->ID;
	foreach ( $seo_keys as $key => $meta ) {
		$fill( (string) get_post_meta( $id, $meta, true ), $copy[ $key ], fn( $v ) => update_post_meta( $id, $meta, $v ) );
	}
	$fill( $post->post_excerpt, $copy['short'], fn( $v ) => wp_update_post( [ 'ID' => $id, 'post_excerpt' => $v ] ) );
	$fill( $post->post_content, $copy['long'], fn( $v ) => wp_update_post( [ 'ID' => $id, 'post_content' => $v ] ) );
}

/* -------------------------------------------------------------------------
 * Product categories (the description shows above the products)
 * ---------------------------------------------------------------------- */

$categories = [
	'lip-cheek' => [
		'title' => 'Lip & Cheek Tint in Egypt | isa',
		'desc'  => 'Lip and cheek tints from isa: colour for your lips and a natural flush for your cheeks in one product. Order online, delivered anywhere in Egypt.',
		'title_ar' => 'تينت الشفايف والخدود في مصر | isa',
		'desc_ar'  => 'تينت الشفايف والخدود من isa: لون لشفايفك واحمرار طبيعي لخدودك في منتج واحد. الطلب أونلاين والتوصيل لكل محافظات مصر.',
		'text'  => 'Lip and cheek tints: colour for your lips and a natural flush for your cheeks, all in one product. Wear it sheer or build it up.',
	],
	'body-care' => [
		'title' => 'Body Splash & Body Care in Egypt | isa',
		'desc'  => 'Body splashes from isa in fruity, warm and sweet scents to wear every day. Order online and pay by InstaPay or Vodafone Cash. Delivered anywhere in Egypt.',
		'title_ar' => 'بودي سبلاش ومنتجات العناية بالجسم في مصر | isa',
		'desc_ar'  => 'بودي سبلاش من isa بروائح فاكهية ودافئة وحلوة للاستخدام اليومي. الدفع بإنستاباي أو فودافون كاش، والتوصيل لكل محافظات مصر.',
		'text'  => 'Body splashes in fruity, warm and sweet scents to wear every day, after your shower or on the go.',
	],
	'hair-care' => [
		'title' => 'Hair Mist & Hair Care in Egypt | isa',
		'desc'  => 'Hair mists from isa: fragrance sprays for your hair in fruity, warm and sweet scents, for hair worn down or under a hijab. Delivered anywhere in Egypt.',
		'title_ar' => 'هير ميست ومنتجات العناية بالشعر في مصر | isa',
		'desc_ar'  => 'هير ميست من isa: عطر للشعر بروائح فاكهية ودافئة وحلوة، سواء كان شعرك ظاهرًا أو تحت الحجاب. التوصيل لكل محافظات مصر.',
		'text'  => 'Hair mists: fragrance sprays for your hair in fruity, warm and sweet scents, for hair worn down or under a hijab.',
	],
];

foreach ( $categories as $slug => $copy ) {
	$term = get_term_by( 'slug', $slug, 'product_cat' );
	if ( ! $term ) {
		WP_CLI::warning( "category '$slug' not found, skipped" );
		continue;
	}
	$id = $term->term_id;
	foreach ( $seo_keys as $key => $meta ) {
		$fill( (string) get_term_meta( $id, $meta, true ), $copy[ $key ], fn( $v ) => update_term_meta( $id, $meta, $v ) );
	}
	$fill( $term->description, $copy['text'], fn( $v ) => wp_update_term( $id, 'product_cat', [ 'description' => $v ] ) );
}

/* -------------------------------------------------------------------------
 * Pages
 * ---------------------------------------------------------------------- */

$pages = [
	'shop'             => [ 'Shop Skin Care, Tints & Body Splash Online in Egypt | isa', 'Shop all isa products online: lip & cheek tints, body splashes and hair mists. Pay by InstaPay or Vodafone Cash, delivered anywhere in Egypt.',
		'تسوق منتجات العناية بالبشرة والتينت والبودي سبلاش أونلاين في مصر | isa', 'تسوق كل منتجات isa أونلاين: تينت للشفايف والخدود، وبودي سبلاش، وهير ميست. الدفع بإنستاباي أو فودافون كاش، والتوصيل لكل محافظات مصر.' ],
	'about'            => [ 'About isa skincare | Egypt', 'isa started with a simple idea: good skin care should be easy to understand, easy to buy, and kind to your skin. Meet the store behind the products.',
		'عن isa للعناية بالبشرة | مصر', 'بدأت isa بفكرة بسيطة: العناية الجيدة بالبشرة يجب أن تكون سهلة الفهم، وسهلة الشراء، ولطيفة على بشرتك. تعرّف على المتجر.' ],
	'faq'              => [ 'FAQ: Delivery, Shipping & Payment | isa Egypt', 'How long delivery takes, how much shipping costs, how to pay by InstaPay or Vodafone Cash, and how to choose the right isa product.',
		'الأسئلة الشائعة: التوصيل والشحن والدفع | isa مصر', 'كم يستغرق التوصيل، وكم تكلفة الشحن، وكيف تدفع بإنستاباي أو فودافون كاش، وكيف تختار منتج isa المناسب لك.' ],
	'contact'          => [ 'Contact isa skincare | WhatsApp & Instagram', 'Talk to isa on WhatsApp, Instagram or TikTok. A question about a product, your order or delivery in Egypt? Message us any time.',
		'تواصل مع isa | واتساب وإنستجرام', 'تواصل مع isa على واتساب أو إنستجرام أو تيك توك. عندك سؤال عن منتج أو عن طلبك أو التوصيل؟ راسلنا في أي وقت.' ],
	'shipping-returns' => [ 'Shipping & Returns | isa Egypt', 'Delivery to every governorate in Egypt, shipping paid to the courier on arrival, and how returns and refunds work at isa.',
		'الشحن والاسترجاع | isa مصر', 'توصيل لكل محافظات مصر، ومصاريف الشحن تُدفع للمندوب عند الاستلام، وطريقة الاسترجاع واسترداد المبلغ في isa.' ],
	'privacy-policy'   => [ 'Privacy Policy | isa', 'How isa collects, uses and protects your personal information when you visit the site or place an order.',
		'سياسة الخصوصية | isa', 'كيف تجمع isa بياناتك الشخصية وتستخدمها وتحميها عند زيارة الموقع أو تقديم طلب.' ],
	'terms'            => [ 'Terms & Conditions | isa', 'The terms for using the isa website and ordering isa products in Egypt.',
		'الشروط والأحكام | isa', 'شروط استخدام موقع isa وطلب منتجات isa في مصر.' ],
];

foreach ( $pages as $slug => [ $title, $desc, $title_ar, $desc_ar ] ) {
	$page = get_page_by_path( $slug );
	if ( ! $page ) {
		WP_CLI::warning( "page '$slug' not found, skipped" );
		continue;
	}
	$id = $page->ID;
	$fill( (string) get_post_meta( $id, '_isa_seo_title', true ), $title, fn( $v ) => update_post_meta( $id, '_isa_seo_title', $v ) );
	$fill( (string) get_post_meta( $id, '_isa_seo_desc', true ), $desc, fn( $v ) => update_post_meta( $id, '_isa_seo_desc', $v ) );
	$fill( (string) get_post_meta( $id, '_isa_seo_title_ar', true ), $title_ar, fn( $v ) => update_post_meta( $id, '_isa_seo_title_ar', $v ) );
	$fill( (string) get_post_meta( $id, '_isa_seo_desc_ar', true ), $desc_ar, fn( $v ) => update_post_meta( $id, '_isa_seo_desc_ar', $v ) );
}

// Home page: add the intro paragraph as the last section.
$home = (int) get_option( 'page_on_front' );
if ( $home && false === strpos( (string) get_post_field( 'post_content', $home ), '[isa_intro]' ) ) {
	wp_update_post( [ 'ID' => $home, 'post_content' => rtrim( get_post_field( 'post_content', $home ) ) . "\n\n<!-- wp:shortcode -->\n[isa_intro]\n<!-- /wp:shortcode -->\n" ] );
	$written++;
}

WP_CLI::success( "SEO copy: $written field(s) written" . ( $force ? ' (forced)' : '' ) );
