<?php

/**
 * Black Friday x Cyber Monday 2026 promotion
 *
 * @package Sigmize
 */

namespace Sigmize\Admin;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Single source of truth for every Black Friday nudge in wp-admin.
 *
 * Dates, prices and the "who sees what" rule mirror the app's
 * lib/promotions/black-friday.ts so the plugin never offers something the app
 * does not. Everything here fails closed: outside the sale window, for anyone
 * who is not the account owner, for Lifetime customers, or when the plan is
 * unknown, every method returns null and nothing is shown.
 *
 * The dates are built in rather than fetched, so a site that is not connected
 * makes no external request to decide whether to show the sale, and every
 * nudge switches itself off on ENDS_AT without a plugin update.
 *
 * @since 1.2.1
 */
class Promotion
{

    /**
     * Sale start: 2026-11-10 00:00 UTC.
     *
     * @var int
     */
    const STARTS_AT = 1794268800;

    /**
     * Sale end: 2026-12-06 04:00 UTC (09:30 IST, same as the sigmize.com countdown).
     *
     * @var int
     */
    const ENDS_AT = 1796529600;

    /**
     * The last 48 hours are the "last chance" phase.
     *
     * @var int
     */
    const LAST_CHANCE_SECONDS = 172800;

    /**
     * Visitor usage, in percent, at which the usage nudge appears.
     *
     * @var int
     */
    const USAGE_THRESHOLD = 80;

    /**
     * User meta key set when a user closes the offer. Year-scoped so next
     * year's sale is not hidden by this year's dismissal.
     *
     * @var string
     */
    const DISMISS_META = 'sigmize_bfcm_2026_dismissed';

    /**
     * Option holding the plan slug this site was last connected with. Kept on
     * disconnect so a returning Lifetime customer is never shown the sale.
     *
     * @var string
     */
    const LAST_PLAN_OPTION = 'sigmize_last_plan_slug';

    /**
     * UTM campaign used on every promotion link.
     *
     * @var string
     */
    const CAMPAIGN = 'bfcm-2026';

    /**
     * Highest discount advertised anywhere.
     *
     * @var int
     */
    const MAX_DISCOUNT_PERCENT = 50;

    /**
     * Public pricing page, used when the site has no account yet.
     *
     * @var string
     */
    const PRICING_URL = 'https://sigmize.com/pricing';

    /**
     * Sale prices in USD, from the Sigmize BFCM 2026 pricing sheet. Must match
     * BLACK_FRIDAY_PRICES in the app.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function prices()
    {
        return array(
            'pro'      => array('name' => 'Pro', 'regular' => 599, 'sale' => 349, 'billing' => 'yearly'),
            'business' => array('name' => 'Business', 'regular' => 1199, 'sale' => 699, 'billing' => 'yearly'),
            'lifetime' => array('name' => 'Lifetime', 'regular' => 599, 'sale' => 299, 'billing' => 'one-time'),
        );
    }

    /**
     * Whether promotions may show at all right now.
     *
     * @param int|null $now Unix time, for tests. Defaults to time().
     * @return bool
     */
    public static function is_live($now = null)
    {
        $now = null === $now ? time() : (int) $now;

        if ($now < self::STARTS_AT || $now >= self::ENDS_AT) {
            return false;
        }

        /**
         * Filter whether Sigmize shows Black Friday promotions in wp-admin.
         *
         * Return false to hide every promotion, for example on client sites.
         *
         * @since 1.2.1
         *
         * @param bool $show Whether to show promotions.
         */
        return (bool) apply_filters('sigmize_show_promotions', true);
    }

    /**
     * Whether the sale is in its final 48 hours.
     *
     * @param int|null $now Unix time, for tests.
     * @return bool
     */
    public static function is_last_chance($now = null)
    {
        $now = null === $now ? time() : (int) $now;

        return self::is_live($now) && (self::ENDS_AT - $now) <= self::LAST_CHANCE_SECONDS;
    }

    /**
     * Whether a plan slug is a Lifetime plan (lifetime, early-bird-lifetime).
     *
     * @param string $slug Plan slug.
     * @return bool
     */
    public static function is_lifetime_slug($slug)
    {
        return false !== strpos((string) $slug, 'lifetime');
    }

    /**
     * Which offer a plan gets, using the app's getBlackFridayAudience() rule.
     *
     * - 'free'    free plan, any trial, or no active plan: Lifetime at 50% off
     * - 'starter' Starter: upgrade to Pro
     * - 'pro'     Pro: move up to Business
     * - null      Lifetime, Business, non-owners, or an unknown plan
     *
     * Lifetime is checked first so a Lifetime customer can never fall through
     * to the "no active plan" branch.
     *
     * @param mixed $plan     Normalized plan: slug, is_trial, has_active_plan.
     * @param mixed $is_owner Whether the connected user owns the organization.
     * @return string|null
     */
    public static function audience($plan, $is_owner)
    {
        if (true !== $is_owner || ! is_array($plan)) {
            return null;
        }

        $slug = isset($plan['slug']) ? (string) $plan['slug'] : '';

        // An older platform that does not send the slug: show nothing.
        if ('' === $slug || self::is_lifetime_slug($slug)) {
            return null;
        }

        $has_active_plan = isset($plan['has_active_plan']) ? $plan['has_active_plan'] : null;

        if ('free' === $slug || ! empty($plan['is_trial']) || false === $has_active_plan) {
            return 'free';
        }

        if (0 === strpos($slug, 'starter')) {
            return 'starter';
        }

        if (0 === strpos($slug, 'pro')) {
            return 'pro';
        }

        return null;
    }

    /**
     * The plan offered to an audience, with the saving worked out.
     *
     * @param string $audience 'free', 'starter' or 'pro'.
     * @return array<string, mixed>
     */
    public static function offer($audience)
    {
        $prices = self::prices();
        $key    = 'starter' === $audience ? 'pro' : ('pro' === $audience ? 'business' : 'lifetime');
        $plan   = $prices[$key];

        $plan['key']     = $key;
        $plan['saving']  = $plan['regular'] - $plan['sale'];
        $plan['percent'] = (int) round(($plan['saving'] / $plan['regular']) * 100);

        return $plan;
    }

    /**
     * Link into the app or the pricing page with campaign tracking attached.
     *
     * @param string $base    Absolute URL to link to.
     * @param string $source  Where the link sits, e.g. 'wp-dashboard-widget'.
     * @param string $content Which nudge, e.g. 'sale-line'.
     * @return string
     */
    public static function tracked_url($base, $source, $content)
    {
        return add_query_arg(
            array(
                'utm_source'   => sanitize_key($source),
                'utm_medium'   => 'wordpress',
                'utm_campaign' => self::CAMPAIGN,
                'utm_content'  => sanitize_key($content),
            ),
            $base
        );
    }

    /**
     * The app's plans page, where the owner sees the sale prices.
     *
     * @param string $source  UTM source.
     * @param string $content UTM content.
     * @return string
     */
    public static function plans_url($source, $content)
    {
        return self::tracked_url(untrailingslashit(SIGMIZE_SAAS_BASE_URL) . '/organization/plans', $source, $content);
    }

    /**
     * Whether a user has closed this year's offer.
     *
     * @param int $user_id User ID.
     * @return bool
     */
    public static function is_dismissed($user_id)
    {
        return $user_id > 0 && '1' === (string) get_user_meta($user_id, self::DISMISS_META, true);
    }

    /**
     * Hide this year's offer for a user.
     *
     * @param int $user_id User ID.
     * @return bool
     */
    public static function dismiss($user_id)
    {
        if ($user_id <= 0) {
            return false;
        }

        return (bool) update_user_meta($user_id, self::DISMISS_META, '1');
    }

    /**
     * Remember the plan this site is connected with.
     *
     * @param string $slug Plan slug from the platform.
     */
    public static function remember_plan($slug)
    {
        $slug = sanitize_key((string) $slug);

        if ('' === $slug || 'none' === $slug || get_option(self::LAST_PLAN_OPTION) === $slug) {
            return;
        }

        update_option(self::LAST_PLAN_OPTION, $slug, false);
    }

    /**
     * Whether this site was last connected to a plan that never gets the sale
     * (Lifetime, Early-bird Lifetime or Business).
     *
     * @return bool
     */
    public static function site_has_top_plan()
    {
        $slug = (string) get_option(self::LAST_PLAN_OPTION, '');

        return self::is_lifetime_slug($slug) || 0 === strpos($slug, 'business');
    }

    /**
     * Offer for a site that is not connected yet.
     *
     * @param int|null $now Unix time, for tests.
     * @return array<string, mixed>|null
     */
    public static function connect_offer($now = null)
    {
        if (! self::is_live($now) || self::site_has_top_plan()) {
            return null;
        }

        return array(
            'endsAt'      => self::ENDS_AT * 1000,
            'percent'     => self::MAX_DISCOUNT_PERCENT,
            'lastChance'  => self::is_last_chance($now),
            'pricingUrl'  => self::tracked_url(self::PRICING_URL, 'wp-connect-screen', 'sale-panel'),
            'glowUrl'     => SIGMIZE_PLUGIN_URL . 'assets/images/bfcm-glow.webp',
        );
    }

    /**
     * Offer for a connected site, from the normalized overview.
     *
     * @param mixed    $overview Normalized overview.
     * @param int      $user_id  Current WordPress user.
     * @param string   $source   UTM source for the link.
     * @param int|null $now      Unix time, for tests.
     * @return array<string, mixed>|null
     */
    public static function for_overview($overview, $user_id, $source, $now = null)
    {
        if (! self::is_live($now) || self::is_dismissed($user_id) || ! is_array($overview)) {
            return null;
        }

        $audience = self::audience(
            isset($overview['plan']) ? $overview['plan'] : array(),
            isset($overview['viewer']['is_owner']) ? $overview['viewer']['is_owner'] : null
        );

        if (null === $audience) {
            return null;
        }

        $offer   = self::offer($audience);
        $one_off = 'one-time' === $offer['billing'];

        if ('starter' === $audience) {
            /* translators: %s: sale price including the currency sign, e.g. $349. */
            $line     = sprintf(__('Upgrade to Pro for %s/year', 'sigmize'), '$' . $offer['sale']);
            /* translators: %s: amount saved including the currency sign, e.g. $250. */
            $headline = sprintf(__('Save %s on Pro', 'sigmize'), '$' . $offer['saving']);
            $body     = __('Get 400,000 visitors a year and run more experiments at once.', 'sigmize');
        } elseif ('pro' === $audience) {
            /* translators: %s: sale price including the currency sign, e.g. $699. */
            $line     = sprintf(__('Move up to Business for %s/year', 'sigmize'), '$' . $offer['sale']);
            /* translators: %s: amount saved including the currency sign, e.g. $500. */
            $headline = sprintf(__('Save %s on Business', 'sigmize'), '$' . $offer['saving']);
            $body     = __('Get 1M visitors a year for your biggest sites and launches.', 'sigmize');
        } else {
            /* translators: %d: discount percentage. */
            $line     = sprintf(__('Get Sigmize at %d%% off', 'sigmize'), $offer['percent']);
            /* translators: %d: discount percentage. */
            $headline = sprintf(__('Sale is live %d%% off', 'sigmize'), $offer['percent']);
            $body     = __('Pay once and keep A/B testing, heatmaps and session recordings for life.', 'sigmize');
        }

        return array(
            'audience'   => $audience,
            'endsAt'     => self::ENDS_AT * 1000,
            'lastChance' => self::is_last_chance($now),
            'line'       => $line,
            'headline'   => $headline,
            'body'       => $body,
            'plan'       => $offer['name'],
            'regular'    => '$' . number_format($offer['regular']),
            'sale'       => '$' . number_format($offer['sale']),
            'billing'    => $one_off ? __('one-time', 'sigmize') : __('/year', 'sigmize'),
            'cta'        => __('Upgrade', 'sigmize'),
            'url'        => self::plans_url($source, 'offer'),
        );
    }

    /**
     * Visitor-limit nudge, shown all year once usage passes USAGE_THRESHOLD.
     *
     * Never shown to Lifetime customers or anyone who is not the owner.
     *
     * @param mixed  $overview Normalized overview.
     * @param string $source   UTM source for the link.
     * @return array<string, mixed>|null
     */
    public static function usage_nudge($overview, $source)
    {
        if (! is_array($overview) || empty($overview['viewer']['is_owner'])) {
            return null;
        }

        $slug = isset($overview['plan']['slug']) ? (string) $overview['plan']['slug'] : '';

        if ('' === $slug || self::is_lifetime_slug($slug)) {
            return null;
        }

        $usage = isset($overview['plan']['features']['unique_visitors']) ? $overview['plan']['features']['unique_visitors'] : null;

        if (! is_array($usage) || empty($usage['limit']) || (int) $usage['limit'] <= 0) {
            return null;
        }

        $percentage = (float) $usage['percentage'];

        if ($percentage < self::USAGE_THRESHOLD) {
            return null;
        }

        return array(
            'percentage' => min(100, (int) round($percentage)),
            'current'    => (string) $usage['current_display'],
            'limit'      => (string) $usage['limit_display'],
            'url'        => self::plans_url($source, 'visitor-limit'),
        );
    }
}
