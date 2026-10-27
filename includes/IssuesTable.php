<?php
/**
 * Bulgular listesi (WP_List_Table): süzgeç, arama, sıralama, toplu eylem.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class IssuesTable extends \WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'bulgu',
				'plural'   => 'bulgular',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'cb'       => '<input type="checkbox" />',
			'rule'     => __( 'Kural', 'contenthealth' ),
			'severity' => __( 'Ciddiyet', 'contenthealth' ),
			'object'   => __( 'Nesne', 'contenthealth' ),
			'summary'  => __( 'Özet', 'contenthealth' ),
			'status'   => __( 'Durum', 'contenthealth' ),
			'last'     => __( 'Son görülme', 'contenthealth' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'rule'     => array( 'rule_id', false ),
			'severity' => array( 'severity', false ),
			'status'   => array( 'status', false ),
			'last'     => array( 'last_seen', true ),
		);
	}

	protected function get_bulk_actions(): array {
		return array(
			'ignore' => __( 'Yoksay', 'contenthealth' ),
			'reopen' => __( 'Yeniden aç', 'contenthealth' ),
		);
	}

	protected function column_cb( $item ): string {
		return '<label class="screen-reader-text" for="ch-cb-' . (int) $item->id . '">' . esc_html__( 'Bulguyu seç', 'contenthealth' ) . '</label><input id="ch-cb-' . (int) $item->id . '" type="checkbox" name="ids[]" value="' . (int) $item->id . '" />';
	}

	/** @param object $item */
	protected function column_rule( $item ): string {
		$rule  = Registry::get( $item->rule_id );
		$label = $rule ? $rule->label() : $item->rule_id;
		$url   = add_query_arg(
			array(
				'page' => Admin::SLUG,
				'tab'  => 'issues',
				'view' => (int) $item->id,
			),
			admin_url( 'tools.php' )
		);
		return '<a href="' . esc_url( $url ) . '"><strong>' . esc_html( $label ) . '</strong></a>';
	}

	/** @param object $item */
	protected function column_severity( $item ): string {
		$sev = Registry::severities();
		return '<span class="ch-badge ch-sev-' . esc_attr( $item->severity ) . '">' . esc_html( $sev[ $item->severity ] ?? $item->severity ) . '</span>';
	}

	/** @param object $item */
	protected function column_object( $item ): string {
		if ( 'term' === $item->object_type ) {
			$t = get_term( $item->object_id );
			if ( $t && ! is_wp_error( $t ) ) {
				$link = get_edit_term_link( $t->term_id, $t->taxonomy );
				return $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $t->name ) . '</a>' : esc_html( $t->name );
			}
			return esc_html( '#' . $item->object_id ) . ' <em>' . esc_html__( '(silinmiş)', 'contenthealth' ) . '</em>';
		}
		$title = get_the_title( $item->object_id );
		$link  = get_edit_post_link( $item->object_id );
		$label = '' !== $title ? $title : '#' . $item->object_id;
		return $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( Text::snippet( $label, 70 ) ) . '</a>' : esc_html( $label );
	}

	/** @param object $item */
	protected function column_summary( $item ): string {
		$d = $item->details_arr;
		if ( isset( $d['items'][0] ) ) {
			$first = $d['items'][0];
			$txt   = is_array( $first ) ? (string) reset( $first ) : (string) $first;
			return esc_html( sprintf( '%d× · %s', (int) ( $d['count'] ?? 1 ), Text::snippet( $txt, 90 ) ) );
		}
		if ( isset( $d['reasons'] ) ) {
			return esc_html( implode( ', ', array_keys( $d['reasons'] ) ) );
		}
		return esc_html( Text::snippet( (string) wp_json_encode( $d, JSON_UNESCAPED_UNICODE ), 90 ) );
	}

	/** @param object $item */
	protected function column_status( $item ): string {
		$map = array(
			'open'    => __( 'Açık', 'contenthealth' ),
			'ignored' => __( 'Yoksayıldı', 'contenthealth' ),
			'fixed'   => __( 'Düzeltildi', 'contenthealth' ),
		);
		return esc_html( $map[ $item->status ] ?? $item->status );
	}

	/** @param object $item */
	protected function column_last( $item ): string {
		return esc_html( get_date_from_gmt( (string) $item->last_seen, 'Y-m-d H:i' ) );
	}

	/** @param object $item @param string $name */
	protected function column_default( $item, $name ): string {
		return '';
	}

	/** Süzgeç çubuğu. */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$rule   = isset( $_GET['rule'] ) ? sanitize_key( wp_unslash( $_GET['rule'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'open'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sev    = isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="ch-f-rule">' . esc_html__( 'Kural', 'contenthealth' ) . '</label><select id="ch-f-rule" name="rule"><option value="">' . esc_html__( 'Tüm kurallar', 'contenthealth' ) . '</option>';
		foreach ( Registry::rules() as $r ) {
			echo '<option value="' . esc_attr( $r->id() ) . '"' . selected( $rule, $r->id(), false ) . '>' . esc_html( $r->label() ) . '</option>';
		}
		echo '</select> <label class="screen-reader-text" for="ch-f-sev">' . esc_html__( 'Ciddiyet', 'contenthealth' ) . '</label><select id="ch-f-sev" name="severity"><option value="">' . esc_html__( 'Tüm ciddiyetler', 'contenthealth' ) . '</option>';
		foreach ( Registry::severities() as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $sev, $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select> <label class="screen-reader-text" for="ch-f-status">' . esc_html__( 'Durum', 'contenthealth' ) . '</label><select id="ch-f-status" name="status">';
		foreach ( array(
			'open'    => __( 'Açık', 'contenthealth' ),
			'ignored' => __( 'Yoksayılan', 'contenthealth' ),
			'fixed'   => __( 'Düzeltilen', 'contenthealth' ),
			''        => __( 'Hepsi', 'contenthealth' ),
		) as $k => $v ) {
			echo '<option value="' . esc_attr( (string) $k ) . '"' . selected( $status, (string) $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Süz', 'contenthealth' ), '', 'filter_action', false );
		echo '</div>';
	}

	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- salt-okunur süzgeç.
		$per = 25;
		$res = Issues::query(
			array(
				'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'open',
				'rule'     => isset( $_GET['rule'] ) ? sanitize_key( wp_unslash( $_GET['rule'] ) ) : '',
				'severity' => isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : '',
				'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
				'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id',
				'order'    => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
				'per_page' => $per,
				'page'     => $this->get_pagenum(),
			)
		);
		// phpcs:enable
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->items           = $res['rows'];
		$this->set_pagination_args(
			array(
				'total_items' => $res['total'],
				'per_page'    => $per,
			)
		);
	}
}
