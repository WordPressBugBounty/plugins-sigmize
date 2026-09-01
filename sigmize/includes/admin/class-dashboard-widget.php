<?php

/**
 * WordPress dashboard widget
 *
 * @package Sigmize
 */

namespace Sigmize\Admin;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renders the Sigmize overview card on wp-admin/index.php.
 *
 * The card is deliberately plain PHP + a small inline script: the plugin's
 * Tailwind build ships a preflight reset that would break the surrounding
 * dashboard, and the React bundle is far too heavy for a single card.
 */
class Dashboard_Widget
{

    /**
     * Widget ID, also used to scope every CSS rule.
     *
     * @var string
     */
    const WIDGET_ID = 'sigmize_overview';

    /**
     * Constructor
     *
     * @since 1.1.2
     */
    public function __construct()
    {
        add_action('wp_dashboard_setup', array($this, 'register_widget'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * Register the dashboard widget
     *
     * @since 1.1.2
     */
    public function register_widget()
    {
        if (! $this->user_can_view()) {
            return;
        }

        wp_add_dashboard_widget(
            self::WIDGET_ID,
            __('Sigmize - Experiment Summary', 'sigmize'),
            array($this, 'render_widget'),
            null,
            null,
            'normal',
            'high'
        );
    }

    /**
     * Whether the widget should be shown to the current user.
     *
     * @since 1.1.2
     *
     * @return bool
     */
    private function user_can_view()
    {
        /**
         * Filter whether the Sigmize dashboard widget is registered.
         *
         * @since 1.1.2
         *
         * @param bool $show Whether to show the widget.
         */
        return (bool) apply_filters('sigmize_show_dashboard_widget', current_user_can('manage_options'));
    }

    /**
     * Whether this site is connected to a Sigmize account.
     *
     * Reads the options directly rather than instantiating Auth_Manager: its
     * constructor hooks the OAuth callback, and a second instance would
     * process that callback twice. Both halves are required, because the
     * overview request needs the token and the workspace UUID.
     *
     * @since 1.1.2
     *
     * @return bool
     */
    private function is_connected()
    {
        return ! empty(get_option('sigmize_bearer_token'))
            && ! empty(get_option('sigmize_workspace_uuid'));
    }

    /**
     * Build a link into the Sigmize app with campaign tracking attached.
     *
     * @since 1.1.2
     *
     * @param string $path     App path, e.g. '/experiments'.
     * @param string $campaign UTM campaign for this placement.
     * @return string
     */
    private function app_url($path, $campaign)
    {
        return add_query_arg(
            array(
                'utm_source'   => 'wp-dashboard-widget',
                'utm_medium'   => 'wordpress',
                'utm_campaign' => $campaign,
            ),
            untrailingslashit(SIGMIZE_SAAS_BASE_URL) . $path
        );
    }

    /**
     * Enqueue the widget's styles and behaviour on the dashboard screen only.
     *
     * @since 1.1.2
     *
     * @param string $hook_suffix Current admin page.
     */
    public function enqueue_assets($hook_suffix)
    {
        if ('index.php' !== $hook_suffix || ! $this->user_can_view()) {
            return;
        }

        // Source-less handles: the widget is small enough that shipping two
        // extra HTTP requests for it would cost more than it saves.
        wp_register_style('sigmize-dashboard-widget', false, array(), SIGMIZE_VERSION);
        wp_enqueue_style('sigmize-dashboard-widget');
        wp_add_inline_style('sigmize-dashboard-widget', $this->get_styles());

        if (! $this->is_connected()) {
            return;
        }

        wp_register_script('sigmize-dashboard-widget', '', array(), SIGMIZE_VERSION, true);
        wp_enqueue_script('sigmize-dashboard-widget');

        // Strings are translated here rather than with wp.i18n: the script is
        // inline, so the JS translation extractor never sees it.
        wp_localize_script(
            'sigmize-dashboard-widget',
            'sigmizeWidget',
            array(
                'restUrl'  => esc_url_raw(rest_url('sigmize/v1/overview')),
                'nonce'    => wp_create_nonce('wp_rest'),
                'appUrl'   => untrailingslashit(SIGMIZE_SAAS_BASE_URL),
                'utm'      => 'utm_source=wp-dashboard-widget&utm_medium=wordpress&utm_campaign=running-experiment',
                'settings' => array(
                    'locale' => str_replace('_', '-', get_user_locale()),
                ),
                'i18n'     => array(
                    // Both forms are sent because the count is only known in the
                    // browser, so _n() cannot be resolved here.
                    /* translators: %s: number of visitors. */
                    'visitorsOne'  => __('%s visitor', 'sigmize'),
                    /* translators: %s: number of visitors. */
                    'visitorsMany' => __('%s visitors', 'sigmize'),
                    /* translators: %s: number of experiments currently running. */
                    'activeCount'  => __('%s active', 'sigmize'),
                    'noRunning'    => __('No experiments are running yet.', 'sigmize'),
                ),
            )
        );

        wp_add_inline_script('sigmize-dashboard-widget', $this->get_script());
    }

    /**
     * Render the widget body
     *
     * @since 1.1.2
     */
    public function render_widget()
    {
        if (! $this->is_connected()) {
            $this->render_disconnected();
            return;
        }

        $this->render_connected();
    }

    /**
     * Connect prompt shown before the site is linked to an account.
     *
     * @since 1.1.2
     */
    private function render_disconnected()
    {
        ?>
        <div class="sigmize-dw sigmize-dw--connect">
            <p class="sigmize-dw-heading"><?php esc_html_e('Start testing what actually converts', 'sigmize'); ?></p>
            <p class="sigmize-dw-lede">
                <?php esc_html_e('Connect your Sigmize account to run A/B tests, heatmaps and session recordings on this site, and see the results right here.', 'sigmize'); ?>
            </p>
            <p class="sigmize-dw-actions">
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=sigmize-dashboard')); ?>">
                    <?php esc_html_e('Connect Sigmize', 'sigmize'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Overview shell. Values are filled in by the inline script so the
     * dashboard never blocks on a call to the platform.
     *
     * @since 1.1.2
     */
    private function render_connected()
    {
        ?>
        <div class="sigmize-dw" id="sigmize-widget-root" data-state="loading">
            <div class="sigmize-dw-cards">
                <?php
                $this->render_card(
                    __('Experiments', 'sigmize'),
                    'experiments_total',
                    $this->app_url('/experiments', 'experiments'),
                    'experiments_active',
                    true
                );
                $this->render_card(__('Total Impressions', 'sigmize'), 'impressions', $this->app_url('/dashboard', 'impressions'));
                $this->render_card(__('Conversion Rate', 'sigmize'), 'conversion_rate', $this->app_url('/dashboard', 'conversion-rate'));
                $this->render_card(__('Heatmaps', 'sigmize'), 'heatmaps', $this->app_url('/heatmaps', 'heatmaps'), 'heatmaps_active');
                $this->render_card(__('Session Recordings', 'sigmize'), 'session_recordings', $this->app_url('/session-history', 'session-recordings'), 'session_recordings_active');
                ?>
            </div>

            <div class="sigmize-dw-section" data-field="running_section">
                <p class="sigmize-dw-section-title"><?php esc_html_e('Running Experiments', 'sigmize'); ?></p>
                <ul class="sigmize-dw-list" data-field="running"></ul>
            </div>

            <p class="sigmize-dw-error" data-field="error" hidden>
                <?php esc_html_e('Could not load your Sigmize overview.', 'sigmize'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=sigmize-dashboard')); ?>"><?php esc_html_e('Check your connection', 'sigmize'); ?></a>
            </p>

            <div class="sigmize-dw-footer">
                <a class="button button-primary" href="<?php echo esc_url($this->app_url('/dashboard', 'open-sigmize')); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Open Sigmize', 'sigmize'); ?>
                </a>
                <a class="sigmize-dw-link" href="<?php echo esc_url($this->app_url('/experiments', 'view-experiments')); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('View Experiments', 'sigmize'); ?>
                    <?php $this->render_external_icon(); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Render a single stat card.
     *
     * @since 1.1.2
     *
     * @param string      $label       Card label.
     * @param string      $field       Data field the script fills in.
     * @param string      $url         Where the card links to.
     * @param string|null $badge_field Optional field for the "n active" badge.
     * @param bool        $wide        Whether the card spans the full row.
     */
    private function render_card($label, $field, $url, $badge_field = null, $wide = false)
    {
        $class = 'sigmize-dw-card' . ($wide ? ' sigmize-dw-card--wide' : '');
        ?>
        <a class="<?php echo esc_attr($class); ?>" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer">
            <?php $this->render_external_icon(); ?>
            <span class="sigmize-dw-card-label"><?php echo esc_html($label); ?></span>
            <span class="sigmize-dw-card-figures">
                <span class="sigmize-dw-card-value" data-field="<?php echo esc_attr($field); ?>">&ndash;</span>
                <?php if ($badge_field) : ?>
                    <span class="sigmize-dw-badge" data-field="<?php echo esc_attr($badge_field); ?>" hidden></span>
                <?php endif; ?>
            </span>
        </a>
        <?php
    }

    /**
     * Render the external-link affordance used on every card and CTA.
     *
     * Uses the core dashicon so the mark matches the one WordPress puts on the
     * links in its own dashboard widgets. Dashicons are always enqueued in
     * wp-admin, so this costs nothing extra.
     *
     * @since 1.1.2
     */
    private function render_external_icon()
    {
        ?>
        <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'sigmize'); ?></span>
        <span aria-hidden="true" class="dashicons dashicons-external"></span>
        <?php
    }

    /**
     * Widget stylesheet.
     *
     * Scoped to the widget's own container so it cannot leak into the other
     * dashboard boxes, and written with logical properties because inline CSS
     * is not run through the RTL build.
     *
     * @since 1.1.2
     *
     * @return string
     */
    private function get_styles()
    {
        $id = '#' . self::WIDGET_ID;

        return "
        {$id} .sigmize-dw-heading { margin: 0 0 2px; font-size: 15px; font-weight: 600; color: #111827; line-height: 1.3; }
        {$id} .sigmize-dw-lede { margin: 0; font-size: 13px; color: #6b7280; line-height: 1.4; }
        {$id} .sigmize-dw-link { display: inline-flex; align-items: center; gap: 2px; font-size: 12px; font-weight: 500; text-decoration: none; white-space: nowrap; color: var(--wp-admin-theme-color, #2271b1); }
        {$id} .sigmize-dw-link:hover { color: var(--wp-admin-theme-color-darker-10, #135e96); }
        {$id} .sigmize-dw-cards { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
        {$id} .sigmize-dw-card { position: relative; display: flex; flex-direction: column; gap: 6px; padding: 14px; padding-inline-end: 38px; text-decoration: none; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 1px 2px rgba(15, 23, 42, .04); transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease; }
        {$id} .sigmize-dw-card--wide { grid-column: 1 / -1; flex-direction: row; align-items: center; justify-content: space-between; gap: 12px; }
        {$id} .sigmize-dw-card:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(15, 23, 42, .08); border-color: var(--wp-admin-theme-color, #2271b1); }
        {$id} .sigmize-dw-card:focus-visible { outline: 2px solid var(--wp-admin-theme-color, #2271b1); outline-offset: 2px; }
        {$id} .sigmize-dw-card-label { font-size: 12px; font-weight: 600; color: #6b7280; line-height: 1.3; }
        {$id} .sigmize-dw-card-figures { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
        {$id} .sigmize-dw-card-value { font-size: 22px; font-weight: 700; color: #111827; line-height: 1.15; letter-spacing: -0.01em; }
        {$id} .sigmize-dw-badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; line-height: 1.6; color: #0a5c36; background: #e6f4ea; border: 1px solid #b7e1c5; }
        {$id} .sigmize-dw-badge.is-idle { color: #6b7280; background: #f3f4f6; border-color: #e5e7eb; }
        {$id} .sigmize-dw-card .dashicons-external { position: absolute; top: 12px; inset-inline-end: 12px; width: 17px; height: 17px; font-size: 17px; color: var(--wp-admin-theme-color, #2271b1); }
        {$id} .sigmize-dw-link .dashicons-external { width: 17px; height: 17px; font-size: 17px; }
        {$id} .sigmize-dw-section { margin-bottom: 14px; }
        {$id} .sigmize-dw-section-title { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin: 0 0 8px; font-size: 13px; font-weight: 600; color: #111827; }
        {$id} .sigmize-dw-list { margin: 0; padding: 0; list-style: none; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
        {$id} .sigmize-dw-list li { border-block-end: 1px solid #e5e7eb; }
        {$id} .sigmize-dw-list li:last-child { border-block-end: none; }
        {$id} .sigmize-dw-list a { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 12px; font-size: 13px; text-decoration: none; color: #111827; }
        {$id} .sigmize-dw-list a:hover { background: #f9fafb; color: var(--wp-admin-theme-color, #2271b1); }
        {$id} .sigmize-dw-list-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        {$id} .sigmize-dw-list-meta { flex-shrink: 0; font-size: 12px; color: #6b7280; }
        {$id} .sigmize-dw-empty { padding: 12px; font-size: 13px; color: #6b7280; }
        {$id} .sigmize-dw-error { margin: 0 0 14px; font-size: 13px; color: #b32d2e; }
        {$id} .sigmize-dw-footer { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding-block-start: 14px; border-block-start: 1px solid #e5e7eb; }
        {$id} .sigmize-dw-actions { margin: 14px 0 0; }
        {$id} .sigmize-dw--connect .sigmize-dw-lede { margin-block-start: 6px; }
        {$id} [data-state=\"loading\"] .sigmize-dw-card-value { color: #9ca3af; }
        {$id} [data-state=\"error\"] .sigmize-dw-cards,
        {$id} [data-state=\"error\"] .sigmize-dw-section { display: none; }
        @media (max-width: 480px) {
            {$id} .sigmize-dw-cards { grid-template-columns: minmax(0, 1fr); }
        }
        ";
    }

    /**
     * Widget behaviour: fetch the overview and fill in the shell.
     *
     * @since 1.1.2
     *
     * @return string
     */
    private function get_script()
    {
        return <<<'JS'
(function () {
    var config = window.sigmizeWidget || {};
    var root = document.getElementById('sigmize-widget-root');

    if (!root || !config.restUrl) {
        return;
    }

    var locale = (config.settings && config.settings.locale) || undefined;
    var strings = config.i18n || {};

    function field(name) {
        return root.querySelector('[data-field="' + name + '"]');
    }

    function setText(name, value) {
        var node = field(name);
        if (node) {
            node.textContent = value;
        }
    }

    function number(value) {
        return Number(value || 0).toLocaleString(locale);
    }

    function setBadge(name, count) {
        var node = field(name);
        if (!node) {
            return;
        }

        var active = Number(count) || 0;

        node.textContent = (strings.activeCount || '%s').replace('%s', number(active));
        node.classList.toggle('is-idle', active === 0);
        node.hidden = false;
    }

    function renderRunning(experiments) {
        var list = field('running');
        if (!list) {
            return;
        }

        list.textContent = '';

        if (!experiments || !experiments.length) {
            var empty = document.createElement('li');
            empty.className = 'sigmize-dw-empty';
            empty.textContent = strings.noRunning || '';
            list.appendChild(empty);
            return;
        }

        experiments.forEach(function (experiment) {
            var item = document.createElement('li');
            var link = document.createElement('a');

            link.href = config.appUrl + '/experiments/' + encodeURIComponent(experiment.uuid) + '/reports?' + (config.utm || '');
            link.target = '_blank';
            link.rel = 'noopener noreferrer';

            var name = document.createElement('span');
            name.className = 'sigmize-dw-list-name';
            name.textContent = experiment.name;

            var visitors = Number(experiment.visitors) || 0;
            var meta = document.createElement('span');
            meta.className = 'sigmize-dw-list-meta';
            meta.textContent = (visitors === 1 ? strings.visitorsOne : strings.visitorsMany)
                .replace('%s', number(visitors));

            link.appendChild(name);
            link.appendChild(meta);
            item.appendChild(link);
            list.appendChild(item);
        });
    }

    function render(overview) {
        setText('experiments_total', number(overview.experiments.total));
        setBadge('experiments_active', overview.experiments.active);

        setText('impressions', number(overview.metrics.impressions));
        setText('conversion_rate', (Number(overview.metrics.conversion_rate) || 0).toFixed(1) + '%');

        setText('heatmaps', number(overview.heatmaps.total));
        setBadge('heatmaps_active', overview.heatmaps.active);

        setText('session_recordings', number(overview.session_recordings.total));
        setBadge('session_recordings_active', overview.session_recordings.active);

        renderRunning(overview.running);

        root.dataset.state = 'ready';
    }

    function fail() {
        var error = field('error');
        if (error) {
            error.hidden = false;
        }
        root.dataset.state = 'error';
    }

    fetch(config.restUrl, {
        credentials: 'same-origin',
        headers: {
            'X-WP-Nonce': config.nonce
        }
    })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('request_failed');
            }
            return response.json();
        })
        .then(function (payload) {
            if (!payload || !payload.success || !payload.overview) {
                throw new Error('unexpected_payload');
            }
            render(payload.overview);
        })
        .catch(fail);
}());
JS;
    }
}
