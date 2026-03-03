<?php
/**
 * Plugin Name: Clientes - Visor (Solo Nombre y Correo)
 * Description: Crea un rol que solo puede ver una lista de clientes (nombre + correo) sin exportar ni acceder a WooCommerce (HPOS compatible).
 * Version: 2.0
 */

if (!defined('ABSPATH')) exit;

const PP_CLIENT_VIEW_CAP = 'pp_view_customers_list';
const PP_CLIENT_VIEW_ROLE = 'pp_client_viewer';

/**
 * 1) Crear rol (solo una vez)
 */
add_action('init', function () {
    if (!get_role(PP_CLIENT_VIEW_ROLE)) {
        add_role(PP_CLIENT_VIEW_ROLE, 'Visor de Clientes', [
            'read' => true,
            PP_CLIENT_VIEW_CAP => true,
        ]);
    }
}, 99);

/**
 * 2) Registrar página del admin
 */
add_action('admin_menu', function () {
    add_menu_page(
        'Clientes (solo lectura)',
        'Clientes',
        PP_CLIENT_VIEW_CAP,
        'pp-clientes-visor',
        'pp_render_clientes_visor_page',
        'dashicons-email-alt2',
        56
    );
}, 20);

/**
 * 3) Bloquear acceso a otras páginas del admin (redirige al visor)
 */
add_action('admin_init', function () {
    if (!is_user_logged_in()) return;

    $user = wp_get_current_user();
    if (!in_array(PP_CLIENT_VIEW_ROLE, (array)$user->roles, true)) return;

    $allowed_pages = [
        'pp-clientes-visor',
        'profile', // opcional
    ];

    $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
    $is_ajax = (defined('DOING_AJAX') && DOING_AJAX);

    if (!$is_ajax && !in_array($page, $allowed_pages, true)) {
        wp_safe_redirect(admin_url('admin.php?page=pp-clientes-visor'));
        exit;
    }
}, 1);

/**
 * 4) Ocultar menús para el rol
 */
add_action('admin_menu', function () {
    $user = wp_get_current_user();
    if (!in_array(PP_CLIENT_VIEW_ROLE, (array)$user->roles, true)) return;

    remove_menu_page('index.php');
    remove_menu_page('edit.php');
    remove_menu_page('upload.php');
    remove_menu_page('edit.php?post_type=page');
    remove_menu_page('edit-comments.php');
    remove_menu_page('themes.php');
    remove_menu_page('plugins.php');
    remove_menu_page('users.php');
    remove_menu_page('tools.php');
    remove_menu_page('options-general.php');

    // WooCommerce / CPTs
    remove_menu_page('woocommerce');
    remove_menu_page('edit.php?post_type=product');
    remove_menu_page('edit.php?post_type=shop_order');
}, 999);

/**
 * 5) Render de la tabla con paginación
 */
function pp_render_clientes_visor_page() {
    if (!current_user_can(PP_CLIENT_VIEW_CAP)) {
        wp_die('No tienes permisos para ver esta página.');
    }

    $search   = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $paged    = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = isset($_GET['per_page']) ? max(10, min(500, intval($_GET['per_page']))) : 100;

    $result = pp_get_buyers_hpos_addresses($search, $paged, $per_page);
    $rows   = $result['rows'];
    $total  = (int)$result['total'];

    $total_pages = (int)ceil($total / $per_page);

    echo '<div class="wrap">';
    echo '<h1>Clientes (solo lectura)</h1>';
    echo '<p>Total de clientes únicos por email: <strong>' . number_format_i18n($total) . '</strong></p>';

    echo '<form method="get" style="margin:15px 0; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">';
    echo '<input type="hidden" name="page" value="pp-clientes-visor" />';

    echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Buscar por nombre o correo..." style="min-width:320px;" />';

    echo '<select name="per_page">';
    foreach ([50, 100, 200, 500] as $opt) {
        echo '<option value="' . esc_attr($opt) . '" ' . selected($per_page, $opt, false) . '>' . esc_html($opt) . '</option>';
    }
    echo '</select>';

    echo '<button class="button button-primary">Buscar</button>';
    echo '</form>';

    echo '<table class="widefat striped" style="max-width:900px;">';
    echo '<thead><tr><th style="width:50%;">Nombre</th><th>Correo</th></tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="2">Sin resultados.</td></tr>';
    } else {
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td>' . esc_html($r['name']) . '</td>';
            echo '<td><a href="mailto:' . esc_attr($r['email']) . '">' . esc_html($r['email']) . '</a></td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';

    // Paginación
    if ($total_pages > 1) {
        $base_url = admin_url('admin.php?page=pp-clientes-visor');
        $args = ['s' => $search, 'per_page' => $per_page];

        echo '<div style="margin-top:15px;">';

        if ($paged > 1) {
            echo '<a class="button" href="' . esc_url(add_query_arg(array_merge($args, ['paged' => $paged - 1]), $base_url)) . '">‹ Anterior</a> ';
        } else {
            echo '<span class="button disabled">‹ Anterior</span> ';
        }

        echo '<span style="margin:0 10px;">Página <strong>' . $paged . '</strong> de <strong>' . $total_pages . '</strong></span>';

        if ($paged < $total_pages) {
            echo '<a class="button" href="' . esc_url(add_query_arg(array_merge($args, ['paged' => $paged + 1]), $base_url)) . '">Siguiente ›</a>';
        } else {
            echo '<span class="button disabled">Siguiente ›</span>';
        }

        echo '</div>';
    }

    echo '</div>';
}

/**
 * 6) HPOS real:
 * - estados + order_id: wc_order_stats
 * - nombre + email: wc_order_addresses (address_type = 'billing')
 * - únicos por email
 * - paginación eficiente en DB
 */
function pp_get_buyers_hpos_addresses(string $search = '', int $paged = 1, int $per_page = 100): array {
    global $wpdb;

    $offset = ($paged - 1) * $per_page;

    $t_stats = $wpdb->prefix . 'wc_order_stats';
    $t_addr  = $wpdb->prefix . 'wc_order_addresses';

    // Ajusta si quieres incluir más estados
    $statuses = ['wc-processing', 'wc-completed', 'wc-on-hold'];
    $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));

    $params = $statuses;

    $where_search = '';
    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where_search = " AND (
            LOWER(TRIM(a.email)) LIKE LOWER(%s)
            OR CONCAT(COALESCE(a.first_name,''),' ',COALESCE(a.last_name,'')) LIKE %s
        )";
        $params[] = $like;
        $params[] = $like;
    }

    // TOTAL únicos por email
    $sql_total = "
        SELECT COUNT(*) FROM (
            SELECT LOWER(TRIM(a.email)) AS buyer_email
            FROM {$t_stats} s
            INNER JOIN {$t_addr} a
                ON a.order_id = s.order_id
               AND a.address_type = 'billing'
            WHERE s.status IN ($status_placeholders)
              AND a.email IS NOT NULL
              AND TRIM(a.email) <> ''
              $where_search
            GROUP BY buyer_email
        ) t
    ";
    $total = (int)$wpdb->get_var($wpdb->prepare($sql_total, ...$params));

    // ROWS: email + último order_id por email (para mostrar nombre “más reciente”)
    $sql_rows = "
        SELECT buyer_email AS email, MAX(order_id) AS last_order_id
        FROM (
            SELECT
              s.order_id,
              LOWER(TRIM(a.email)) AS buyer_email
            FROM {$t_stats} s
            INNER JOIN {$t_addr} a
                ON a.order_id = s.order_id
               AND a.address_type = 'billing'
            WHERE s.status IN ($status_placeholders)
              AND a.email IS NOT NULL
              AND TRIM(a.email) <> ''
              $where_search
        ) x
        GROUP BY buyer_email
        ORDER BY last_order_id DESC
        LIMIT %d OFFSET %d
    ";

    $params_rows = $params;
    $params_rows[] = $per_page;
    $params_rows[] = $offset;

    $emails = $wpdb->get_results($wpdb->prepare($sql_rows, ...$params_rows), ARRAY_A);

    if (empty($emails)) {
        return ['rows' => [], 'total' => $total];
    }

    // Resolver nombre del último pedido (billing)
    $rows = [];
    foreach ($emails as $e) {
        $email = $e['email'];
        $last_order_id = (int)$e['last_order_id'];

        $addr = $wpdb->get_row($wpdb->prepare(
            "SELECT first_name, last_name
             FROM {$t_addr}
             WHERE order_id = %d AND address_type = 'billing'
             LIMIT 1",
            $last_order_id
        ), ARRAY_A);

        $first = $addr['first_name'] ?? '';
        $last  = $addr['last_name'] ?? '';
        $name  = trim($first . ' ' . $last);
        if ($name === '') $name = '—';

        $rows[] = ['name' => $name, 'email' => $email];
    }

    return ['rows' => $rows, 'total' => $total];
}

/**
 * 7) Redirección al visor tras login
 */
add_filter('login_redirect', function ($redirect_to, $request, $user) {
    if ($user instanceof WP_User && in_array(PP_CLIENT_VIEW_ROLE, (array)$user->roles, true)) {
        return admin_url('admin.php?page=pp-clientes-visor');
    }
    return $redirect_to;
}, 10, 3);

/**
 * 8) Permitir wp-admin para este rol (WooCommerce a veces lo bloquea)
 */
add_filter('woocommerce_prevent_admin_access', function ($prevent) {
    $user = wp_get_current_user();
    if (in_array(PP_CLIENT_VIEW_ROLE, (array)$user->roles, true)) {
        return false;
    }
    return $prevent;
}, 10, 1);