<?php
add_action('admin_menu', function () {
    add_options_page(
        __('KSeF Integration', 'ksef-integration'),
        __('KSeF Integration', 'ksef-integration'),
        'manage_options',
        'ksef-settings',
        'ksef_render_settings_page'
    );
});

add_action('admin_init', function () {
    if (!isset($_POST['ksef_manual_sync'])) return;
    if (!wp_verify_nonce($_POST['ksef_nonce'] ?? '', 'ksef_save')) return;
    ksef_cron_sync();
    add_action('admin_notices', function () {
        echo '<div class="notice notice-success"><p>' . __('Synchronizacja zakończona. Sprawdź logi.', 'ksef-integration') . '</p></div>';
    });
});

function ksef_render_settings_page() {
    if (isset($_POST['ksef_save_settings']) && wp_verify_nonce($_POST['ksef_nonce'] ?? '', 'ksef_save')) {
        update_option('ksef_environment', sanitize_text_field($_POST['ksef_environment'] ?? 'test'));
        update_option('ksef_nip', sanitize_text_field($_POST['ksef_nip'] ?? ''));
        update_option('ksef_sync_start_date', sanitize_text_field($_POST['ksef_sync_start_date'] ?? ''));
        $types = $_POST['ksef_sync_types'] ?? [];
        if (!is_array($types)) $types = [];
        update_option('ksef_sync_types', $types);
        if (!empty($_POST['ksef_token'])) {
            $token_mgr = new KSeF_Token_Manager();
            $token_mgr->save_token($_POST['ksef_token']);
        }
        echo '<div class="notice notice-success"><p>' . __('Ustawienia zapisane.', 'ksef-integration') . '</p></div>';
    }

    if (isset($_POST['ksef_test_connection']) && wp_verify_nonce($_POST['ksef_nonce'] ?? '', 'ksef_save')) {
        try {
            $client = new KSeF_Client();
            if (!$client->test_connectivity()) {
                throw new Exception('Brak odpowiedzi API KSeF');
            }
            $nip        = get_option('ksef_nip');
            $token_mgr  = new KSeF_Token_Manager();
            $ksef_token = $token_mgr->get_stored_token();
            if (empty($nip) || empty($ksef_token)) throw new Exception('Skonfiguruj NIP i token');

            $auth   = new KSeF_Auth();
            $tokens = $auth->authenticate($nip, $ksef_token);
            $token_mgr->save_tokens($tokens['access_token'], $tokens['refresh_token'] ?? '');
            echo '<div class="notice notice-success"><p>' . __('Połączenie z KSeF nawiązane!', 'ksef-integration') . '</p></div>';
        } catch (Exception $e) {
            echo '<div class="notice notice-error"><p>' . sprintf(__('Błąd: %s', 'ksef-integration'), esc_html($e->getMessage())) . '</p></div>';
        }
    }

    $current_env   = get_option('ksef_environment', 'test');
    $current_nip   = get_option('ksef_nip', '');
    $current_start = get_option('ksef_sync_start_date', date('Y-m-01'));
    $current_types = get_option('ksef_sync_types', ['Subject1', 'Subject2']);
    ?>
    <div class="wrap">
        <h1><?php _e('Ustawienia KSeF Integration', 'ksef-integration'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('ksef_save', 'ksef_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="ksef_environment">Środowisko</label></th>
                    <td>
                        <select name="ksef_environment" id="ksef_environment">
                            <option value="test" <?php selected($current_env, 'test'); ?>>Testowe</option>
                            <option value="production" <?php selected($current_env, 'production'); ?>>Produkcyjne</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="ksef_nip">NIP</label></th>
                    <td><input type="text" name="ksef_nip" value="<?php echo esc_attr($current_nip); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th><label for="ksef_token">Token KSeF</label></th>
                    <td>
                        <input type="password" name="ksef_token" class="regular-text" placeholder="Pozostaw puste, aby nie zmieniać" />
                        <p class="description">Token z Aplikacji Podatnika KSeF 2.0.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="ksef_sync_start_date">Data początkowa synchronizacji</label></th>
                    <td>
                        <input type="date" name="ksef_sync_start_date" value="<?php echo esc_attr($current_start); ?>" />
                        <p class="description">Faktury od tej daty będą pobierane. Pozostaw puste, aby użyć ostatniej synchronizacji.</p>
                    </td>
                </tr>
                <tr>
                    <th>Typy faktur do pobrania</th>
                    <td>
                        <label>
                            <input type="checkbox" name="ksef_sync_types[]" value="Subject1" <?php checked(in_array('Subject1', $current_types)); ?>>
                            <?php _e('Sprzedażowe (Subject1)', 'ksef-integration'); ?>
                        </label><br>
                        <label>
                            <input type="checkbox" name="ksef_sync_types[]" value="Subject2" <?php checked(in_array('Subject2', $current_types)); ?>>
                            <?php _e('Kosztowe (Subject2)', 'ksef-integration'); ?>
                        </label>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" name="ksef_save_settings" class="button button-primary">Zapisz ustawienia</button>
                <button type="submit" name="ksef_test_connection" class="button button-secondary">Testuj połączenie</button>
            </p>
        </form>

        <hr>
        <h2>Ręczna synchronizacja</h2>
        <form method="post">
            <?php wp_nonce_field('ksef_save', 'ksef_nonce'); ?>
            <button type="submit" name="ksef_manual_sync" class="button button-primary">Synchronizuj teraz</button>
        </form>

        <hr>
        <h2>Stan synchronizacji</h2>
        <p>Ostatnia synchronizacja: <?php echo esc_html(get_option('ksef_last_sync', 'Nigdy')); ?></p>
    </div>
    <?php
}