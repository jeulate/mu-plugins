<?php
/**
 * Plugin Name: Clientes - Visor (Solo Nombre y Correo)
 * Description: Crea un rol que solo puede ver una lista de clientes (nombre + correo) sin exportar ni acceder a WooCommerce.
 * Version: 1.0
 */

if (!defined('ABSPATH')) exit;

const PP_CLIENT_VIEW_CAP = 'pp_view_customers_list';

// TEMPORAL: borrar rol para recrearlo limpio (usar 1 vez y luego borrar)
add_action('admin_init', function () {
    if (current_user_can('administrator')) {
        remove_role('pp_client_viewer');
    }
});

// 1) Crear rol (solo una vez)
add_action('init', function () {
    if (!get_role('pp_client_viewer')) {
        add_role('pp_client_viewer', 'Visor de Clientes', [
            'read' => true,
            PP_CLIENT_VIEW_CAP => true,
        ]);
    }
}, 20);

// 2) Registrar página del admin solo para este rol
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

// 3) Bloquear acceso a otras páginas del admin (redirecciona al visor)
add_action('admin_init', function () {
    if (!is_user_logged_in()) return;

    $user = wp_get_current_user();
    if (!in_array('pp_client_viewer', (array) $user->roles, true)) return;

    $allowed = [
        'pp-clientes-visor',
        'profile', // para que pueda ver su perfil (opcional)
    ];

    $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';

    // Permitir también admin-ajax.php para que WP no falle en algunas acciones internas
    $is_ajax = (defined('DOING_AJAX') && DOING_AJAX);

    if (!$is_ajax && !in_array($page, $allowed, true)) {
        wp_safe_redirect(admin_url('admin.php?page=pp-clientes-visor'));
        exit;
    }
}, 1);

// 4) Ocultar menús para el rol
add_action('admin_menu', function () {
    $user = wp_get_current_user();
    if (!in_array('pp_client_viewer', (array) $user->roles, true)) return;

    // Dejamos solo "Clientes" y opcionalmente "Perfil"
    remove_menu_page('index.php'); // Escritorio
    remove_menu_page('edit.php'); // Entradas
    remove_menu_page('upload.php'); // Medios
    remove_menu_page('edit.php?post_type=page'); // Páginas
    remove_menu_page('edit-comments.php'); // Comentarios
    remove_menu_page('themes.php'); // Apariencia
    remove_menu_page('plugins.php'); // Plugins
    remove_menu_page('users.php'); // Usuarios
    remove_menu_page('tools.php'); // Herramientas
    remove_menu_page('options-general.php'); // Ajustes

    // WooCommerce y otros CPTs
    remove_menu_page('woocommerce');
    remove_menu_page('edit.php?post_type=product');
    remove_menu_page('edit.php?post_type=shop_order');
}, 999);

// 5) Render de la tabla (solo Nombre + Correo)
// Nota: incluye compradores invitados (guest) sacando datos desde pedidos.
function pp_render_clientes_visor_page() {
    if (!current_user_can(PP_CLIENT_VIEW_CAP)) {
        wp_die('No tienes permisos para ver esta página.');
    }

    echo '<div class="wrap">';
    echo '<h1>Clientes (solo lectura)</h1>';
    echo '<p>Se muestran únicamente <strong>Nombre</strong> y <strong>Correo</strong>. No hay exportación.</p>';

    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

    echo '<form method="get" style="margin:12px 0;">';
    echo '<input type="hidden" name="page" value="pp-clientes-visor" />';
    echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Buscar por nombre o correo..." style="min-width:340px;" />';
    echo '<button class="button button-primary" type="submit">Buscar</button>';
    echo '</form>';

    // Cache 10 min para no cargar mucho (ajusta si quieres)
    $cache_key = 'pp_clientes_visor_' . md5($search);
    $rows = get_transient($cache_key);

    if ($rows === false) {
        $rows = pp_get_unique_buyers_name_email($search);
        set_transient($cache_key, $rows, 10 * MINUTE_IN_SECONDS);
    }

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
    echo '</div>';
}

// Obtiene compradores únicos desde pedidos (incluye guests) → nombre + email
function pp_get_unique_buyers_name_email(string $search = ''): array {
    if (!function_exists('wc_get_orders')) return [];

    $args = [
        'limit'        => 500, // ajusta si tu tienda es grande (p.ej. 2000)
        'orderby'      => 'date',
        'order'        => 'DESC',
        'status'       => ['processing', 'completed', 'on-hold'],
        'return'       => 'objects',
    ];

    $orders = wc_get_orders($args);
    $unique = [];

    foreach ($orders as $order) {
        $email = trim((string) $order->get_billing_email());
        if ($email === '') continue;

        $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        if ($name === '') $name = '—';

        $key = strtolower($email);
        $unique[$key] = ['name' => $name, 'email' => $email];
    }

    $rows = array_values($unique);

    if ($search !== '') {
        $s = mb_strtolower($search);
        $rows = array_values(array_filter($rows, function ($r) use ($s) {
            return (mb_strtolower($r['name']) . ' ' . mb_strtolower($r['email'])) !== ''
                && (str_contains(mb_strtolower($r['name']), $s) || str_contains(mb_strtolower($r['email']), $s));
        }));
    }

    // Orden por nombre
    usort($rows, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

    return $rows;
}