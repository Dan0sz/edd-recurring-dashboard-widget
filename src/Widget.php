<?php
/**
 * @package Daan\EDD\Recurring
 * @author  Daan van den Bergh
 * @url https://daan.dev
 * @license MIT
 */

namespace Daan\EDD\Recurring;

class Widget {
	/**
	 * Subscription statuses that will (still) renew. 'failing' subscriptions are being retried by the gateway and
	 * 'trialling' ones renew when the trial ends; cancelled, expired and completed subscriptions don't renew.
	 */
	const RENEWING_STATUSES = [ 'active', 'failing', 'trialling' ];

	/**
	 * @var string[] $timespans Period key => label.
	 */
	private $timespans = [];

	/**
	 * @var string $text_domain
	 */
	private $text_domain = 'edd-recurring-dashboard-widget';

	/**
	 * Build class.
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Action & Filter hooks.
	 *
	 * @return void
	 */
	private function init() {
		add_action( 'admin_init', [ $this, 'set_timespans' ] );
		add_action( 'edd_sales_summary_widget_after_stats', [ $this, 'add_stats' ], 11 );
	}

	/**
	 * Set the timespans as translatable strings, keyed by period (so translations don't affect the periods).
	 */
	public function set_timespans() {
		$this->timespans = [
			'overdue'      => __( 'Overdue', $this->text_domain ),
			'tomorrow'     => __( 'Tomorrow', $this->text_domain ),
			'this_week'    => __( 'This Week', $this->text_domain ),
			'next_week'    => __( 'Next Week', $this->text_domain ),
			'this_month'   => __( 'This Month', $this->text_domain ),
			'next_month'   => __( 'Next Month', $this->text_domain ),
			'this_quarter' => __( 'This Quarter', $this->text_domain ),
			'next_quarter' => __( 'Next Quarter', $this->text_domain ),
			'this_year'    => __( 'This Year', $this->text_domain ),
			'next_year'    => __( 'Next Year', $this->text_domain ),
		];
	}

	/**
	 * Render the stats.
	 * @return void
	 */
	public function add_stats() {
		?>
        <div class="table table_left table_totals">
            <table>
                <thead>
                <tr>
                    <td colspan="2"><?php echo esc_attr( __( 'Upcoming Recurring Sales', 'edd-recurring' ) ); ?></td>
                </tr>
                </thead>
                <tbody>
				<?php foreach ( $this->timespans as $period => $label ): ?>
                    <tr>
                        <td class="t"><?php echo esc_attr( $label ); ?></td>
                        <td class="last b"><?php echo esc_attr( $this->get_estimated( $period, 'sales' ) ); ?></td>
                    </tr>
				<?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="table table_right table_totals">
            <table>
                <thead>
                <tr>
                    <td colspan="2"><?php echo esc_attr( __( 'Upcoming Recurring Revenue', 'edd-recurring' ) ); ?></td>
                </tr>
                </thead>
                <tbody>
				<?php foreach ( $this->timespans as $period => $label ): ?>
                    <tr>
                        <td class="t"><?php echo esc_attr( $label ); ?></td>
                        <td class="last b"><?php echo esc_attr( $this->get_estimated( $period ) ); ?></td>
                    </tr>
				<?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="clear: both"></div>
		<?php
	}

	/**
	 * Get the number of renewals or the expected revenue in a period.
	 *
	 * @param string $period Key of self::$timespans.
	 * @param string $type   revenue | sales
	 *
	 * @return string|int
	 */
	private function get_estimated( $period = 'this_month', $type = 'revenue' ) {
		global $wpdb;

		$key    = 'daan_recurring_estimated_' . $type . '_' . $period;
		$amount = get_transient( $key );

		// No transient (0 is a valid, cacheable result).
		if ( false === $amount ) {
			[ $begin, $end ] = $this->get_range( $period );

			$command  = $type === 'revenue' ? 'SUM(recurring_amount)' : 'COUNT(*)';
			$statuses = implode( ', ', array_fill( 0, count( self::RENEWING_STATUSES ), '%s' ) );
			$where    = [ "status IN ($statuses)" ];
			$args     = self::RENEWING_STATUSES;

			if ( $begin ) {
				$where[] = 'expiration >= %s';
				$args[]  = $begin;
			}

			$where[] = 'expiration <= %s';
			$args[]  = $end;

			$query  = "SELECT $command FROM {$wpdb->prefix}edd_subscriptions WHERE " . implode( ' AND ', $where );
			$amount = (string) (float) $wpdb->get_var( $wpdb->prepare( $query, $args ) );

			// Cache
			set_transient( $key, $amount, 300 );
		}

		if ( $type === 'revenue' ) {
			return edd_currency_filter( edd_format_amount( edd_sanitize_amount( $amount ) ) );
		}

		return (int) $amount;
	}

	/**
	 * Date range for a period, in the site's timezone (subscription expiration dates are stored in local time).
	 *
	 * "This …" periods start in the past: renewing subscriptions whose expiration date has already passed are still
	 * waiting for their payment (e.g. SEPA direct debit or a retry after a failed payment), so they'll renew within
	 * the period too.
	 *
	 * @param string $period
	 *
	 * @return array [ begin (Y-m-d H:i:s, or null for "no lower bound"), end (Y-m-d H:i:s) ]
	 */
	private function get_range( $period ) {
		$today   = new \DateTimeImmutable( 'today', wp_timezone() );
		$quarter = (int) ceil( (int) $today->format( 'n' ) / 3 );
		$q_start = $today->setDate( (int) $today->format( 'Y' ), ( $quarter - 1 ) * 3 + 1, 1 );

		switch ( $period ) {
			case 'overdue':
				$begin = null;
				$end   = $today->modify( '-1 second' );

				break;
			case 'tomorrow':
				$begin = $today->modify( '+1 day' );
				$end   = $begin->setTime( 23, 59, 59 );

				break;
			case 'this_week':
				$begin = null;
				$end   = $today->modify( 'sunday this week' )->setTime( 23, 59, 59 );

				break;
			case 'next_week':
				$begin = $today->modify( 'monday next week' );
				$end   = $begin->modify( 'sunday this week' )->setTime( 23, 59, 59 );

				break;
			case 'this_month':
				$begin = null;
				$end   = $today->modify( 'last day of this month' )->setTime( 23, 59, 59 );

				break;
			case 'next_month':
				$begin = $today->modify( 'first day of next month' );
				$end   = $begin->modify( 'last day of this month' )->setTime( 23, 59, 59 );

				break;
			case 'this_quarter':
				$begin = null;
				$end   = $q_start->modify( '+3 months -1 day' )->setTime( 23, 59, 59 );

				break;
			case 'next_quarter':
				$begin = $q_start->modify( '+3 months' );
				$end   = $begin->modify( '+3 months -1 day' )->setTime( 23, 59, 59 );

				break;
			case 'this_year':
				$begin = null;
				$end   = $today->setDate( (int) $today->format( 'Y' ), 12, 31 )->setTime( 23, 59, 59 );

				break;
			case 'next_year':
			default:
				$year  = (int) $today->format( 'Y' ) + 1;
				$begin = $today->setDate( $year, 1, 1 );
				$end   = $begin->setDate( $year, 12, 31 )->setTime( 23, 59, 59 );
		}

		return [ $begin ? $begin->format( 'Y-m-d H:i:s' ) : null, $end->format( 'Y-m-d H:i:s' ) ];
	}
}
