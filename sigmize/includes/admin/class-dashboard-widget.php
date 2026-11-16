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
                'restUrl'    => esc_url_raw(rest_url('sigmize/v1/overview')),
                'dismissUrl' => esc_url_raw(rest_url('sigmize/v1/promotion/dismiss')),
                'nonce'      => wp_create_nonce('wp_rest'),
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
                    'saleBadge'    => __('Black Friday', 'sigmize'),
                    'lastChance'   => __('Last chance', 'sigmize'),
                    /* translators: %s: date the sale ends, e.g. Dec 6. */
                    'endsOn'       => __('Ends %s', 'sigmize'),
                    'grabDeal'     => __('Grab the Deal', 'sigmize'),
                    'closeOffer'   => __('Close this offer', 'sigmize'),
                    'usageTitle'   => __('Visitor limit', 'sigmize'),
                    /* translators: 1: visitors used, e.g. 42K. 2: visitor limit, e.g. 50K. */
                    'usageText'    => __('%1$s of %2$s visitors used this month', 'sigmize'),
                    'upgradePlan'  => __('Upgrade plan', 'sigmize'),
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
            <?php $this->render_connect_sale_line(); ?>
        </div>
        <?php
    }

    /**
     * One sale line under the Connect button while Black Friday is on.
     *
     * Uses the dates built into the plugin, so a site with no account makes
     * no external request. Hidden for sites last connected to a Lifetime or
     * Business plan.
     *
     * @since 1.2.1
     */
    private function render_connect_sale_line()
    {
        $offer = Promotion::connect_offer();

        if (null === $offer) {
            return;
        }

        $message = sprintf(
            /* translators: 1: discount percentage. 2: date the sale ends, e.g. December 6. */
            __('Black Friday: Sigmize Lifetime is %1$d%% off until %2$s.', 'sigmize'),
            (int) $offer['percent'],
            wp_date(_x('F j', 'Black Friday end date format', 'sigmize'), Promotion::ENDS_AT)
        );
        ?>
        <p class="sigmize-dw-sale-line">
            <span class="sigmize-dw-dot" aria-hidden="true"></span>
            <span><?php echo esc_html($message); ?></span>
        </p>
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
            <div class="sigmize-dw-promo" data-field="promotion" hidden></div>
            <div class="sigmize-dw-usage" data-field="usage" hidden></div>
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
        {$id} .sigmize-dw-promo { position: relative; display: flex; flex-wrap: wrap; align-items: center; gap: 10px 14px; margin-bottom: 14px; padding: 12px 14px; padding-inline-end: 36px; border-radius: 10px; color: #fff; background: radial-gradient(90% 160% at 100% 0%, rgba(255, 91, 4, .5) 0%, transparent 55%), radial-gradient(80% 160% at 0% 100%, rgba(0, 140, 130, .6) 0%, transparent 60%), #050807; }
        {$id} .sigmize-dw-promo[hidden], {$id} .sigmize-dw-usage[hidden] { display: none; }
        {$id} .sigmize-dw-promo-text { display: flex; flex-direction: column; gap: 2px; flex: 1 1 200px; min-width: 0; }
        {$id} .sigmize-dw-promo-kicker { display: inline-flex; align-items: center; gap: 6px; font-size: 10px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: #fed7aa; }
        {$id} .sigmize-dw-promo-line { font-size: 14px; font-weight: 600; line-height: 1.35; }
        {$id} .sigmize-dw-promo-ends { font-size: 12px; color: rgba(255, 255, 255, .7); }
        {$id} .sigmize-dw-promo-cta { display: inline-flex; align-items: center; padding: 7px 14px; border-radius: 6px; font-size: 13px; font-weight: 700; text-decoration: none; white-space: nowrap; color: #04201e; background: #7FD3C8; }
        {$id} .sigmize-dw-promo-cta:hover, {$id} .sigmize-dw-promo-cta:focus { color: #04201e; background: #99e0d6; }
        {$id} .sigmize-dw-promo-cta:focus-visible, {$id} .sigmize-dw-promo-close:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
        {$id} .sigmize-dw-promo-close { position: absolute; top: 6px; inset-inline-end: 6px; width: 26px; height: 26px; padding: 0; border: 0; border-radius: 4px; cursor: pointer; font-size: 18px; line-height: 26px; color: rgba(255, 255, 255, .7); background: transparent; }
        {$id} .sigmize-dw-promo-close:hover { color: #fff; background: rgba(255, 255, 255, .1); }
        {$id} .sigmize-dw-dot { display: inline-block; flex-shrink: 0; width: 6px; height: 6px; border-radius: 50%; background: #FF5B04; }
        {$id} .sigmize-dw-usage { margin-bottom: 14px; padding: 12px 14px; border: 1px solid #fcd34d; border-radius: 8px; background: #fffbeb; }
        {$id} .sigmize-dw-usage.is-full { border-color: #fca5a5; background: #fef2f2; }
        {$id} .sigmize-dw-usage-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; font-size: 13px; font-weight: 600; color: #111827; }
        {$id} .sigmize-dw-meter { height: 6px; border-radius: 999px; overflow: hidden; background: rgba(17, 24, 39, .08); }
        {$id} .sigmize-dw-meter span { display: block; height: 100%; border-radius: inherit; background: #d97706; }
        {$id} .sigmize-dw-usage.is-full .sigmize-dw-meter span { background: #dc2626; }
        {$id} .sigmize-dw-usage-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin-top: 8px; font-size: 12px; color: #4b5563; }
        {$id} .sigmize-dw-sale-line { display: flex; align-items: center; gap: 8px; margin: 12px 0 0; font-size: 13px; color: #374151; }
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

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text) {
            node.textContent = text;
        }
        return node;
    }

    // Only ever links to the app or the pricing page, never a URL from the response.
    function safeUrl(url) {
        return typeof url === 'string' && url.indexOf(config.appUrl + '/') === 0 ? url : config.appUrl + '/organization/plans';
    }

    function dismissPromotion(box) {
        box.hidden = true;

        if (!config.dismissUrl) {
            return;
        }

        fetch(config.dismissUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce': config.nonce
            }
        }).catch(function () {});
    }

    function renderPromotion(promotion) {
        var box = field('promotion');
        if (!box || !promotion || !promotion.line) {
            return;
        }

        box.textContent = '';

        var text = el('div', 'sigmize-dw-promo-text');
        var kicker = el('span', 'sigmize-dw-promo-kicker');
        var dot = el('span', 'sigmize-dw-dot');
        dot.setAttribute('aria-hidden', 'true');
        kicker.appendChild(dot);
        kicker.appendChild(document.createTextNode(promotion.lastChance ? strings.lastChance : strings.saleBadge));
        text.appendChild(kicker);
        text.appendChild(el('span', 'sigmize-dw-promo-line', promotion.line));

        var endsAt = new Date(Number(promotion.endsAt) || 0);
        if (!isNaN(endsAt.getTime())) {
            var ends = endsAt.toLocaleDateString(locale, { month: 'short', day: 'numeric' });
            text.appendChild(el('span', 'sigmize-dw-promo-ends', (strings.endsOn || '%s').replace('%s', ends)));
        }

        var cta = el('a', 'sigmize-dw-promo-cta', strings.grabDeal);
        cta.href = safeUrl(promotion.url);
        cta.target = '_blank';
        cta.rel = 'noopener noreferrer';

        var close = el('button', 'sigmize-dw-promo-close', '\u00d7');
        close.type = 'button';
        close.setAttribute('aria-label', strings.closeOffer);
        close.addEventListener('click', function () {
            dismissPromotion(box);
        });

        box.appendChild(text);
        box.appendChild(cta);
        box.appendChild(close);
        box.hidden = false;
    }

    function renderUsage(usage, promotion) {
        var box = field('usage');
        if (!box || !usage) {
            return;
        }

        var percentage = Math.max(0, Math.min(100, Number(usage.percentage) || 0));

        box.textContent = '';
        box.classList.toggle('is-full', percentage >= 100);

        var head = el('div', 'sigmize-dw-usage-head');
        head.appendChild(el('span', '', strings.usageTitle));
        head.appendChild(el('span', '', percentage + '%'));

        var meter = el('div', 'sigmize-dw-meter');
        var fill = el('span');
        fill.style.width = percentage + '%';
        meter.setAttribute('role', 'progressbar');
        meter.setAttribute('aria-valuemin', '0');
        meter.setAttribute('aria-valuemax', '100');
        meter.setAttribute('aria-valuenow', String(percentage));
        meter.setAttribute('aria-label', strings.usageTitle);
        meter.appendChild(fill);

        var foot = el('div', 'sigmize-dw-usage-foot');
        foot.appendChild(el('span', '', (strings.usageText || '')
            .replace('%1$s', function () { return String(usage.current || ''); })
            .replace('%2$s', function () { return String(usage.limit || ''); })));

        // During the sale the link carries the plan's sale price.
        var link = el('a', 'sigmize-dw-link', promotion && promotion.cta ? promotion.cta : strings.upgradePlan);
        link.href = safeUrl(promotion && promotion.url ? promotion.url : usage.url);
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        foot.appendChild(link);

        box.appendChild(head);
        box.appendChild(meter);
        box.appendChild(foot);
        box.hidden = false;
    }

    function render(overview) {
        renderPromotion(overview.promotion);
        renderUsage(overview.usage_nudge, overview.promotion);

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
