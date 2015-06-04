<?php
class Currencies {
	public static function get() {
		$sql = "SELECT * FROM currencies WHERE is_active = 'Y'";
		$result = db_query_array($sql);
		
		if ($result) {
			foreach ($result as $row) {
				$currencies[$row['currency']] = $row;
				$currencies[$row['id']] = $row;
			}
		}
		ksort($currencies);
		return $currencies;
	}
	
	public static function getRecord($currency_abbr=false,$currency_id=false) {
		if (!$currency_abbr && !$currency_id)
			return false;
		
		$currency_id1 = preg_replace("/[^0-9]/", "",$currency_id);
		$currency_abbr1 = preg_replace("/[^a-zA-Z]/", "",$currency_abbr);

		if ($currency_abbr1)
			return DB::getRecord('currencies',false,$currency_abbr1,0,'currency');
		elseif ($currency_id1 > 0)
			return DB::getRecord('currencies',$currency_id1,false,1);
	}
}