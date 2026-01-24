<?php
/* 
   Astra Child – functions.php
   FoodDelivery: підключення стилів + форма замовлення + БД кур’єрів
    */
if ( ! defined( 'ABSPATH' ) ) { exit; }
/* 
 Підключення CSS дочірньої теми (Child Theme Configurator)
    */

if ( ! function_exists( 'chld_thm_cfg_locale_css' ) ) :
	function chld_thm_cfg_locale_css( $uri ) {
		if ( empty( $uri ) && is_rtl() && file_exists( get_template_directory() . '/rtl.css' ) ) {
			$uri = get_template_directory_uri() . '/rtl.css';
		}
		return $uri;
	}
endif;
add_filter( 'locale_stylesheet_uri', 'chld_thm_cfg_locale_css' );

if ( ! function_exists( 'child_theme_configurator_css' ) ) :
	function child_theme_configurator_css() {
		wp_enqueue_style(
			'chld_thm_cfg_child',
			trailingslashit( get_stylesheet_directory_uri() ) . 'style.css',
			array( 'astra-theme-css', 'woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-general' )
		);
	}
endif;
add_action( 'wp_enqueue_scripts', 'child_theme_configurator_css', 10 );

/* 
   Створення таблиць БД (кур’єри + замовлення) 
   */
add_action( 'init', function () {
	if ( ! get_option( 'fd_tables_created' ) ) {
		fd_create_tables();
		update_option( 'fd_tables_created', '1' );
	}
});
function fd_create_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset    = $wpdb->get_charset_collate();
	$t_couriers = $wpdb->prefix . 'fd_couriers';
	$t_orders   = $wpdb->prefix . 'fd_orders';

	// Таблиця кур’єрів
	$sql1 = "CREATE TABLE $t_couriers (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(120) NOT NULL,
		phone VARCHAR(40) NULL,
		is_active TINYINT(1) NOT NULL DEFAULT 1,
		PRIMARY KEY (id)
	) $charset;";

	// Таблиця замовлень 
	$sql2 = "CREATE TABLE $t_orders (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		customer_name VARCHAR(120) NOT NULL,
		customer_phone VARCHAR(40) NOT NULL,
		address TEXT NOT NULL,
		note TEXT NULL,
		courier_id BIGINT UNSIGNED NULL,
		status VARCHAR(30) NOT NULL DEFAULT 'new',
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY courier_id (courier_id),
		KEY status (status)
	) $charset;";

	dbDelta( $sql1 );
	dbDelta( $sql2 );

	// Додати тестових кур’єрів, якщо таблиця порожня
	$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t_couriers" );
	if ( $count === 0 ) {
		$wpdb->insert( $t_couriers, array( 'name' => 'Кур’єр 1', 'phone' => '+380000000001', 'is_active' => 1 ) );
		$wpdb->insert( $t_couriers, array( 'name' => 'Кур’єр 2', 'phone' => '+380000000002', 'is_active' => 1 ) );
		$wpdb->insert( $t_couriers, array( 'name' => 'Кур’єр 3', 'phone' => '+380000000003', 'is_active' => 1 ) );
	}
}
/* =========================================================
   Логіка вибору кур’єра (найменше активних замовлень)
   ========================================================= */

function fd_pick_courier_id() {
  global $wpdb;
  $t_couriers = $wpdb->prefix . 'fd_couriers';
  $t_orders   = $wpdb->prefix . 'fd_orders';

  // дата по часовому поясу WordPress
  $today = current_time('Y-m-d'); // напр. 2026-01-16

  // Вибираємо активного кур’єра з мінімумом АКТИВНИХ замовлень СЬОГОДНІ
  $sql = $wpdb->prepare("
    SELECT c.id
    FROM $t_couriers c
    LEFT JOIN $t_orders o
      ON o.courier_id = c.id
      AND o.status IN ('new','assigned','in_delivery')
      AND DATE(o.created_at) = %s
    WHERE c.is_active = 1
    GROUP BY c.id
    ORDER BY COUNT(o.id) ASC, c.id ASC
    LIMIT 1
  ", $today);

  return (int) $wpdb->get_var($sql);
}

function fd_get_courier_name( $courier_id ) {
	global $wpdb;
	$t_couriers = $wpdb->prefix . 'fd_couriers';
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM $t_couriers WHERE id=%d", $courier_id ) );
}

/*  Форма замовлення*/

add_shortcode( 'fd_order_form', function () {
	global $wpdb;
	$t_orders = $wpdb->prefix . 'fd_orders';

	$msg = '';

	// Обробка відправки форми
	if (
		$_SERVER['REQUEST_METHOD'] === 'POST'
		&& isset( $_POST['fd_order_nonce'] )
		&& wp_verify_nonce( $_POST['fd_order_nonce'], 'fd_order' )
	) {
		$name    = sanitize_text_field( $_POST['customer_name'] ?? '' );
		$phone   = sanitize_text_field( $_POST['customer_phone'] ?? '' );
		$address = sanitize_textarea_field( $_POST['address'] ?? '' );
		$note    = sanitize_textarea_field( $_POST['note'] ?? '' );

		// Перевірка формату телефону: +380XXXXXXXXX
		$phone_ok = (bool) preg_match( '/^\+380\d{9}$/', $phone );

		if ( ! $name || ! $phone || ! $address ) {
			$msg = '<div class="fd-error">Заповни ім’я, телефон і адресу.</div>';
		} elseif ( ! $phone_ok ) {
			$msg = '<div class="fd-error">Телефон має бути у форматі: +380XXXXXXXXX (9 цифр після +380).</div>';
		} elseif ( function_exists('WC') && WC()->cart && WC()->cart->is_empty() ) {
			// Забороняємо замовлення без товарів у кошику
			$msg = '<div class="fd-error">Додай хоча б один товар у кошик перед оформленням.</div>';
		} else {
			$courier_id = fd_pick_courier_id();

			$wpdb->insert( $t_orders, array(
				'customer_name'  => $name,
				'customer_phone' => $phone,
				'address'        => $address,
				'note'           => $note,
				'courier_id'     => $courier_id,
				'status'         => 'assigned',
			) );

			$courier_name = $courier_id ? fd_get_courier_name( $courier_id ) : 'не призначено';
			$msg = '<div class="fd-success">Замовлення прийнято. Кур’єр: <b>' . esc_html( $courier_name ) . '</b></div>';
		}
	}

	ob_start(); ?>
	<div class="fd-order">
		<?php echo $msg; ?>

		<form method="post" class="fd-order-form">
			<?php wp_nonce_field( 'fd_order', 'fd_order_nonce' ); ?>

			<label>Ім’я*</label>
			<input type="text" name="customer_name" required>

			<label>Телефон*</label>
			<input
				type="tel"
				name="customer_phone"
				required
				inputmode="numeric"
				pattern="^\+380\d{9}$"
				placeholder="+380XXXXXXXXX"
				value="+380"
			>
			<small style="display:block;margin-top:6px;color:#666;">Формат: +380XXXXXXXXX</small>

			<label>Адреса доставки*</label>
			<textarea name="address" rows="3" required></textarea>

			<label>Коментар</label>
			<textarea name="note" rows="3"></textarea>

			<button type="submit" class="fd-btn">Оформити замовлення</button>
		</form>
	</div>
	<?php
	return ob_get_clean();
});

/*  5) фіксація +380 і заборона “букв у номері телефону
    */

add_action( 'wp_footer', function () { ?>
<script>
document.addEventListener('input', function(e){
  if(e.target && e.target.name === 'customer_phone'){
    let v = e.target.value || '';
    if(!v.startsWith('+380')) v = '+380' + v.replace(/^\+?3?8?0?/, '');
    v = '+380' + v.slice(4).replace(/\D/g,'').slice(0,9);
    e.target.value = v;
  }
});
</script>
<?php });
/* 
   Кінець файлу functions.php */
/* 
   Доступ до сторінки "Замовлення" тільки якщо кошик НЕ порожній
   (перевірка по ID сторінки)
    */

add_action('template_redirect', function () {
  $order_page_id = 8504;

  if (!function_exists('is_page')) return;
  if (!is_page($order_page_id)) return;

  if (function_exists('WC') && WC()->cart) {
    if (WC()->cart->is_empty()) {
      wp_safe_redirect(wc_get_page_permalink('shop'));
      exit;
    }
  } else {
    wp_safe_redirect(wc_get_page_permalink('shop'));
    exit;
  }
});

