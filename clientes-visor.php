<?php
/**
 * Plugin Name: Clientes - Visor (Solo Nombre y Correo)
 * Description: Crea un rol que solo puede ver una lista de clientes (nombre + correo) sin exportar ni acceder a WooCommerce.
 * Version: 1.0
 */

add_action('admin_notices', function () {
    if (current_user_can('administrator')) {
        echo '<div class="notice notice-success"><p><strong>MU-plugin Clientes-Visor cargado ✅</strong></p></div>';
    }
});

if (!defined('ABSPATH')) exit;

const PP_CLIENT_VIEW_CAP = 'pp_view_customers_list';


// 1) Crear rol (solo una vez)
add_action('init', function () {

    if (!get_role('pp_client_viewer')) {
        add_role('pp_client_viewer', 'Visor de Clientes', [
            'read' => true,
            PP_CLIENT_VIEW_CAP => true,
        ]);
    }

}, 99);

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

    $search   = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $paged    = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = isset($_GET['per_page']) ? max(10, min(200, intval($_GET['per_page']))) : 50; // 10..200

    $result = pp_get_customers_name_email($search, $paged, $per_page);
    $rows   = $result['rows'];
    $total  = (int) $result['total'];

    $total_pages = (int) ceil($total / $per_page);

    echo '<div class="wrap">';
    echo '<h1>Clientes (solo lectura)</h1>';
    echo '<p>Se muestran únicamente <strong>Nombre</strong> y <strong>Correo</strong>. Sin exportación.</p>';

    // Filtros
    echo '<form method="get" style="margin:12px 0; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">';
    echo '<input type="hidden" name="page" value="pp-clientes-visor" />';

    echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Buscar por nombre o correo..." style="min-width:340px;" />';

    echo '<label>Por página: ';
    echo '<select name="per_page">';
    foreach ([25, 50, 100, 200] as $opt) {
        $sel = selected($per_page, $opt, false);
        echo "<option value='{$opt}' {$sel}>{$opt}</option>";
    }
    echo '</select></label>';

    echo '<button class="button button-primary" type="submit">Buscar</button>';
    echo '</form>';

    // Resumen
    echo '<p style="margin:10px 0;"><strong>Total:</strong> ' . number_format_i18n($total) . ' clientes</p>';

    // Tabla
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
        $args = [
            's'        => $search,
            'per_page' => $per_page,
        ];

        echo '<div style="margin-top:14px;">';

        $prev = max(1, $paged - 1);
        $next = min($total_pages, $paged + 1);

        $prev_url = add_query_arg(array_merge($args, ['paged' => $prev]), $base_url);
        $next_url = add_query_arg(array_merge($args, ['paged' => $next]), $base_url);

        echo '<span class="tablenav-pages">';
        echo '<span style="margin-right:10px;">Página <strong>' . $paged . '</strong> de <strong>' . $total_pages . '</strong></span>';

        if ($paged > 1) {
            echo '<a class="button" href="' . esc_url($prev_url) . '">‹ Anterior</a> ';
        } else {
            echo '<span class="button disabled">‹ Anterior</span> ';
        }

        if ($paged < $total_pages) {
            echo '<a class="button" href="' . esc_url($next_url) . '">Siguiente ›</a>';
        } else {
            echo '<span class="button disabled">Siguiente ›</span>';
        }

        echo '</span>';
        echo '</div>';
    }

    echo '</div>';
}

/**
 * Devuelve clientes registrados (usuarios rol customer) con paginación + búsqueda.
 * Retorna:
 *  - rows: [ ['name'=>..., 'email'=>...], ... ]
 *  - total: int
 */
function pp_get_customers_name_email(string $search = '', int $paged = 1, int $per_page = 50): array {
    $offset = ($paged - 1) * $per_page;

    $args = [
        'role__in'   => ['customer'],
        'number'     => $per_page,
        'offset'     => $offset,
        'orderby'    => 'display_name',
        'order'      => 'ASC',
        'fields'     => ['ID', 'display_name', 'user_email', 'first_name', 'last_name'],
        'count_total'=> true,
    ];

    if ($search !== '') {
        // búsqueda por email/login/display_name
        $args['search'] = '*' . $search . '*';
        $args['search_columns'] = ['user_email', 'user_login', 'display_name'];
    }

    $uq = new WP_User_Query($args);

    $rows = [];
    foreach ($uq->get_results() as $u) {
        $first = get_user_meta($u->ID, 'first_name', true);
        $last  = get_user_meta($u->ID, 'last_name', true);

        $name = trim($first . ' ' . $last);
        if ($name === '') $name = $u->display_name ?: '—';

        $rows[] = [
            'name'  => $name,
            'email' => $u->user_email,
        ];
    }

    return [
        'rows'  => $rows,
        'total' => (int) $uq->get_total(),
    ];
}

add_filter('login_redirect', function ($redirect_to, $request, $user) {
    if ($user instanceof WP_User && in_array('pp_client_viewer', (array) $user->roles, true)) {
        return admin_url('admin.php?page=pp-clientes-visor');
    }
    return $redirect_to;
}, 10, 3);

add_filter('woocommerce_prevent_admin_access', function ($prevent) {
    $user = wp_get_current_user();
    if (in_array('pp_client_viewer', (array) $user->roles, true)) {
        return false; // permitir wp-admin para este rol
    }
    return $prevent;
}, 10, 1);