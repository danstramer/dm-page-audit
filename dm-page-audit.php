<?php
/*
Plugin Name: DM Page Audit
Description: Front-end page inventory and audit tool with notes, stars, filters, and CSV export.
Version: 1.0.0
Author: Dan Stramer
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DM_PAGE_AUDIT_PATH', plugin_dir_path( __FILE__ ) );
define( 'DM_PAGE_AUDIT_URL', plugin_dir_url( __FILE__ ) );
define( 'DM_PAGE_AUDIT_PASSWORD', 'auditlist-metro' ); // 🔐 Your password

/**
 * Start PHP session (for password gate)
 */
function dm_page_audit_start_session() {
    if ( session_status() === PHP_SESSION_NONE ) {
        session_start();
    }
}
add_action( 'init', 'dm_page_audit_start_session', 1 );

/**
 * Enqueue scripts & styles
 */
function dm_page_audit_enqueue_assets() {
    // DataTables core + Buttons + HTML5 export
    wp_enqueue_style(
        'datatables-css',
        'https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css',
        [],
        '1.13.6'
    );

    wp_enqueue_style(
        'datatables-buttons-css',
        'https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css',
        [],
        '2.4.1'
    );

    wp_enqueue_script(
        'datatables-js',
        'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js',
        ['jquery'],
        '1.13.6',
        true
    );

    wp_enqueue_script(
        'datatables-buttons-js',
        'https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js',
        ['datatables-js'],
        '2.4.1',
        true
    );

    wp_enqueue_script(
        'datatables-buttons-colvis-js',
        'https://cdn.datatables.net/buttons/2.4.1/js/buttons.colVis.min.js',
        ['datatables-buttons-js'],
        '2.4.1',
        true
    );

    wp_enqueue_script(
        'datatables-buttons-html5-js',
        'https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js',
        ['datatables-buttons-js'],
        '2.4.1',
        true
    );

    // Plugin CSS
    wp_enqueue_style(
        'dm-page-audit-css',
        DM_PAGE_AUDIT_URL . 'assets/css/dm-page-audit.css',
        [],
        '1.0.0'
    );

    // Plugin JS (DataTables init + AJAX for notes/stars/traffic)
    wp_enqueue_script(
        'dm-page-audit-js',
        DM_PAGE_AUDIT_URL . 'assets/js/dm-page-audit.js',
        ['jquery', 'datatables-js'],
        '1.0.0',
        true
    );

    wp_localize_script(
        'dm-page-audit-js',
        'dmPageAudit',
        [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'dm_page_audit' ),
        ]
    );
}
add_action( 'wp_enqueue_scripts', 'dm_page_audit_enqueue_assets' );

/**
 * Shortcode: [dm_page_audit]
 * Front-end page audit table with password protection.
 */
function dm_page_audit_shortcode( $atts ) {

    // PASSWORD GATE
    $authorized = isset( $_SESSION['dm_page_audit_unlocked'] ) && $_SESSION['dm_page_audit_unlocked'];

    $error = '';

    if ( isset( $_POST['dm_page_audit_password'] ) ) {
        if ( ! isset( $_POST['dm_page_audit_password_nonce'] ) || ! wp_verify_nonce( $_POST['dm_page_audit_password_nonce'], 'dm_page_audit_password' ) ) {
            $error = 'Security check failed.';
        } else {
            $pass = sanitize_text_field( $_POST['dm_page_audit_password'] );
            if ( $pass === DM_PAGE_AUDIT_PASSWORD ) {
                $_SESSION['dm_page_audit_unlocked'] = true;
                $authorized = true;
            } else {
                $error = 'Incorrect password.';
            }
        }
    }

    ob_start();

    if ( ! $authorized ) : ?>
        <div class="dm-audit-password-wrap">
            <h2>Page Audit – Restricted</h2>
            <p>Please enter the audit password to view this page.</p>

            <?php if ( $error ) : ?>
                <p class="dm-audit-error"><?php echo esc_html( $error ); ?></p>
            <?php endif; ?>

            <form method="post">
                <p>
                    <label for="dm_page_audit_password">Password:</label><br>
                    <input type="password" name="dm_page_audit_password" id="dm_page_audit_password" />
                </p>
                <?php wp_nonce_field( 'dm_page_audit_password', 'dm_page_audit_password_nonce' ); ?>
                <p>
                    <button type="submit">Enter</button>
                </p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    endif;

    // Authorized – build page inventory

    $pages = get_pages([
        'sort_column' => 'post_title',
        'sort_order'  => 'ASC',
        'post_status' => 'publish',
    ]);

    if ( ! $pages ) {
        echo '<p>No published pages found.</p>';
        return ob_get_clean();
    }

    // Build lists for Parent & Template filters
    $parent_titles  = [];
    $template_names = [];

    foreach ( $pages as $page ) {
        if ( $page->post_parent ) {
            $parent_titles[] = get_the_title( $page->post_parent );
        }

        $template = get_page_template_slug( $page->ID );
        if ( ! $template ) {
            $template = 'Default';
        }
        $template_names[] = $template;
    }

    $parent_titles  = array_unique( array_filter( $parent_titles ) );
    sort( $parent_titles );

    $template_names = array_unique( array_filter( $template_names ) );
    sort( $template_names );

    ?>

    <div class="dm-audit-controls">

        <div class="dm-audit-filters">
            <h3>Filters</h3>
            <div class="dm-audit-filters-row">
                <label>
                    Star:
                    <select id="dm-filter-star">
                        <option value="all">All pages</option>
                        <option value="starred">Starred only</option>
                        <option value="unstarred">Unstarred only</option>
                    </select>
                </label>

                <label>
                    Builder:
                    <select id="dm-filter-builder">
                        <option value="all">All</option>
                        <option value="Classic/Gutenberg">Classic / Gutenberg</option>
                        <option value="GenerateBlocks">GenerateBlocks</option>
                        <option value="Elementor">Elementor</option>
                    </select>
                </label>

                <label>
                    Menu:
                    <select id="dm-filter-menu">
                        <option value="all">All</option>
                        <option value="Yes">In primary menu</option>
                        <option value="No">Not in menu</option>
                    </select>
                </label>

                <label>
                    SEO:
                    <select id="dm-filter-seo">
                        <option value="all">All</option>
                        <option value="missing_title">Missing SEO Title</option>
                        <option value="missing_desc">Missing Meta Description</option>
                        <option value="missing_both">Missing Both</option>
                    </select>
                </label>

                <label>
                    Word Count:
                    <select id="dm-filter-words">
                        <option value="all">All</option>
                        <option value="thin">&lt; 200</option>
                        <option value="short">200–800</option>
                        <option value="medium">800–1500</option>
                        <option value="long">&gt; 1500</option>
                    </select>
                </label>

                <label>
                    Parent Page:
                    <select id="dm-filter-parent">
                        <option value="all">All</option>
                        <?php foreach ( $parent_titles as $pt ) : ?>
                            <option value="<?php echo esc_attr( $pt ); ?>"><?php echo esc_html( $pt ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Template:
                    <select id="dm-filter-template">
                        <option value="all">All</option>
                        <?php foreach ( $template_names as $tmpl ) : ?>
                            <option value="<?php echo esc_attr( $tmpl ); ?>"><?php echo esc_html( $tmpl ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </div>

        <div class="dm-audit-groups">
            <h3>Column Groups</h3>
            <label><input type="checkbox" class="dm-group-toggle" data-group="content" checked> Content</label>
            <label><input type="checkbox" class="dm-group-toggle" data-group="seo" checked> SEO</label>
            <label><input type="checkbox" class="dm-group-toggle" data-group="structure" checked> Structure</label>
            <label><input type="checkbox" class="dm-group-toggle" data-group="dates" checked> Dates</label>
            <label><input type="checkbox" class="dm-group-toggle" data-group="audit" checked> Audit</label>
        </div>

    </div>

    <table class="dm-audit-table">
        <thead>
            <tr>
                <th>⭐</th>
                <th>Title</th>
                <th>URL</th>
                <th>Slug</th>
                <th>Excerpt</th>
                <th>Word Count</th>
                <th>Published</th>
                <th>Updated</th>
                <th>Author</th>
                <th>Parent</th>
                <th>Menu</th>
                <th>Template</th>
                <th>Builder</th>
                <th>Featured Img</th>
                <th>Redirect</th>
                <th>SEO Title</th>
                <th>Meta Description</th>
                <th>Internal Links</th>
                <th>Traffic</th>
                <th>Notes</th>
            </tr>
        </thead>

        <tbody>
        <?php
        // Primary menu for "Menu presence"
        $menu_items = wp_get_nav_menu_items( 'primary' );

        foreach ( $pages as $page ) :

            $keep      = get_post_meta( $page->ID, 'audit_keep', true );
            $note      = get_post_meta( $page->ID, 'audit_note', true );
            $traffic   = get_post_meta( $page->ID, 'audit_traffic', true );

            $slug      = $page->post_name;
            $wordcount = str_word_count( wp_strip_all_tags( $page->post_content ) );
            $published = get_the_date( '', $page->ID );
            $updated   = get_the_modified_date( '', $page->ID );
            $author    = get_the_author_meta( 'display_name', $page->post_author );
            $parent    = $page->post_parent ? get_the_title( $page->post_parent ) : '—';
            $featured  = has_post_thumbnail( $page->ID ) ? 'Yes' : 'No';

            // Template
            $template = get_page_template_slug( $page->ID );
            if ( ! $template ) {
                $template = 'Default';
            }

            // Builder detection
            $builder = 'Classic/Gutenberg';
            if ( has_block( 'generateblocks/container', $page->post_content ) ) {
                $builder = 'GenerateBlocks';
            }
            if ( strpos( $page->post_content, 'elementor' ) !== false ) {
                $builder = 'Elementor';
            }

            // Menu presence
            $in_menu = 'No';
            if ( $menu_items ) {
                foreach ( $menu_items as $item ) {
                    if ( intval( $item->object_id ) === $page->ID ) {
                        $in_menu = 'Yes';
                        break;
                    }
                }
            }

            // Redirect (Yoast / Rank Math)
            $redirect = get_post_meta( $page->ID, '_yoast_wpseo_redirect', true );
            if ( ! $redirect ) {
                $redirect = get_post_meta( $page->ID, 'rank_math_redirections', true );
            }
            if ( ! $redirect ) {
                $redirect = '—';
            }

            // SEO Title & Meta
            $seo_title = get_post_meta( $page->ID, '_yoast_wpseo_title', true );
            if ( ! $seo_title ) {
                $seo_title = get_post_meta( $page->ID, 'rank_math_title', true );
            }
            if ( ! $seo_title ) {
                $seo_title = '';
            }

            $meta_desc = get_post_meta( $page->ID, '_yoast_wpseo_metadesc', true );
            if ( ! $meta_desc ) {
                $meta_desc = get_post_meta( $page->ID, 'rank_math_description', true );
            }
            if ( ! $meta_desc ) {
                $meta_desc = '';
            }

            // SEO status for filtering
            if ( $seo_title && $meta_desc ) {
                $seo_status = 'ok';
            } elseif ( ! $seo_title && ! $meta_desc ) {
                $seo_status = 'missing_both';
            } elseif ( ! $seo_title ) {
                $seo_status = 'missing_title';
            } else {
                $seo_status = 'missing_desc';
            }

            // Internal links (rough count)
            $domain          = parse_url( home_url(), PHP_URL_HOST );
            $internal_links  = substr_count( $page->post_content, 'href="https://' . $domain );

            ?>
            <tr
                data-page="<?php echo esc_attr( $page->ID ); ?>"
                data-star="<?php echo $keep ? 1 : 0; ?>"
                data-builder="<?php echo esc_attr( $builder ); ?>"
                data-menu="<?php echo esc_attr( $in_menu ); ?>"
                data-seo="<?php echo esc_attr( $seo_status ); ?>"
                data-parent="<?php echo esc_attr( $parent ); ?>"
                data-template="<?php echo esc_attr( $template ); ?>"
                data-words="<?php echo esc_attr( $wordcount ); ?>"
            >

                <td>
                    <span class="dm-star <?php echo $keep ? 'active' : ''; ?>">★</span>
                </td>

                <td><?php echo esc_html( $page->post_title ); ?></td>

                <td>
                    <a href="<?php echo esc_url( get_permalink( $page->ID ) ); ?>" target="_blank" rel="noopener">
                        Open
                    </a>
                </td>

                <td><?php echo esc_html( $slug ); ?></td>

                <td><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $page->post_content ), 20 ) ); ?></td>

                <td><?php echo esc_html( $wordcount ); ?></td>

                <td><?php echo esc_html( $published ); ?></td>

                <td><?php echo esc_html( $updated ); ?></td>

                <td><?php echo esc_html( $author ); ?></td>

                <td><?php echo esc_html( $parent ); ?></td>

                <td><?php echo esc_html( $in_menu ); ?></td>

                <td><?php echo esc_html( $template ); ?></td>

                <td><?php echo esc_html( $builder ); ?></td>

                <td><?php echo esc_html( $featured ); ?></td>

                <td><?php echo esc_html( $redirect ); ?></td>

                <td><?php echo esc_html( $seo_title ? $seo_title : '—' ); ?></td>

                <td><?php echo esc_html( $meta_desc ? $meta_desc : '—' ); ?></td>

                <td><?php echo esc_html( $internal_links ); ?></td>

                <td>
                    <span class="dm-traffic" contenteditable="true">
                        <?php echo esc_html( $traffic ); ?>
                    </span>
                </td>

                <td>
                    <textarea class="dm-note"><?php echo esc_textarea( $note ); ?></textarea>
                </td>

            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php
    return ob_get_clean();
}
add_shortcode( 'dm_page_audit', 'dm_page_audit_shortcode' );

/**
 * AJAX: Save note
 */
function dm_page_audit_save_note() {
    check_ajax_referer( 'dm_page_audit', 'nonce' );

    $page = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 0;
    $note = isset( $_POST['note'] ) ? sanitize_textarea_field( $_POST['note'] ) : '';

    if ( $page ) {
        update_post_meta( $page, 'audit_note', $note );
        wp_send_json_success();
    }

    wp_send_json_error();
}
add_action( 'wp_ajax_dm_save_audit_note', 'dm_page_audit_save_note' );
add_action( 'wp_ajax_nopriv_dm_save_audit_note', 'dm_page_audit_save_note' );

/**
 * AJAX: Toggle star
 */
function dm_page_audit_toggle_star() {
    check_ajax_referer( 'dm_page_audit', 'nonce' );

    $page = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 0;
    $keep = isset( $_POST['keep'] ) ? intval( $_POST['keep'] ) : 0;

    if ( $page ) {
        update_post_meta( $page, 'audit_keep', $keep );
        wp_send_json_success();
    }

    wp_send_json_error();
}
add_action( 'wp_ajax_dm_toggle_audit_star', 'dm_page_audit_toggle_star' );
add_action( 'wp_ajax_nopriv_dm_toggle_audit_star', 'dm_page_audit_toggle_star' );

/**
 * AJAX: Save traffic indicator
 */
function dm_page_audit_save_traffic() {
    check_ajax_referer( 'dm_page_audit', 'nonce' );

    $page    = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 0;
    $traffic = isset( $_POST['traffic'] ) ? sanitize_text_field( $_POST['traffic'] ) : '';

    if ( $page ) {
        update_post_meta( $page, 'audit_traffic', $traffic );
        wp_send_json_success();
    }

    wp_send_json_error();
}
add_action( 'wp_ajax_dm_save_audit_traffic', 'dm_page_audit_save_traffic' );
add_action( 'wp_ajax_nopriv_dm_save_traffic', 'dm_page_audit_save_traffic' ); // small typo-safe
