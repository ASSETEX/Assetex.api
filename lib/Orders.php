<?php
class Orders {
	public static function get($count=false,$page=false,$per_page=false,$currency=false,$user=false,$start_date=false,$show_bids=false,$order_by1=false,$order_desc=false,$dont_paginate=false,$public_api_open_orders=false,$public_api_order_book=false) {
		global $CFG;
		
		if ($user && !(User::$info['id'] > 0))
			return false;
		
		$page = preg_replace("/[^0-9]/", "",$page);
		$per_page = preg_replace("/[^0-9]/", "",$per_page);
		$page = preg_replace("/[^0-9]/", "",$page);
		$currency = preg_replace("/[^a-zA-Z]/", "",$currency);
		$start_date = preg_replace ("/[^0-9: \-]/","",$start_date);
		
		$page = ($page > 0) ? $page - 1 : 0;
		$r1 = $page * $per_page;
		$order_arr = array('date'=>'orders.date','btc'=>'orders.btc','btcprice'=>'btc_price','fiat'=>'usd_amount');
		$order_by = ($order_by1) ? $order_arr[$order_by1] : ((!$currency && $dont_paginate) ? 'usd_price' : 'btc_price');
		$order_desc = ($order_desc && ($order_by1 != 'date' && $order_by1 != 'fiat')) ? 'ASC' : 'DESC';
		$currency_info = (!empty($CFG->currencies[strtoupper($currency)])) ? $CFG->currencies[strtoupper($currency)] : false;
		$usd_info = $CFG->currencies['USD'];
		$user = ($user) ? User::$info['id'] : false;
		$type = ($show_bids) ? $CFG->order_type_bid : $CFG->order_type_ask;
		$user_id = (User::$info['id'] > 0) ? User::$info['id'] : '0';
		
		//$usd_field = ($show_bids) ? 'usd_bid' : 'usd_ask';
		$usd_field = 'usd_ask';
		$conv_comp = ($show_bids) ? '-' : '+';
		$conversion = ($usd_info['id'] == $currency_info['id']) ? ' currencies.'.$usd_field : ' (1 / IF(orders.currency = '.$usd_info['id'].','.$currency_info[$usd_field].', '.$currency_info[$usd_field].' / currencies.'.$usd_field.'))';
		$btc_price = ($currency && !$user && $CFG->cross_currency_trades) ? "ROUND(IF(orders.currency = {$currency_info['id']},orders.btc_price,orders.btc_price * ($conversion $conv_comp ($conversion * {$CFG->currency_conversion_fee}))),2) AS btc_price, " : false;
		$btc_price1 = ($currency && !$user && $CFG->cross_currency_trades) ? str_replace('AS btc_price,','',$btc_price) : false;
		//$btc_price = ($currency && !$user && $CFG->cross_currency_trades) ? "ROUND(IF(orders.currency = {$currency_info['id']},orders.btc_price,orders.btc_price * ($conversion $conv_comp ($conversion * {$CFG->currency_conversion_fee}))),2) AS btc_price, " : false;
		
		if (!$count && !$public_api_open_orders && !$public_api_order_book)
			$sql = "SELECT orders.*, ".(!$user ? 'SUM(orders.btc) AS btc,' : '')." $btc_price order_types.name_{$CFG->language} AS type, currencies.currency AS currency, (currencies.$usd_field * orders.fiat) AS usd_amount, (currencies.$usd_field * orders.btc_price) AS usd_price, orders.btc_price AS fiat_price, (UNIX_TIMESTAMP(orders.date) * 1000) AS time_since, ".(($currency && !$user && $CFG->cross_currency_trades) ? "'".$currency_info['fa_symbol']."'" : 'currencies.fa_symbol')." AS fa_symbol, ".(!$user ? 'SUM(' : '')."IF(".$user_id." = orders.site_user ".(($currency && $CFG->cross_currency_trades) ? "AND orders.currency = {$currency_info['id']}" : '').",1,0)".(!$user ? ')' : '')." AS mine, currencies.currency AS currency_abbr ";
		elseif (!$count && $public_api_order_book)
			$sql = "SELECT $btc_price1 AS price, orders.btc AS order_amount, ROUND((orders.btc * $btc_price1),2) AS order_value, IF(currencies.id != {$currency_info['id']},currencies.currency,'') AS converted_from ";
		elseif (!$count && $public_api_open_orders)
			$sql = "SELECT order_log.id AS id, IF(order_log.order_type = {$CFG->order_type_bid},'buy','sell') AS side, (IF(order_log.market_price = 'Y','market',IF(order_log.stop_price > 0,'stop','limit'))) AS `type`, order_log.btc AS amount, IF(order_log.status = 'ACTIVE',orders.btc,order_log.btc_remaining) AS amount_remaining, order_log.btc_price AS price, ROUND(SUM(IF(transactions.id IS NOT NULL OR transactions1.id IS NOT NULL,(transactions.btc  / (order_log.btc - IF(order_log.status = 'ACTIVE',orders.btc,order_log.btc_remaining))) * IF(transactions.id IS NOT NULL,transactions.btc_price,transactions1.orig_btc_price),0)),2) AS avg_price_executed, order_log.stop_price AS stop_price, currencies.currency AS currency, order_log.status AS status, order_log.p_id AS replaced, IF(order_log.status = 'REPLACED',replacing_order.id,0) AS replaced_by";
		else
			$sql = "SELECT COUNT(orders.id) AS total ";
			
		$sql .= " 
		FROM orders
		LEFT JOIN order_types ON (order_types.id = orders.order_type)
		LEFT JOIN currencies ON (currencies.id = orders.currency)";
		
		if ($public_api_open_orders) {
			$sql .= "
		LEFT JOIN order_log ON (order_log.id = orders.log_id)
		LEFT JOIN transactions ON (order_log.id = transactions.log_id)
		LEFT JOIN transactions transactions1 ON (order_log.id = transactions1.log_id1)
		LEFT JOIN order_log replacing_order ON (order_log.id = replacing_order.p_id)";
		}
		
		$sql .= "
		WHERE 1 ";
			
		if ($user > 0)
			$sql .= " AND orders.site_user = $user ";
		else
			$sql .= ' AND orders.btc_price > 0 ';
		
		if ($start_date > 0)
			$sql .= " AND orders.date >= '$start_date' ";
		if ($type > 0)
			$sql .= " AND orders.order_type = $type ";
		
		if ($currency && ($user > 0 || !$CFG->cross_currency_trades))
			$sql .= " AND orders.currency = {$currency_info['id']} ";
		
		if (!$user && !$public_api_order_book)
			$sql .= ' GROUP BY CONCAT( orders.btc_price, "-", orders.currency) ';
		
		if ($public_api_open_orders)
			$sql .= ' GROUP BY order_log.id ';
		
		if ($public_api_order_book)
			$sql .= ' GROUP BY CONCAT( orders.btc_price, "-", orders.currency ) ';
			
		if ($per_page > 0 && !$count && !$dont_paginate)
			$sql .= " ORDER BY $order_by $order_desc LIMIT $r1,$per_page ";
		if (!$count && $dont_paginate && !$public_api_open_orders && !$public_api_order_book)
			$sql .= " ORDER BY $order_by $order_desc ";
		if (!$count && $dont_paginate && ($public_api_open_orders || $public_api_order_book))
			$sql .= " ORDER BY price $order_desc ";

		$result = db_query_array($sql);
		if (!$count)
			return $result;
		else
			return $result[0]['total'];
		
	}
	
	public static function getRecord($order_id,$order_log_id=false) {
		global $CFG;
		
		if (!$CFG->session_active)
			return false;
		
		$order_id = preg_replace("/[^0-9]/", "",$order_id);
		$order_log_id = preg_replace("/[^0-9]/", "",$order_log_id);
		
		if (!($order_id > 0 || $order_log_id > 0))
			return false;
		
		if ($order_id > 0) 
			$sql = "SELECT * FROM orders WHERE id = $order_id AND site_user = ".User::$info['id'];
		else
			$sql = "SELECT orders.*, currencies.currency AS currency_abbr FROM orders LEFT JOIN order_log ON (order_log.id = orders.log_id) LEFT JOIN currencies ON (currencies.id = orders.currency) WHERE order_log.id = $order_log_id AND order_log.site_user = ".User::$info['id'];
		
		$result = db_query_array($sql);
		
		if ($result[0]['id'] > 0) {
			$result[0]['user_id'] = User::$info['id'];
			$result[0]['is_bid'] = ($result[0]['order_type'] ==$CFG->order_type_bid);
		}
		
		return $result[0];
	}
	
	public static function getCurrentBid($currency,$currency_id=false,$absolute=false) {
		global $CFG;

		$currency = preg_replace("/[^a-zA-Z]/", "",$currency);
		$currency_id = preg_replace("/[^0-9]/", "",$currency_id);
		$usd_info = $CFG->currencies['USD'];
		$currency_info = ($currency_id > 0) ? DB::getRecord('currencies',$currency_id,0,1) : $CFG->currencies[strtoupper($currency)];
		$conversion = ($usd_info['id'] == $currency_info['id']) ? ' currencies.usd_ask' : ' (1 / IF(orders.currency = '.$usd_info['id'].','.$currency_info['usd_ask'].', '.$currency_info['usd_ask'].' / currencies.usd_ask))';
		$conversion1 = ($usd_info['id'] == $currency_info['id']) ? ' currencies.usd_ask' : ' (1 / IF(transactions.currency = '.$usd_info['id'].','.$currency_info['usd_ask'].', '.$currency_info['usd_ask'].' / currencies.usd_ask))';
		$conversion2 = ($usd_info['id'] == $currency_info['id']) ? ' currencies1.usd_ask' : ' (1 / IF(transactions.currency1 = '.$usd_info['id'].','.$currency_info['usd_ask'].', '.$currency_info['usd_ask'].' / currencies1.usd_ask))';
		$conversion3 = ($usd_info['id'] == $currency_info['id']) ? ' usd' : ' ROUND((usd /'.$currency_info['usd_ask'].'),2)';
		
		$sql = "SELECT ROUND(".(($CFG->cross_currency_trades) ? "IF(orders.currency = {$currency_info['id']},orders.btc_price,orders.btc_price * ($conversion ".((!$absolute) ? " - ($conversion * {$CFG->currency_conversion_fee})" : '')."))" : 'orders.btc_price').",2) AS fiat_price FROM orders LEFT JOIN currencies ON (orders.currency = currencies.id) WHERE orders.order_type = {$CFG->order_type_bid} AND orders.btc_price > 0 ".((!$CFG->cross_currency_trades) ? "AND orders.currency = {$currency_info['id']}" : false)." ORDER BY fiat_price DESC LIMIT 0,1";
		$result = db_query_array($sql);

		if (!$result) {
			$sql = "SELECT ROUND(".(($CFG->cross_currency_trades) ? "IF(orders.currency = {$currency_info['id']},orders.btc_price,orders.btc_price * ($conversion ".((!$absolute) ? " + ($conversion * {$CFG->currency_conversion_fee})" : '')."))" : 'orders.btc_price').",2) AS fiat_price FROM orders LEFT JOIN currencies ON (orders.currency = currencies.id) WHERE orders.order_type = {$CFG->order_type_ask} AND orders.btc_price > 0 ".((!$CFG->cross_currency_trades) ? "AND orders.currency = {$currency_info['id']}" : false)." ORDER BY fiat_price ASC LIMIT 0,1";
			$result = db_query_array($sql);
			
			if (!$result) {
				$sql = "SELECT ".(($CFG->cross_currency_trades) ? "ROUND((CASE WHEN transactions.currency = {$currency_info['id']} THEN transactions.btc_price WHEN transactions.currency1 = {$currency_info['id']} THEN transactions.orig_btc_price ELSE (transactions.orig_btc_price * $conversion1) END),2)" : 'transactions.btc_price')." AS fiat_price FROM transactions LEFT JOIN currencies ON (transactions.currency = currencies.id) LEFT JOIN currencies currencies1 ON (currencies1.id = transactions.currency1) WHERE 1 ".((!$CFG->cross_currency_trades) ? "AND transactions.currency = {$currency_info['id']}" : '')." ORDER BY transactions.date DESC, transactions.id DESC LIMIT 0,1";
				$result = db_query_array($sql);
				
				if (!$result) {
					$currency_info1 = $CFG->currencies['USD'];
					$sql = "SELECT ROUND((btc_price/{$currency_info['usd_ask']}),2) AS fiat_price, '1' AS no_orders FROM transactions WHERE currency = {$currency_info1['id']} ORDER BY `date` DESC LIMIT 0,1";
					$result = db_query_array($sql);
					
					if (!$result) {
						$sql = 'SELECT '.$conversion3.' AS fiat_price FROM historical_data ORDER BY `date` DESC LIMIT 0,1';
						$result = db_query_array($sql);
					}
				}
			}
		}
		return $result[0]['fiat_price'];
	}
	
	public static function getCurrentAsk($currency,$currency_id=false,$absolute=false) {
		global $CFG;

		$currency = preg_replace("/[^a-zA-Z]/", "",$currency);
		$currency_id = preg_replace("/[^0-9]/", "",$currency_id);
		$usd_info = $CFG->currencies['USD'];
		$currency_info = ($currency_id > 0) ? DB::getRecord('currencies',$currency_id,0,1) : $CFG->currencies[strtoupper($currency)];
		$conversion = ($usd_info['id'] == $currency_info['id']) ? ' currencies.usd_ask' : ' (1 / IF(orders.currency = '.$usd_info['id'].','.$currency_info['usd_ask'].', '.$currency_info['usd_ask'].' / currencies.usd_ask))';
		$conversion1 = ($usd_info['id'] == $currency_info['id']) ? ' currencies.usd_ask' : ' (1 / IF(transactions.currency = '.$usd_info['id'].','.$currency_info['usd_ask'].', '.$currency_info['usd_ask'].' / currencies.usd_ask))';
		$conversion2 = ($usd_info['id'] == $currency_info['id']) ? ' currencies1.usd_ask' : ' (1 / IF(transactions.currency1 = '.$usd_info['id'].','.$currency_info['usd_ask'].', '.$currency_info['usd_ask'].' / currencies1.usd_ask))';
		$conversion3 = ($usd_info['id'] == $currency_info['id']) ? ' usd' : ' ROUND((usd /'.$currency_info['usd_ask'].'),2)';
		
		$sql = "SELECT ROUND(".(($CFG->cross_currency_trades) ? "IF(orders.currency = {$currency_info['id']},orders.btc_price,orders.btc_price * ($conversion ".((!$absolute) ? " + ($conversion * {$CFG->currency_conversion_fee})" : '')."))" : 'orders.btc_price').",2) AS fiat_price FROM orders LEFT JOIN currencies ON (orders.currency = currencies.id) WHERE orders.order_type = {$CFG->order_type_ask} AND orders.btc_price > 0 ".((!$CFG->cross_currency_trades) ? "AND orders.currency = {$currency_info['id']}" : false)." ORDER BY fiat_price ASC LIMIT 0,1";
		$result = db_query_array($sql);
		
		if (!$result) {
			$sql = "SELECT ROUND(".(($CFG->cross_currency_trades) ? "IF(orders.currency = {$currency_info['id']},orders.btc_price,orders.btc_price * ($conversion ".((!$absolute) ? " - ($conversion * {$CFG->currency_conversion_fee})" : '')."))" : 'orders.btc_price').",2) AS fiat_price FROM orders LEFT JOIN currencies ON (orders.currency = currencies.id) WHERE orders.order_type = {$CFG->order_type_bid} AND orders.btc_price > 0 ".((!$CFG->cross_currency_trades) ? "AND orders.currency = {$currency_info['id']}" : false)." ORDER BY fiat_price DESC LIMIT 0,1";
			$result = db_query_array($sql);
			
			if (!$result) {
				$sql = "SELECT ".(($CFG->cross_currency_trades) ? "ROUND((CASE WHEN transactions.currency = {$currency_info['id']} THEN transactions.btc_price WHEN transactions.currency1 = {$currency_info['id']} THEN transactions.orig_btc_price ELSE (transactions.orig_btc_price * $conversion1) END),2)" : 'transactions.btc_price')." AS fiat_price FROM transactions LEFT JOIN currencies ON (transactions.currency = currencies.id) LEFT JOIN currencies currencies1 ON (currencies1.id = transactions.currency1) WHERE 1 ".((!$CFG->cross_currency_trades) ? "AND transactions.currency = {$currency_info['id']}" : '')." ORDER BY transactions.date DESC, transactions.id DESC LIMIT 0,1";
				$result = db_query_array($sql);
			
				if (!$result) {
					$currency_info1 = $CFG->currencies['USD'];
					$sql = "SELECT ROUND((btc_price/{$currency_info['usd_ask']}),2) AS fiat_price FROM transactions WHERE currency = {$currency_info1['id']} ORDER BY `date` DESC LIMIT 0,1";
					$result = db_query_array($sql);
					
					if (!$result) {
						$sql = 'SELECT '.$conversion3.' AS fiat_price FROM historical_data ORDER BY `date` DESC LIMIT 0,1';
						$result = db_query_array($sql); 
					}
				}
			}
		}
		return $result[0]['fiat_price'];
	}
	
	public static function checkOutbidSelf($price,$currency,$find_bids=false) {
		global $CFG;
		
		if (!$CFG->session_active)
			return false;
		
		$currency = preg_replace("/[^a-zA-Z]/", "",$currency);
		$price = preg_replace("/[^0-9\.]/", "",$price);
		$type = ($find_bids) ? $CFG->order_type_bid : $CFG->order_type_ask;
		
		if (!$price || !$currency)
			return false;
		
		//$usd_field = ($find_bids) ? 'usd_bid' : 'usd_ask';
		$usd_field = 'usd_ask';
		$comparison = (!$find_bids) ? '<=' : '>=';
		$conv_comp = ($find_bids) ? '-' : '+';
		$currency_info = $CFG->currencies[strtoupper($currency)];
		$usd_info = $CFG->currencies['USD'];
		$conversion = ($usd_info['id'] == $currency_info['id']) ? ' currencies.'.$usd_field : ' (1 / IF(orders.currency = '.$usd_info['id'].','.$currency_info[$usd_field].', '.$currency_info[$usd_field].' / currencies.'.$usd_field.'))';
		
		$sql = "SELECT orders.currency, ".(($CFG->cross_currency_trades) ? "ROUND(IF(orders.currency = {$currency_info['id']},orders.btc_price,orders.btc_price * $conversion),2)" : 'orders.btc_price')." AS price FROM orders LEFT JOIN currencies ON (orders.currency = currencies.id) WHERE orders.order_type = $type AND ".(($CFG->cross_currency_trades) ? "ROUND(IF(orders.currency = {$currency_info['id']},orders.btc_price,orders.btc_price * ($conversion)),2)" : 'orders.btc_price')." $comparison $price AND orders.btc_price > 0 ".((!$CFG->cross_currency_trades) ? "AND orders.currency = {$currency_info['id']}" : false)." AND orders.site_user = ".User::$info['id'];
		return db_query_array($sql);
	}
	
	public static function checkOutbidStops($price,$currency) {
		global $CFG;
	
		if (!$CFG->session_active)
			return false;
	
		$currency = preg_replace("/[^a-zA-Z]/", "",$currency);
		$currency_info = $CFG->currencies[strtoupper($currency)];
		$price = preg_replace("/[^0-9\.]/", "",$price);
		$usd_info = $CFG->currencies['USD'];
		$conversion = ($usd_info['id'] == $currency_info['id']) ? ' currencies.usd_ask' : ' (1 / IF(orders.currency = '.$usd_info['id'].','.$currency_info['usd_ask'].', '.$currency_info['usd_ask'].' / currencies.usd_ask))';
		
		if (!$price || !$currency)
			return false;
	
		$currency_info = $CFG->currencies[strtoupper($currency)];
	
		$sql = "SELECT orders.currency, ".(($CFG->cross_currency_trades) ? "ROUND(IF(orders.currency = {$currency_info['id']},orders.stop_price,orders.stop_price * $conversion),2)" : 'orders.btc_price')." AS price FROM orders LEFT JOIN currencies ON (orders.currency = currencies.id) WHERE orders.order_type = {$CFG->order_type_ask} AND ".(($CFG->cross_currency_trades) ? "ROUND(IF(orders.currency = {$currency_info['id']},orders.stop_price,orders.stop_price * $conversion),2)" : 'orders.stop_price')." >= $price AND orders.stop_price > 0 ".((!$CFG->cross_currency_trades) ? "AND orders.currency = {$currency_info['id']}" : false)." AND orders.site_user = ".User::$info['id'];
		return db_query_array($sql);
	}
	
	public static function checkStopsOverBid($stop_price,$currency) {
		global $CFG;
	
		if (!$CFG->session_active)
			return false;
	
		$currency = preg_replace("/[^a-zA-Z]/", "",$currency);
		$currency_info = $CFG->currencies[strtoupper($currency)];
		$stop_price = preg_replace("/[^0-9\.]/", "",$stop_price);
		$usd_info = $CFG->currencies['USD'];
		$conversion = ($usd_info['id'] == $currency_info['id']) ? ' currencies.usd_ask' : ' (1 / IF(orders.currency = '.$usd_info['id'].','.$currency_info['usd_ask'].', '.$currency_info['usd_ask'].' / currencies.usd_ask))';
		
		if (!$stop_price || !$currency)
			return false;
	
		$currency_info = $CFG->currencies[strtoupper($currency)];
	
		$sql = "SELECT orders.currency, ".(($CFG->cross_currency_trades) ? "ROUND(IF(orders.currency = {$currency_info['id']},orders.btc_price,orders.btc_price * $conversion),2)" : 'orders.btc_price')." AS price FROM orders LEFT JOIN currencies ON (orders.currency = currencies.id) WHERE orders.order_type = {$CFG->order_type_bid} AND ".(($CFG->cross_currency_trades) ? "ROUND(IF(orders.currency = {$currency_info['id']},orders.btc_price,orders.btc_price * $conversion),2)" : 'orders.btc_price')." <= $stop_price AND orders.btc_price > 0 ".((!$CFG->cross_currency_trades) ? "AND orders.currency = {$currency_info['id']}" : false)." AND orders.site_user = ".User::$info['id'];
		return db_query_array($sql);
	}
	
	private static function triggerStops($max_price,$min_price,$currency,$maker_is_sell=false,$abs_bid=false,$abs_ask=false,$currency_max=false,$currency_min=false) {
		global $CFG;
		
		$currency = preg_replace("/[^a-zA-Z]/", "",$currency);
		$max_price = preg_replace("/[^0-9\.]/", "",$max_price);
		$min_price = preg_replace("/[^0-9\.]/", "",$min_price);
		
		if (!($max_price && $min_price) || !$currency)
			return false;
		
		$currency_info = $CFG->currencies[strtoupper($currency)];
		$usd_info = $CFG->currencies['USD'];
		$conversion = ($usd_info['id'] == $currency_info['id']) ? ' (1/currencies.usd_ask)' : ' (IF(orders.currency = '.$usd_info['id'].','.$currency_info['usd_ask'].', '.$currency_info['usd_ask'].' / currencies.usd_ask))';
		
		$currency_max_str = '';
		if ($currency_max) {
			$currency_max_str = '(CASE orders.currency ';
			foreach ($currency_max as $curr_id => $price) {
				$currency_max_str .= " WHEN $curr_id THEN $price ";
			}
			$currency_max_str .= ' END)';
		}
		
		$currency_min_str = '';
		if ($currency_min) {
			$currency_min_str = '(CASE orders.currency ';
			foreach ($currency_min as $curr_id => $price) {
				$currency_min_str .= " WHEN $curr_id THEN $price ";
			}
			$currency_min_str .= ' END)';
		}
		
		/*
		if ($maker_is_sell)
			$price1 = ($price <= $abs_ask || !$abs_ask) ? $price : $abs_ask;
		else
			$price1 = ($price >= $abs_bid || !$abs_bid) ? $price : $abs_bid;
		*/
		
		//$price_str = "ROUND(IF(orders.currency = {$currency_info['id']},$price, $price1 * $conversion),2)";
		
		if ($currency_min_str)
			$price_str = "IF($currency_min_str < ROUND(IF(orders.currency = {$currency_info['id']},$min_price, $min_price * $conversion),2), $currency_min_str, ROUND(IF(orders.currency = {$currency_info['id']},$min_price, $min_price * $conversion),2))";
		else
			$price_str = "ROUND(IF(orders.currency = {$currency_info['id']},$min_price, $min_price * $conversion),2)";
		
		if ($currency_max_str)
			$price_str1 = "IF($currency_max_str > ROUND(IF(orders.currency = {$currency_info['id']},$max_price, $max_price * $conversion),2), $currency_max_str, ROUND(IF(orders.currency = {$currency_info['id']},$max_price, $max_price * $conversion),2))";
		else
			$price_str1 = "ROUND(IF(orders.currency = {$currency_info['id']},$max_price, $max_price * $conversion),2)";

		
		$sql = "UPDATE orders 
				LEFT JOIN currencies ON (orders.currency = currencies.id) 
				LEFT JOIN order_log ON (orders.log_id = order_log.id)
				LEFT JOIN (SELECT btc_price, order_type, currency FROM orders WHERE order_type = {$CFG->order_type_bid} ORDER BY btc_price DESC LIMIT 0,1) AS max_bid ON (orders.currency = max_bid.currency)
				LEFT JOIN (SELECT btc_price, order_type, currency FROM orders WHERE order_type = {$CFG->order_type_ask} ORDER BY btc_price DESC LIMIT 0,1) AS max_ask ON (orders.currency = max_ask.currency)
				SET orders.market_price = 'Y', orders.btc_price = IF(orders.btc_price > 0,orders.btc_price,orders.stop_price), orders.stop_price = '', order_log.market_price = 'Y', order_log.stop_price = ''
				WHERE ((orders.stop_price >= IF($price_str < max_bid.btc_price,max_bid.btc_price,$price_str) AND orders.order_type = {$CFG->order_type_ask}) OR (orders.stop_price <= IF($price_str1 > max_ask.btc_price,max_ask.btc_price,$price_str1) AND orders.order_type = {$CFG->order_type_bid}))
				AND orders.stop_price > 0
				".((!$CFG->cross_currency_trades) ? "AND orders.currency = {$currency_info['id']}" : false);

		return db_query($sql);
	}
	
	public static function getCompatible($type,$price,$currency,$for_update=false,$market_price=false,$executed_orders=false,$compare_with_conv_fees=false) {
		global $CFG;
		
		if (!$CFG->session_active)
			return false;
		
		$currency = preg_replace("/[^a-zA-Z]/", "",$currency);
		$price = preg_replace("/[^0-9\.]/", "",$price);
		$type = preg_replace("/[^0-9]/", "",$type);
		
		if (!$type || !$price || !$currency)
			return false;
		
		$currency_info = $CFG->currencies[strtoupper($currency)];
		$comparison = ($type == $CFG->order_type_ask) ? '<=' : '>=';
		//$usd_field = ($type == $CFG->order_type_bid) ? 'usd_bid' : 'usd_ask';
		$usd_field = 'usd_ask';
		$conv_comp = ($type == $CFG->order_type_bid) ? '-' : '+';
		$conv_comp1 = ($type == $CFG->order_type_ask) ? '-' : '+';
		$order_asc = ($type == $CFG->order_type_ask) ? 'ASC' : 'DESC';
		$usd_info = $CFG->currencies['USD'];
		$conversion = ($usd_info['id'] == $currency_info['id']) ? ' currencies.'.$usd_field : ' (1 / IF(orders.currency = '.$usd_info['id'].','.$currency_info[$usd_field].', '.$currency_info[$usd_field].' / currencies.'.$usd_field.'))';
		$conversion1 = ($usd_info['id'] == $currency_info['id']) ? ' (1/currencies.'.$usd_field.')' : ' ('.$currency_info[$usd_field].' / IF(orders.currency = '.$usd_info['id'].',1,currencies.'.$usd_field.'))';
		$executed_sql = '';
		$currency_cols = '';
		
		if ($executed_orders) {
			foreach ($executed_orders as $executed_id) {
				$executed_sql .= ' AND orders.id != '.$executed_id.' '; 
			}
		}
		
		if ($CFG->cross_currency_trades) {
			$sql = "DESCRIBE site_users";
			$result = db_query_array($sql);
			if ($result) {
				$currency_cols .= 'CASE currencies.currency ';
				foreach ($result as $row) {
					if (array_key_exists(strtoupper($row['Field']),$CFG->currencies) && $row['Field'] != 'BTC') {
						$currency_cols .= " WHEN '".strtoupper($row['Field'])."' THEN site_users.".$row['Field'].' ';
					}
				}
				$currency_cols .= ' END';
			}
		}

		$sql = "SELECT orders.id, IF(orders.market_price = 'Y',$price,".(($CFG->cross_currency_trades) ? "IF(orders.currency = {$currency_info['id']},orders.btc_price,orders.btc_price * ($conversion $conv_comp ($conversion * {$CFG->currency_conversion_fee})))" : 'orders.btc_price').") AS fiat_price, orders.btc AS btc_outstanding, orders.site_user AS site_user, fee_schedule.fee AS fee, fee_schedule.fee1 AS fee1 ,site_users.btc AS btc_balance, (IFNULL((SELECT SUM(orders1.fiat + (orders1.fiat * (fee * 0.01))) FROM orders orders1 WHERE orders1.order_type = {$CFG->order_type_bid} AND orders1.currency = orders.currency AND orders1.site_user = orders.site_user),0) + IFNULL((SELECT SUM(amount) FROM requests WHERE requests.currency != {$CFG->btc_currency_id} AND requests.currency = orders.currency AND requests.site_user = orders.site_user),0)) AS fiat_on_hold, (IFNULL((SELECT SUM(orders1.btc) FROM orders orders1 WHERE orders1.order_type = {$CFG->order_type_ask} AND orders1.site_user = orders.site_user),0) + IFNULL((SELECT SUM(amount) FROM requests WHERE requests.currency = {$CFG->btc_currency_id} AND requests.currency = orders.currency AND requests.site_user = orders.site_user),0)) AS btc_on_hold, orders.log_id AS log_id, orders.currency AS currency_id, currencies.currency AS currency_abbr, IF(orders.market_price = 'Y',".(($CFG->cross_currency_trades) ? "IF(orders.currency = {$currency_info['id']},$price,$price * ($conversion1 $conv_comp1 ($conversion1 * {$CFG->currency_conversion_fee})))" : $price).",orders.btc_price) AS orig_btc_price, (orders.btc_price * $conversion) AS real_market_price ".(($CFG->cross_currency_trades) ? ", IF(orders.currency = {$currency_info['id']},0,1 * ($conversion $conv_comp ($conversion * {$CFG->currency_conversion_fee}))) AS conversion_factor, IF(orders.currency = {$currency_info['id']},0,1 * $conversion) AS orig_conversion_factor, IF(orders.currency = {$currency_info['id']},site_users.{$currency},{$currency_cols}) AS fiat_balance" : ", site_users.{$currency} AS fiat_balance").", orders.stop_price AS stop_price
				FROM orders
				LEFT JOIN site_users ON (orders.site_user = site_users.id )
				LEFT JOIN currencies ON (orders.currency = currencies.id)
				LEFT JOIN fee_schedule ON (site_users.fee_schedule = fee_schedule.id )
				WHERE orders.order_type = $type
				".((!$market_price) ? " AND (".(($CFG->cross_currency_trades) ? "ROUND(IF(orders.currency = {$currency_info['id']},orders.btc_price,orders.btc_price * ".((!$compare_with_conv_fees) ? $conversion : "($conversion $conv_comp ($conversion * {$CFG->currency_conversion_fee}))").")" : 'orders.btc_price').",2) $comparison $price OR orders.market_price = 'Y') " : false)."
				".((!$CFG->cross_currency_trades) ? "AND orders.currency = {$currency_info['id']}" : false)."
				$executed_sql
				AND orders.btc_price > 0
				ORDER BY fiat_price $order_asc, orders.id ASC";
	
		if ($for_update)
			$sql .= ' FOR UPDATE';

		return db_query_array($sql);
	}
	
	public static function executeOrder($buy,$price,$amount,$currency1,$fee,$market_price,$edit_id=0,$this_user_id=0,$external_transaction=false,$stop_price=false,$use_maker_fee=false,$verbose=false) {
		global $CFG;
		
		if (!$CFG->session_active)
			return false;
		
		$this_user_id = ($this_user_id > 0) ? $this_user_id : User::$info['id'];
		$this_user_id = preg_replace("/[^0-9]/", "",$this_user_id);
		$amount = preg_replace("/[^0-9\.]/", "",$amount);
		$orig_amount = $amount;
		$price = preg_replace("/[^0-9\.]/", "",$price);
		$stop_price = preg_replace("/[^0-9\.]/", "",$stop_price);
		$currency1 = preg_replace("/[^a-zA-Z]/", "",$currency1);
		//$fee = preg_replace("/[^0-9\.]/", "",$fee);
		$edit_id = preg_replace("/[^0-9]/", "",$edit_id);
		$bid = self::getCurrentBid($currency1);
		$ask = self::getCurrentAsk($currency1);
		$bid = ($bid > $ask) ? $ask : $bid;
		$price = ($market_price) ? (($buy) ? $ask : $bid) : $price;
		
		if (!$external_transaction)
			db_start_transaction();
		
		if ($edit_id > 0) {
			$orig_order = DB::getRecord('orders',$edit_id,0,1,false,false,false,1);
			if ($orig_order['site_user'] != $this_user_id || !$orig_order)
				return false;
			
			$edit_currency = DB::getRecord('currencies',$orig_order['currency'],0,1);
			$currency1 = strtolower($edit_currency['currency']);
		}
		
		$currency_info = $CFG->currencies[strtoupper($currency1)];
		$usd_info = $CFG->currencies['USD'];
		$transactions = 0;
		$new_order = 0;
		$edit_order = 0;
		$status = Status::get(1);
		$user_info = DB::getRecord('site_users',$this_user_id,0,1,false,false,false,1);
		$this_btc_balance = $user_info['btc'];
		$this_fiat_balance = $user_info[strtolower($currency1)];
		
		$comp_btc_balance = false;
		$comp_btc_on_hold = false;
		$comp_btc_on_hold_prev = false;
		$comp_fiat_balance = false;
		$comp_fiat_on_hold = false;
		$comp_fiat_on_hold_prev = false;
		$currency_max = false;
		$currency_max_str = false;
		$currency_min = false;
		$currency_min_str = false;
		$compatible = false;
		$trans_total = 0;
		$this_funds_finished = false;
		$hidden_executions = false;
		$max_price = 0;
		$min_price = 0;
		$executed_orders = array();
		$executed_prices = array();
		$executed_orig_prices = false;
		
		$on_hold = User::getOnHold(1,$user_info['id']);
		if (!empty($on_hold['BTC']['total']))
			$this_btc_on_hold = ($edit_id > 0 && !$buy) ? $on_hold['BTC']['total'] - $amount : $on_hold['BTC']['total'];
		else
			$this_btc_on_hold = 0;
		
		if (!empty($on_hold[strtoupper($currency1)]['total']))
			$this_fiat_on_hold = ($edit_id > 0 && $buy) ? $on_hold[strtoupper($currency1)]['total'] - (($amount * $orig_order['btc_price']) + (($amount * $orig_order['btc_price']) * ($fee * 0.01))) : $on_hold[strtoupper($currency1)]['total'];
		else 
			$this_fiat_on_hold = 0;
			
		$user_fee = DB::getRecord('fee_schedule',$user_info['fee_schedule'],0,1);
		$fee = (!$use_maker_fee) ? $user_fee['fee'] : $user_fee['fee1'];
		
		if ($status['trading_status'] == 'suspended')
			return false;
		
		if (!($edit_id > 0))
			$order_log_id = db_insert('order_log',array('date'=>date('Y-m-d H:i:s'),'order_type'=>(($buy) ? $CFG->order_type_bid : $CFG->order_type_ask),'site_user'=>$user_info['id'],'btc'=>$amount,'fiat'=>$amount*$price,'currency'=>$currency_info['id'],'btc_price'=>$price,'market_price'=>(($market_price) ? 'Y' : 'N'),'stop_price'=>$stop_price,'status'=>'ACTIVE'));
		else {
			if (!$external_transaction) {
				$order_log_id = db_insert('order_log',array('date'=>date('Y-m-d H:i:s'),'order_type'=>(($buy) ? $CFG->order_type_bid : $CFG->order_type_ask),'site_user'=>$user_info['id'],'btc'=>$amount,'fiat'=>$amount*$price,'currency'=>$currency_info['id'],'btc_price'=>$price,'market_price'=>(($market_price) ? 'Y' : 'N'),'p_id'=>$orig_order['log_id'],'stop_price'=>$stop_price,'status'=>'ACTIVE'));
				db_update('order_log',$orig_order['log_id'],array('status'=>'REPLACED','btc_remaining'=>$orig_order['btc']));
			}
			else
				$order_log_id = $orig_order['log_id'];
		}
	
		if ($buy) {			
			if ($price != $stop_price) {
				$compatible = self::getCompatible($CFG->order_type_ask,$price,$currency1,1,$market_price,false,$use_maker_fee);
				$no_compatible = (!$compatible);
				$compatible = (is_array($compatible)) ? new ArrayIterator($compatible) : false;
				$compatible[] = array('continue'=>1);
				//$btc_commision = 0;
				$fiat_commision = false;
				$c = count($compatible);
				$i = 1;
			}
			
			if ($compatible) {
				foreach ($compatible as $comp_order) {
					if (!empty($comp_order['real_market_price']) && round($comp_order['real_market_price'],2,PHP_ROUND_HALF_UP) <= $price && round($comp_order['fiat_price'],2,PHP_ROUND_HALF_UP) > $price && !$market_price) {
						$hidden_executions[] = $comp_order;
						continue;
					}
					
					if (!($amount > 0) || !(($this_fiat_balance - $this_fiat_on_hold) > 0)) {
						$triggered = self::triggerStops($max_price,$min_price,$currency1,1,$bid,$ask,$currency_max,$currency_min);
						break;
					}
					elseif ($i == $c && $max_price > 0) {
						$triggered = self::triggerStops($max_price,$min_price,$currency1,1,$bid,$ask,$currency_max,$currency_min);
						if ($triggered > 0) {
							$triggered_rows = self::getCompatible($CFG->order_type_ask,$max_price,$currency1,1,$market_price,$executed_orders);
							if ($triggered_rows) {
								foreach ($triggered_rows as $triggered_row) {
									$compatible->append($triggered_row);
								}
							}
						}
					}
					
					if (!empty($comp_order['continue']) || $comp_order['site_user'] == $user_info['id']) {
						$i++;
						continue;
					}
					
					if (is_array($comp_btc_balance)) $comp_order['btc_balance'] = (array_key_exists($comp_order['site_user'],$comp_btc_balance)) ? $comp_btc_balance[$comp_order['site_user']] : $comp_order['btc_balance'];
					if (is_array($comp_fiat_balance)) $comp_order['fiat_balance'] = (array_key_exists($comp_order['site_user'],$comp_fiat_balance)) ? $comp_fiat_balance[$comp_order['site_user']] : $comp_order['fiat_balance'];
					$comp_btc_on_hold_prev[$comp_order['site_user']] = $comp_btc_on_hold[$comp_order['site_user']];
					if (is_array($comp_btc_on_hold)) $comp_btc_on_hold[$comp_order['site_user']] = (array_key_exists($comp_order['site_user'],$comp_btc_on_hold)) ? $comp_btc_on_hold[$comp_order['site_user']] - $comp_order['btc_outstanding'] : $comp_order['btc_on_hold'] - $comp_order['btc_outstanding'];
					
					$max_amount = ((($this_fiat_balance - $this_fiat_on_hold) / $comp_order['fiat_price']) > ($amount + (($fee * 0.01) * $amount))) ? $amount : (($this_fiat_balance - $this_fiat_on_hold) / $comp_order['fiat_price']) - (($fee * 0.01) * (($this_fiat_balance - $this_fiat_on_hold) / $comp_order['fiat_price']));
					$max_comp_amount = (($comp_order['btc_balance'] - $comp_btc_on_hold[$comp_order['site_user']]) > $comp_order['btc_outstanding']) ? $comp_order['btc_outstanding'] : $comp_order['btc_balance'] - $comp_btc_on_hold[$comp_order['site_user']];
					$this_funds_finished = ($max_amount < $amount);
					$comp_funds_finished = ($max_comp_amount < $comp_order['btc_outstanding']);
					
					if (!($max_amount > 0) || !($max_comp_amount > 0)) {
						$comp_btc_on_hold[$comp_order['site_user']] = $comp_btc_on_hold_prev[$comp_order['site_user']];
						$i++;
						continue;
					}
					
					if ($max_comp_amount >= $max_amount) {
						$trans_amount = $max_amount;
						$comp_order_outstanding = $comp_order['btc_outstanding'] - $max_amount;
						$amount = $amount - $max_amount;
					}
					else {
						$trans_amount = $max_comp_amount;
						$amount = $amount - $trans_amount;
						$comp_order_outstanding = $comp_order['btc_outstanding'] - $max_comp_amount;
					}
				
					$this_fee = ($fee * 0.01) * $trans_amount;
					$comp_order_fee = ($comp_order['fee1'] * 0.01) * $trans_amount;
					$this_conversion_fee = ($currency_info['id'] != $comp_order['currency_id']) ? ($comp_order['fiat_price'] * $trans_amount) - ($comp_order['orig_btc_price'] * $comp_order['orig_conversion_factor'] * $trans_amount) :  0;
					$this_trans_amount_net = $trans_amount + $this_fee;
					$comp_order_trans_amount_net = $trans_amount - $comp_order_fee;
					$comp_btc_balance[$comp_order['site_user']] = $comp_order['btc_balance'] - $trans_amount;
					$comp_fiat_balance[$comp_order['site_user']] = $comp_order['fiat_balance'] + ($comp_order['orig_btc_price'] * $comp_order_trans_amount_net);
					//$btc_commision += $this_fee;
					
					if (!empty($fiat_commision[strtolower($currency_info['currency'])]))
						$fiat_commision[strtolower($currency_info['currency'])] += $this_fee * $comp_order['fiat_price'];
					else
						$fiat_commision[strtolower($currency_info['currency'])] = $this_fee * $comp_order['fiat_price'];
					
					if (!empty($fiat_commision[strtolower($comp_order['currency_abbr'])]))
						$fiat_commision[strtolower($comp_order['currency_abbr'])] += $comp_order_fee * $comp_order['orig_btc_price'];
					else
						$fiat_commision[strtolower($comp_order['currency_abbr'])] = $comp_order_fee * $comp_order['orig_btc_price'];
					
					$this_prev_btc = $this_btc_balance;
					$this_prev_fiat = $this_fiat_balance;
					$this_btc_balance += $trans_amount;
					$this_fiat_balance -= $this_trans_amount_net * $comp_order['fiat_price'];
					$trans_total += $trans_amount;
					$max_price = ($comp_order['fiat_price'] > $max_price) ? $comp_order['fiat_price'] : $max_price;
					$min_price = ($comp_order['fiat_price'] < $min_price || !($min_price > 0)) ? $comp_order['fiat_price'] : $min_price;
					
					if ($currency_info['id'] != $comp_order['currency_id']) {
						$currency_max[$comp_order['currency_id']] = ($comp_order['orig_btc_price'] > $currency_max[$comp_order['currency_id']]) ? $comp_order['orig_btc_price'] : $currency_max[$comp_order['currency_id']];
						$currency_min[$comp_order['currency_id']] = ($comp_order['orig_btc_price'] < $currency_min[$comp_order['currency_id']] || !($currency_min[$comp_order['currency_id']] > 0)) ? $comp_order['orig_btc_price'] : $currency_min[$comp_order['currency_id']];
					}
					
					$transaction_id = db_insert('transactions',array('date'=>date('Y-m-d H:i:s'),'site_user'=>$user_info['id'],'transaction_type'=>$CFG->transactions_buy_id,'site_user1'=>$comp_order['site_user'],'transaction_type1'=>$CFG->transactions_sell_id,'btc'=>$trans_amount,'btc_price'=>$comp_order['fiat_price'],'fiat'=>($comp_order['fiat_price'] * $trans_amount),'currency'=>$currency_info['id'],'currency1'=>$comp_order['currency_id'],'fee'=>$this_fee,'fee1'=>$comp_order_fee,'btc_net'=>$this_trans_amount_net,'btc_net1'=>$comp_order_trans_amount_net,'btc_before'=>$this_prev_btc,'btc_after'=>$this_btc_balance,'fiat_before'=>$this_prev_fiat,'fiat_after'=>$this_fiat_balance,'btc_before1'=>$comp_order['btc_balance'],'btc_after1'=>$comp_btc_balance[$comp_order['site_user']],'fiat_before1'=>$comp_order['fiat_balance'],'fiat_after1'=>$comp_fiat_balance[$comp_order['site_user']],'log_id'=>$order_log_id,'log_id1'=>$comp_order['log_id'],'fee_level'=>$fee,'fee_level1'=>$comp_order['fee'],'conversion_fee'=>$this_conversion_fee,'orig_btc_price'=>$comp_order['orig_btc_price'],'bid_at_transaction'=>$bid,'ask_at_transaction'=>$ask));
					$executed_orders[] = $comp_order['id'];
					$executed_prices[] = array('price'=>$comp_order['fiat_price'],'amount'=>$trans_amount);
					$executed_orig_prices[$comp_order['id']] = array('price'=>$comp_order['orig_btc_price'],'amount'=>$trans_amount);
					++$transactions;
					
					if ($currency_info['id'] != $comp_order['currency_id'])
						db_update('transactions',$transaction_id,array('conversion'=>'Y','convert_amount'=>($comp_order['fiat_price'] * $trans_amount),'convert_rate_given'=>$comp_order['conversion_factor'],'convert_system_rate'=>$comp_order['orig_conversion_factor'],'convert_from_currency'=>$currency_info['id'],'convert_to_currency'=>$comp_order['currency_id']));
					
					if (round($comp_order_outstanding,8,PHP_ROUND_HALF_UP) > 0) {
						if (!$comp_funds_finished)
							db_update('orders',$comp_order['id'],array('btc_price'=>$comp_order['orig_btc_price'],'btc'=>$comp_order_outstanding,'fiat'=>($comp_order['orig_btc_price'] * $comp_order_outstanding)));
						else
							self::cancelOrder($comp_order['id'],$comp_order_outstanding,$comp_order['site_user']);
					}
					else {
						self::setStatus($comp_order['id'],'FILLED');
						db_delete('orders',$comp_order['id']);
					}
	
					db_update('site_users',$comp_order['site_user'],array('btc'=>$comp_btc_balance[$comp_order['site_user']],strtolower($comp_order['currency_abbr'])=>$comp_fiat_balance[$comp_order['site_user']]));
					$i++;
				}
			}
	
			if ($trans_total > 0) {
				db_update('site_users',$user_info['id'],array('btc'=>$this_btc_balance,$currency1=>$this_fiat_balance));
				if ($fiat_commision) {
					$fiat_commission_array = array();
					foreach ($fiat_commision as $fiat_com_curr => $fiat_com_amount) {
						$fiat_commission_array[$fiat_com_curr.'_escrow'] = $status[$fiat_com_curr.'_escrow']+$fiat_com_amount;
					}
					db_update('status',1,$fiat_commission_array);
				}
				//db_update('status',1,array('btc_escrow'=>($status['btc_escrow']+$btc_commision),strtolower($currency_info['currency']).'_escrow'=>($status[strtolower($currency_info['currency']).'_escrow']+$fiat_commision)));
			}

			if (round($amount,8,PHP_ROUND_HALF_UP) > 0) {
				if ($edit_id > 0) {
					if (!$this_funds_finished) {
						if (!($no_compatible && !empty($CFG->in_cron))) {
							db_update('orders',$edit_id,array('btc'=>$amount,'fiat'=>$amount*$price,'currency'=>$currency_info['id'],'btc_price'=>(($price != $stop_price) ? $price : 0),'market_price'=>(($market_price) ? 'Y' : 'N'),'log_id'=>$order_log_id,'stop_price'=>$stop_price));
							$edit_order = 1;
						}
						$order_status = 'ACTIVE';
					}
					else {
						self::cancelOrder($edit_id,$amount,$this_user_id);
						$order_status = 'OUT_OF_FUNDS';
					}
				}
				else {
					if (!$this_funds_finished) {
						db_insert('orders',array('date'=>date('Y-m-d H:i:s'),'order_type'=>$CFG->order_type_bid,'site_user'=>$user_info['id'],'btc'=>$amount,'fiat'=>$amount*$price,'currency'=>$currency_info['id'],'btc_price'=>(($price != $stop_price) ? (($market_price && $max_price > 0) ? $max_price : $price) : 0),'market_price'=>(($market_price) ? 'Y' : 'N'),'log_id'=>$order_log_id,'stop_price'=>$stop_price));
						$new_order = ($stop_price != $price && $stop_price > 0) ? 2 : 1;
						$order_status = 'ACTIVE';
					}
					else {
						self::cancelOrder(false,$amount,$this_user_id);
						$order_status = 'OUT_OF_FUNDS';
					}
				}
			}
			elseif ($edit_id > 0) {
				self::setStatus($edit_id,'FILLED');
				db_delete('orders',$edit_id);
				$order_status = 'FILLED';
			}
			else {
				self::setStatus(false,'FILLED',$order_log_id);
				$order_status = 'FILLED';
			}
				
			db_insert('history',array('date'=>date('Y-m-d H:i:s'),'ip'=>$CFG->client_ip,'history_action'=>$CFG->history_buy_id,'site_user'=>$user_info['id'],'order_id'=>$order_log_id));
		}
		else {
			if ($price != $stop_price) {
				$compatible = self::getCompatible($CFG->order_type_bid,$price,$currency1,1,$market_price,false,$use_maker_fee);
				$no_compatible = (!$compatible);
				$compatible = (is_array($compatible)) ? new ArrayIterator($compatible) : false;
				$compatible[] = array('continue'=>1);
				//$btc_commision = 0;
				$fiat_commision = false;
				$c = count($compatible);
				$i = 1;
			}
			
			if ($compatible) {
				foreach ($compatible as $comp_order) {
					if (!empty($comp_order['real_market_price']) && round($comp_order['real_market_price'],2,PHP_ROUND_HALF_UP) >= $price && round($comp_order['fiat_price'],2,PHP_ROUND_HALF_UP) < $price && !$market_price) {
						$hidden_executions[] = $comp_order;
						continue;
					}
					
					if (!($amount > 0) || !(($this_btc_balance - $this_btc_on_hold) > 0)) {
						$triggered = self::triggerStops($max_price,$min_price,$currency1,false,$bid,$ask,$currency_max,$currency_min);
						break;
					}
					elseif ($i == $c && $min_price > 0) {
						$triggered = self::triggerStops($max_price,$min_price,$currency1,false,$bid,$ask,$currency_max,$currency_min);
						if ($triggered > 0) {
							$triggered_rows = self::getCompatible($CFG->order_type_bid,$min_price,$currency1,1,$market_price,$executed_orders);
							if ($triggered_rows) {
								foreach ($triggered_rows as $triggered_row) {
									$compatible->append($triggered_row);
								}
							}
						}
					}
					
					if (!empty($comp_order['continue']) || $comp_order['site_user'] == $user_info['id']) {
						$i++;
						continue;
					}
					
					if (is_array($comp_btc_balance)) $comp_order['btc_balance'] = (array_key_exists($comp_order['site_user'],$comp_btc_balance)) ? $comp_btc_balance[$comp_order['site_user']] : $comp_order['btc_balance'];
					if (is_array($comp_fiat_balance)) $comp_order['fiat_balance'] = (array_key_exists($comp_order['site_user'],$comp_fiat_balance)) ? $comp_fiat_balance[$comp_order['site_user']] : $comp_order['fiat_balance'];
					$comp_fiat_on_hold_prev[$comp_order['site_user']] = $comp_fiat_on_hold[$comp_order['site_user']];
					if (is_array($comp_fiat_on_hold)) $comp_fiat_on_hold[$comp_order['site_user']] = (array_key_exists($comp_order['site_user'],$comp_fiat_on_hold)) ? $comp_fiat_on_hold[$comp_order['site_user']] - (($comp_order['btc_outstanding'] * $comp_order['orig_btc_price']) + (($comp_order['fee'] * 0.01) * ($comp_order['btc_outstanding'] * $comp_order['fiat_price']))) : $comp_order['fiat_on_hold'] - (($comp_order['btc_outstanding'] * $comp_order['orig_btc_price']) + (($comp_order['fee'] * 0.01) * ($comp_order['btc_outstanding'] * $comp_order['orig_btc_price'])));
					
					$max_amount = (($this_btc_balance - $this_btc_on_hold) > $amount) ? $amount : $this_btc_balance - $this_btc_on_hold;
					$max_comp_amount = ((($comp_order['fiat_balance'] - $comp_fiat_on_hold[$comp_order['site_user']]) / $comp_order['orig_btc_price']) > ($comp_order['btc_outstanding'] + (($comp_order['fee'] * 0.01) * $comp_order['btc_outstanding']))) ? $comp_order['btc_outstanding'] : (($comp_order['fiat_balance'] - $comp_fiat_on_hold[$comp_order['site_user']]) / $comp_order['orig_btc_price']) - (($comp_order['fee'] * 0.01) * (($comp_order['fiat_balance'] - $comp_fiat_on_hold[$comp_order['site_user']]) / $comp_order['orig_btc_price']));
					$this_funds_finished = ($max_amount < $amount);
					$comp_funds_finished = ($max_comp_amount < $comp_order['btc_outstanding']);
					
					if (!($max_amount > 0) || !($max_comp_amount > 0)) {
						$comp_fiat_on_hold[$comp_order['site_user']] = $comp_fiat_on_hold_prev[$comp_order['site_user']];
						$i++;
						continue;
					}
					
					if ($max_comp_amount >= $max_amount) {
						$trans_amount = $max_amount;
						$comp_order_outstanding = $comp_order['btc_outstanding'] - $amount;
						$amount = $amount - $max_amount;
					}
					else {
						$trans_amount = $max_comp_amount;
						$amount = $amount - $trans_amount;
						$comp_order_outstanding = $comp_order['btc_outstanding'] - $max_comp_amount;
					}
					
					$this_fee = ($fee * 0.01) * $trans_amount;
					$comp_order_fee = ($comp_order['fee1'] * 0.01) * $trans_amount;
					$this_trans_amount_net = $trans_amount - $this_fee;
					$this_conversion_fee = ($currency_info['id'] != $comp_order['currency_id']) ? ($comp_order['orig_btc_price'] * $comp_order['orig_conversion_factor'] * $trans_amount) - ($comp_order['fiat_price'] * $trans_amount) :  0;
					$comp_order_trans_amount_net = $trans_amount + $comp_order_fee;
					$comp_btc_balance[$comp_order['site_user']] = $comp_order['btc_balance'] + $trans_amount;
					$comp_fiat_balance[$comp_order['site_user']] = $comp_order['fiat_balance'] - ($comp_order['orig_btc_price'] * $comp_order_trans_amount_net);
					//$btc_commision += $comp_order_fee;
					
					if (!empty($fiat_commision[strtolower($currency_info['currency'])]))
						$fiat_commision[strtolower($currency_info['currency'])] += $this_fee * $comp_order['fiat_price'];
					else
						$fiat_commision[strtolower($currency_info['currency'])] = $this_fee * $comp_order['fiat_price'];
						
					if (!empty($fiat_commision[strtolower($comp_order['currency_abbr'])]))
						$fiat_commision[strtolower($comp_order['currency_abbr'])] += $comp_order_fee * $comp_order['orig_btc_price'];
					else
						$fiat_commision[strtolower($comp_order['currency_abbr'])] = $comp_order_fee * $comp_order['orig_btc_price'];
						
					
					$this_prev_btc = $this_btc_balance;
					$this_prev_fiat = $this_fiat_balance;
					$this_btc_balance -= $trans_amount;
					$this_fiat_balance += $this_trans_amount_net * $comp_order['fiat_price'];
					$trans_total += $trans_amount;
					$max_price = ($comp_order['fiat_price'] > $max_price) ? $comp_order['fiat_price'] : $max_price;
					$min_price = ($comp_order['fiat_price'] < $min_price || !($min_price > 0)) ? $comp_order['fiat_price'] : $min_price;
					
					if ($currency_info['id'] != $comp_order['currency_id']) {
						$currency_max[$comp_order['currency_id']] = ($comp_order['orig_btc_price'] > $currency_max[$comp_order['currency_id']]) ? $comp_order['orig_btc_price'] : $currency_max[$comp_order['currency_id']];
						$currency_min[$comp_order['currency_id']] = ($comp_order['orig_btc_price'] < $currency_min[$comp_order['currency_id']] || !($currency_min[$comp_order['currency_id']] > 0)) ? $comp_order['orig_btc_price'] : $currency_min[$comp_order['currency_id']];
					}
					
					$transaction_id = db_insert('transactions',array('date'=>date('Y-m-d H:i:s'),'site_user'=>$user_info['id'],'transaction_type'=>$CFG->transactions_sell_id,'site_user1'=>$comp_order['site_user'],'transaction_type1'=>$CFG->transactions_buy_id,'btc'=>$trans_amount,'btc_price'=>$comp_order['fiat_price'],'fiat'=>($comp_order['fiat_price'] * $trans_amount),'currency'=>$currency_info['id'],'currency1'=>$comp_order['currency_id'],'fee'=>$this_fee,'fee1'=>$comp_order_fee,'btc_net'=>$this_trans_amount_net,'btc_net1'=>$comp_order_trans_amount_net,'btc_before'=>$this_prev_btc,'btc_after'=>$this_btc_balance,'fiat_before'=>$this_prev_fiat,'fiat_after'=>$this_fiat_balance,'btc_before1'=>$comp_order['btc_balance'],'btc_after1'=>$comp_btc_balance[$comp_order['site_user']],'fiat_before1'=>$comp_order['fiat_balance'],'fiat_after1'=>$comp_fiat_balance[$comp_order['site_user']],'log_id'=>$order_log_id,'log_id1'=>$comp_order['log_id'],'fee_level'=>$fee,'fee_level1'=>$comp_order['fee'],'conversion_fee'=>$this_conversion_fee,'orig_btc_price'=>$comp_order['orig_btc_price'],'bid_at_transaction'=>$bid,'ask_at_transaction'=>$ask));
					$executed_orders[] = $comp_order['id'];
					$executed_prices[] = array('price'=>$comp_order['fiat_price'],'amount'=>$trans_amount);
					$executed_orig_prices[$comp_order['id']] = array('price'=>$comp_order['orig_btc_price'],'amount'=>$trans_amount);
					++$transactions;
					
					if ($currency_info['id'] != $comp_order['currency_id'])
						db_update('transactions',$transaction_id,array('conversion'=>'Y','convert_amount'=>($comp_order['orig_btc_price'] * $trans_amount),'convert_rate_given'=>$comp_order['conversion_factor'],'convert_system_rate'=>$comp_order['orig_conversion_factor'],'convert_from_currency'=>$comp_order['currency_id'],'convert_to_currency'=>$currency_info['id']));
						
					if (round($comp_order_outstanding,8,PHP_ROUND_HALF_UP) > 0) {
						if (!$comp_funds_finished)
							db_update('orders',$comp_order['id'],array('btc_price'=>$comp_order['orig_btc_price'],'btc'=>$comp_order_outstanding,'fiat'=>($comp_order['orig_btc_price'] * $comp_order_outstanding)));
						else
							self::cancelOrder($comp_order['id'],$comp_order_outstanding,$comp_order['site_user']);
					}
					else {
						self::setStatus($comp_order['id'],'FILLED');
						db_delete('orders',$comp_order['id']);
					}
	
					db_update('site_users',$comp_order['site_user'],array('btc'=>$comp_btc_balance[$comp_order['site_user']],strtolower($comp_order['currency_abbr'])=>$comp_fiat_balance[$comp_order['site_user']]));
					$i++;
				}
			}
	
			if ($trans_total > 0) {
				db_update('site_users',$user_info['id'],array('btc'=>$this_btc_balance,$currency1=>$this_fiat_balance));
				if ($fiat_commision) {
					$fiat_commission_array = array();
					foreach ($fiat_commision as $fiat_com_curr => $fiat_com_amount) {
						$fiat_commission_array[$fiat_com_curr.'_escrow'] = $status[$fiat_com_curr.'_escrow']+$fiat_com_amount;
					}
					db_update('status',1,$fiat_commission_array);
				}
				//db_update('status',1,array('btc_escrow'=>($status['btc_escrow']+$btc_commision),strtolower($currency_info['currency']).'_escrow'=>($status[strtolower($currency_info['currency']).'_escrow']+$fiat_commision)));
			}
			
			if (round($amount,8,PHP_ROUND_HALF_UP) > 0) {
				if ($edit_id > 0) {
					if (!$this_funds_finished) {
						if (!($no_compatible && !empty($CFG->in_cron))) {
							db_update('orders',$edit_id,array('btc'=>$amount,'fiat'=>($amount*$price),'btc_price'=>(($price != $stop_price) ? $price : 0),'market_price'=>(($market_price) ? 'Y' : 'N'),'log_id'=>$order_log_id,'stop_price'=>$stop_price));
							$edit_order = 1;
						}
						$order_status = 'ACTIVE';
					}
					else {
						self::cancelOrder($edit_id,$amount,$this_user_id);
						$order_status = 'OUT_OF_FUNDS';
					}
				}
				else {
					if (!$this_funds_finished) {
						$insert_id = db_insert('orders',array('date'=>date('Y-m-d H:i:s'),'order_type'=>$CFG->order_type_ask,'site_user'=>$user_info['id'],'btc'=>$amount,'fiat'=>($amount*$price),'currency'=>$currency_info['id'],'btc_price'=>(($price != $stop_price) ? (($market_price  && $min_price > 0) ? $min_price : $price) : 0),'market_price'=>(($market_price) ? 'Y' : 'N'),'log_id'=>$order_log_id,'stop_price'=>$stop_price));
						$new_order = ($stop_price != $price && $stop_price > 0) ? 2 : 1;
						$order_status = 'ACTIVE';
					}
					else {
						self::cancelOrder(false,$amount,$this_user_id);
						$order_status = 'OUT_OF_FUNDS';
					}
				}
			}
			elseif ($edit_id > 0) {
				self::setStatus($edit_id,'FILLED');
				db_delete('orders',$edit_id);
				$order_status = 'FILLED';
			}
			else {
				self::setStatus(false,'FILLED',$order_log_id);
				$order_status = 'FILLED';
			}
			
			$client_ip = (!empty($CFG->client_ip)) ? $CFG->client_ip : false;
			db_insert('history',array('date'=>date('Y-m-d H:i:s'),'ip'=>$client_ip,'history_action'=>$CFG->history_sell_id,'site_user'=>$user_info['id'],'order_id'=>$order_log_id));
		}
		
		if ($hidden_executions && !$external_transaction && $amount > 0 && !$this_funds_finished) {
			foreach ($hidden_executions as $comp_order) {
				if ($triggered_rows && $triggered_rows['id'] == $comp_order['id'])
					continue; 
				
				$return = self::executeOrder((!$buy),$comp_order['orig_btc_price'],$comp_order['btc_outstanding'],strtolower($comp_order['currency_abbr']),false,false,$comp_order['id'],$comp_order['site_user'],1,$comp_order['stop_price'],1,1);
				if (!empty($return['order_info']['comp_orig_prices'][($edit_id ? $edit_id : $insert_id)])) {
					$executed_prices[] = $return['order_info']['comp_orig_prices'][($edit_id ? $edit_id : $insert_id)];
					++$transactions;
				}
			}
			
			if ($verbose) {
				$reevaluated_order = DB::getRecord('orders',($edit_id ? $edit_id : $insert_id),0,1);
				if (!$reevaluated_order)
					$order_status = 'FILLED';
				else 
					$amount = $reevaluated_order['btc'];
			}
		}
		
		if (!$external_transaction)
			db_commit();
		
		if ($max_price > 0 && strtolower($currency1) == 'usd')
			db_update('currencies',$CFG->btc_currency_id,array('usd_ask'=>$max_price));
		if ($min_price > 0 && strtolower($currency1) == 'usd')
			db_update('currencies',$CFG->btc_currency_id,array('usd_bid'=>$min_price));
		
		$order_info = false;
		if ($verbose) {
			if ($executed_prices) {
				foreach ($executed_prices as $exec) {
					$exec_amount[] = $exec['amount'];
				}
				$exec_amount_sum = array_sum($exec_amount);
				foreach ($executed_prices as $exec) {
					$avg_exec[] = ($exec['amount'] / $exec_amount_sum) * $exec['price'];
				}
			}

			$order_info = array('id'=>$order_log_id,'side'=>($buy ? 'buy' : 'sell'),'type'=>(($market_price) ? 'market' : (($stop_price > 0) ? 'stop' : 'limit')),'amount'=>$orig_amount,'amount_remaining'=>$amount,'price'=>round($price,8,PHP_ROUND_HALF_UP),'avg_price_executed'=>((count($executed_prices) > 0) ? round(array_sum($avg_exec),2,PHP_ROUND_HALF_UP) : 0),'stop_price'=>$stop_price,'currency'=>strtoupper($currency1),'status'=>$order_status,'replaced'=>($edit_id ? $orig_order['log_id'] : 0),'comp_orig_prices'=>$executed_orig_prices);
		}
		return array('transactions'=>$transactions,'new_order'=>$new_order,'edit_order'=>$edit_order,'executed'=>$executed_orders,'order_info'=>$order_info);
	}
	
	private static function cancelOrder($order_id=false,$outstanding_btc=false,$site_user=false) {
		global $CFG;
		
		if (!$CFG->session_active)
			return false;
		
		$user_info = ($site_user > 0) ? DB::getRecord('site_users',$site_user,0,1) : User::$info;
		$user_info['amount'] = number_format($outstanding_btc,8);
		$CFG->language = $user_info['last_lang'];
		self::setStatus($order_id,'OUT_OF_FUNDS',false,$user_info['amount']);
		db_delete('orders',$order_id);
		
		$email = SiteEmail::getRecord('order-cancelled');
		Email::send($CFG->form_email,$user_info['email'],$email['title'],$CFG->form_email_from,false,$email['content'],$user_info);
	}
	
	private static function setStatus($order_id,$status,$order_log_id=false,$btc_remaining=false) {
		global $CFG;
		
		if (!($order_id > 0) && !($order_log_id > 0))
			return false;
		
		$sql = "UPDATE order_log LEFT JOIN orders ON (order_log.id = orders.log_id) SET order_log.status = '$status' ".(($btc_remaining > 0) ? ", order_log.btc_remaining = $btc_remaining" : '')." WHERE ".(($order_log_id > 0) ? "order_log.id = $order_log_id " : "orders.id = $order_id ");
		db_query($sql);
	}
	
	public static function getStatus($order_log_id=false,$user=false) {
		global $CFG;
		
		if ($user && !$CFG->session_active)
			return false;
		
		$user_id = false;
		if ($user)
			$user_id = User::$info['id'];
		
		if (!($order_log_id > 0) && !($user_id > 0))
			return false;
		
		$sql = "SELECT order_log.id AS id, IF(order_log.order_type = {$CFG->order_type_bid},'buy','sell') AS side, IF(order_log.market_price = 'Y','market',IF(order_log.stop_price > 0,'stop','limit')) AS `type`, order_log.btc AS amount, IF(order_log.status = 'ACTIVE',orders.btc,order_log.btc_remaining) AS amount_remaining, order_log.btc_price AS price, ROUND(SUM(IF(transactions.id IS NOT NULL OR transactions1.id IS NOT NULL,(IF(transactions.id IS NOT NULL,transactions.btc,transactions1.btc)  / (order_log.btc - IF(order_log.status = 'ACTIVE',orders.btc,order_log.btc_remaining))) * IF(transactions.id IS NOT NULL,transactions.btc_price,transactions1.orig_btc_price),0)),2) AS avg_price_executed, order_log.stop_price AS stop_price, LOWER(currencies.currency) AS currency, order_log.status AS status, order_log.p_id AS replaced, IF(order_log.status = 'REPLACED',replacing_order.id,0) AS replaced_by
		FROM order_log 
		LEFT JOIN orders ON (order_log.id = orders.log_id) 
		LEFT JOIN currencies ON (currencies.id = order_log.currency) 
		LEFT JOIN transactions ON (order_log.id = transactions.log_id)
		LEFT JOIN transactions transactions1 ON (order_log.id = transactions1.log_id1)
		LEFT JOIN order_log replacing_order ON (order_log.id = replacing_order.p_id)
		WHERE 1 ";
		
		if ($order_log_id > 0)
			$sql .= " AND order_log.id = $order_log_id ";
		
		if ($user_id > 0)
			$sql .= " AND orders.site_user = $user_id ";
		else
			$sql .= " AND order_log.site_user = ".User::$info['id'].' ';
		
		$sql .= "GROUP BY order_log.id";
		$result = db_query_array($sql);
		
		if ($order_log_id)
			return $result[0];
		else
			return $result;
	}
	
	public static function delete($id) {
		global $CFG;
		
		$id = preg_replace("/[^0-9]/", "",$id);
		
		if (!($id > 0))
			return false;
		
		if (!$CFG->session_active)
			return false;
		
		$del_order = DB::getRecord('orders',$id,0,1);
		if ($del_order['site_user'] != User::$info['id'])
			return false;
		
		self::setStatus(false,'CANCELLED_USER',$del_order['log_id'],$del_order['btc']);
		db_delete('orders',$id);
		return self::getStatus($del_order['log_id']);
	}
	
	public static function deleteAll() {
		global $CFG;
		
		if (!$CFG->session_active)
			return false;
		
		db_start_transaction();
		$sql = 'SELECT log_id FROM orders WHERE site_user = '.User::$info['id'].' FOR UPDATE';
		$result = db_query_array($sql);
		
		if (!$result) {
			db_commit();
			return false;
		}
		
		$orders_info = self::getStatus(false,1);
		if (!$orders_info) {
			db_commit();
			return false;
		}
		
		$sql = 'UPDATE order_log LEFT JOIN orders ON (order_log.id = orders.log_id) SET status = "CANCELLED_USER" WHERE orders.site_user = '.User::$info['id'];
		$updated = db_query($sql);
		
		if ($updated) {
			$sql = 'DELETE FROM orders WHERE site_user = '.User::$info['id'];
			db_query($sql);
			
			foreach ($orders_info as $i => $order) {
				$orders_info[$i]['status'] = 'CANCELLED_USER';
			}
		}
		db_commit();
		return $orders_info;
	}
	
	public static function getBidList($currency=false,$notrades=false,$limit_7=false,$user=false) {
		global $CFG;
		
		$currency1 = preg_replace("/[^a-zA-Z]/", "",$currency);
		$user = ($user) ? ' AND site_user = '.User::$info['id'].' ' : ' AND orders.btc_price > 0 ';
		
		if ($currency1 != 'All')
			$currency_info = $CFG->currencies[strtoupper($currency1)];
		
		if ($limit_7)
			$limit = " LIMIT 0,10";
		elseif (!$notrades)
			$limit = " LIMIT 0,5 ";
		
		$sql = "
		SELECT orders.id AS id, orders.btc AS btc, orders.btc_price AS btc_price, orders.order_type AS type, currencies.fa_symbol AS fa_symbol, orders.stop_price AS stop_price, orders.market_price AS market_price
		FROM orders
		LEFT JOIN currencies ON (currencies.id = orders.currency)
		WHERE 1
		".((is_array($currency_info)) ? " AND orders.currency = {$currency_info['id']} " : false). "
		AND orders.order_type = $CFG->order_type_bid
		$user
		ORDER BY orders.btc_price DESC $limit ";
		//return $sql;
		return db_query_array($sql);
	}
	
	public static function getAskList($currency=false,$notrades=false,$limit_7=false,$user=false) {
		global $CFG;
	
		$currency1 = preg_replace("/[^a-zA-Z]/", "",$currency);
		$user = ($user) ? ' AND site_user = '.User::$info['id'].' ' : ' AND orders.btc_price > 0 ';
		$currency_info = $CFG->currencies[strtoupper($currency1)];
		
		if ($currency1 != 'All')
			$currency_info = $CFG->currencies[strtoupper($currency1)];
		
		if ($limit_7)
			$limit = " LIMIT 0,10";
		elseif (!$notrades)
			$limit = " LIMIT 0,5 ";
	
		$sql = "
		SELECT orders.id AS id, orders.btc AS btc, orders.btc_price AS btc_price, orders.order_type AS type, currencies.fa_symbol AS fa_symbol, orders.stop_price AS stop_price, orders.market_price AS market_price
		FROM orders
		LEFT JOIN currencies ON (currencies.id = orders.currency)
		WHERE 1
		".((is_array($currency_info)) ? " AND orders.currency = {$currency_info['id']} " : false). "
		AND orders.order_type = $CFG->order_type_ask 
		$user
		ORDER BY orders.btc_price ASC $limit ";
		//return $sql;
		return db_query_array($sql);
	}
}