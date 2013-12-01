<?php

class WP_Stream_Query {
	
	public static $instance;

	public static function get_instance() {
		if ( ! self::$instance ) {
			$class = __CLASS__;
			self::$instance = new $class;
		}
		return self::$instance;
	}

	/**
	 * Query Stream records
	 *
	 * @param  array|string $args Query args
	 * @return [type]             Stream 
	 */
	public function query( $args ) {
		global $wpdb;

		$defaults = array(
			// Pagination params
			'records_per_page'      => '10',
			'page'                  => 1,
			// Search params
			'search'                => null,
			// Stream core fields filtering
			'type'                  => 'stream',
			'object_id'             => null,
			'ip'                    => null,
			// __in params
			'record__in'            => array(),
			'record__not_in'        => array(),
			'record_parent'         => '',
			'record_parent__in'     => array(),
			'record_parent__not_in' => array(),
			// Order
			'order'                 => 'desc',
			'orderby'               => 'ID',
			// Meta/Taxonomy sub queries
			'meta_query'            => array(),
			'context_query'         => array(),
			// Fields selection
			'fields'                => '',
			);

		$args = wp_parse_args( $args, $defaults );

		$args = apply_filters( 'stream_query_args', $args );

		$join  = '';
		$where = '';

		/**
		 * PARSE CORE FILTERS
		 */
		if ( $args['object_id'] ) {
			$where .= $wpdb->prepare( " AND $wpdb->stream.object_id = %d", $args['object_id'] );
		}

		if ( $args['type'] ) {
			$where .= $wpdb->prepare( " AND $wpdb->stream.type = %s", $args['type'] );
		}

		if ( $args['ip'] ) {
			$where .= $wpdb->prepare( " AND $wpdb->stream.ip = %s", filter_var( $args['ip'], FILTER_VALIDATE_IP ) );
		}

		if ( $args['search'] ) {
			$where .= $wpdb->prepare( " AND $wpdb->stream.summary LIKE %s", "%{$args['search']}%" );
		}

		/**
		 * PARSE __IN PARAM FAMILY
		 */
		if ( $args['record__in'] ) {
			$record__in = implode( ',', array_filter( (array) $args['record__in'], 'is_numeric' ) );
			if ( $record__in ) {
				$where .= $wpdb->prepare( " AND $wpdb->stream.ID IN ($record__in)", '' );
			}
		}

		if ( $args['record__not_in'] ) {
			$record__not_in = implode( ',', array_filter( (array) $args['record__not_in'], 'is_numeric' ) );
			if ( strlen( $record__not_in ) ) {
				$where .= $wpdb->prepare( " AND $wpdb->stream.ID NOT IN ($record__not_in)", '' );
			}
		}

		if ( $args['record_parent'] ) {
			$where .= $wpdb->prepare( " AND $wpdb->stream.parent = %d", (int) $args['record_parent'] );
		}

		if ( $args['record_parent__in'] ) {
			$record_parent__in = implode( ',', array_filter( (array) $args['record_parent__in'], 'is_numeric' ) );
			if ( strlen( $record_parent__in ) ) {
				$where .= $wpdb->prepare( " AND $wpdb->stream.parent IN ($record_parent__in)", '' );
			}
		}

		if ( $args['record_parent__not_in'] ) {
			$record_parent__not_in = implode( ',', array_filter( (array) $args['record_parent__not_in'], 'is_numeric' ) );
			if ( strlen( $record_parent__not_in ) ) {
				$where .= $wpdb->prepare( " AND $wpdb->stream.parent NOT IN ($record_parent__not_in)", '' );
			}
		}

		/**
		 * PARSE META QUERY PARAMS
		 */
		$meta_query = new WP_Meta_Query;
		$meta_query->parse_query_vars( $args );
		if ( ! empty( $meta_query->queries ) ) {
			$mclauses = $meta_query->get_sql( 'stream', $wpdb->stream, 'ID' );
			$join    .= str_replace( 'stream_id', 'record_id', $mclauses['join'] );
			$where   .= str_replace( 'stream_id', 'record_id', $mclauses['where'] );
		}

		/**
		 * PARSE CONTEXT PARAMS
		 */
		$context_query = new WP_Stream_Context_Query( $args );
		$cclauses      = $context_query->get_sql();
		$join         .= $cclauses['join'];
		$where        .= $cclauses['where'];

		/**
		 * PARSE PAGINATION PARAMS
		 */
		$page   = intval( $args['page'] );
		$perpage = intval( $args['records_per_page'] );
		$pgstrt = ($page - 1) * $perpage;
		$limits = "LIMIT $pgstrt, {$perpage}";

		/**
		 * PARSE ORDER PARAMS
		 */
		$order   = esc_sql( $args['order'] );
		$orderby = esc_sql( $args['orderby'] );

		if ( in_array(
			$orderby,
			array( 'ID', 'site_id', 'object_id', 'author', 'summary', 'visibility', 'parent', 'type', 'created' )
			) ) {
			$orderby = $wpdb->stream . '.' . $orderby;
		}
		elseif ( in_array( $orderby, array( 'connector', 'context', 'action' ) ) ) {
			$join   .= sprintf(
				' INNER JOIN %1$s ON ( %1$s.record_id = %2$s.ID )',
				$wpdb->streamcontext,
				$wpdb->stream
			);
			$orderby = $wpdb->streamcontext . '.' . $orderby;
		}
		elseif ( $orderby == 'meta_value_num' && ! empty( $args['meta_key'] ) ) {
			$orderby = "CAST($wpdb->streammeta.meta_value AS SIGNED)";
		}
		elseif ( $orderby == 'meta_value' && ! empty( $args['meta_key'] ) ) {
			$orderby = "$wpdb->streammeta.meta_value";
		}
		else {
			$orderby = "$wpdb->stream.ID";
		}
		$orderby = 'ORDER BY ' . $orderby . ' ' . $order;

		/**
		 * PARSE FIELDS PARAMETER
		 */
		$fields = $args['fields'];
		$select = "$wpdb->stream.*";
		if ( $fields == 'ID' ) {
			$select = "$wpdb->stream.ID";
		}
		elseif ( $fields == 'summary' ) {
			$select = "$wpdb->stream.summary, $wpdb->stream.ID";
		}

		/**
		 * BUILD UP THE FINAL QUERY
		 */
		$sql = "SELECT $select
		FROM $wpdb->stream
		$join
		WHERE 1=1 $where
		$orderby
		$limits";

		if ( ! empty( $fields ) ) {
			$results = $wpdb->get_col( $sql );
		} else {
			$results = $wpdb->get_results( $sql );
		}

		return $results;
	}

}

function stream_query( $args ) {
	return WP_Stream_Query::get_instance()->query( $args );
}