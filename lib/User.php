<?php 
class User {
	public static $info, $on_hold;
	
	public static function setInfo($info) {
		User::$info = $info;
	}
	
	public static function getInfo($session_id=false) {
		global $CFG;
		
		$session_id = preg_replace("/[^0-9]/", "",$session_id);
		
		if (!($session_id > 0) || !$CFG->session_active)
			return false;
	
		$result = db_query_array('SELECT site_users.first_name,site_users.last_name,site_users.country,site_users.email, site_users.default_currency FROM sessions LEFT JOIN site_users ON (sessions.user_id = site_users.id) WHERE sessions.session_id = '.$session_id);
		return $result[0];
	}
	
	public static function verifyLogin() {
		global $CFG;
		
		// IP throttling
		$login_attempts = 0;
		$ip_int = ip2long($CFG->client_ip);
		if ($ip_int) {
			$timeframe = (!empty($CFG->cloudflare_blacklist_timeframe)) ? $CFG->cloudflare_blacklist_timeframe : 15;
			$max_attempts = (!empty($CFG->cloudflare_blacklist_attempts)) ? $CFG->cloudflare_blacklist_attempts : 80;
			
			$sql = 'SELECT IFNULL(SUM(IF(login = "Y",1,0)),0) AS login_attempts, IFNULL(SUM(IF(login = "N",1,0)),0) AS hits, IFNULL(MIN(`timestamp`),NOW()) AS start FROM ip_access_log WHERE `timestamp` > DATE_SUB("'.date('Y-m-d H:i:s').'", INTERVAL '.$timeframe.' MINUTE) AND ip = '.$ip_int;
			$result = db_query_array($sql);
			if ($result) {
				$login_attempts = $result[0]['login_attempts'];
				if ($CFG->cloudflare_blacklist && $result[0]['hits'] > 0) {
					$time_elapsed = time() - strtotime($result[0]['start']);
					$hits_per_minute = $result[0]['hits'] / (($time_elapsed > 60 ? $time_elapsed : 60) / 60);
					
					if ($hits_per_minute >= $max_attempts && $time_elapsed >= 60)
						User::banIP($CFG->client_ip);
				}
			}
			
			db_insert('ip_access_log',array('ip'=>$ip_int,'timestamp'=>date('Y-m-d H:i:s')));
		}

		if (!($CFG->session_id > 0))
			return array('message'=>'not-logged-in','attempts'=>$login_attempts);
		
		if (!User::$info) {
			return array('error'=>'session-not-found','attempts'=>$login_attempts);
		}
		
		if (User::$info['awaiting'] == 'Y') {
			return array('message'=>'awaiting-token','attempts'=>$login_attempts);
		}
		
		$return_values = array(
		'first_name',
		'last_name',
		'fee_schedule',
		'tel',
		'country',
		'country_code',
		'verified_google',
		'verified_authy',
		'using_sms',
		'confirm_withdrawal_email_btc',
		'confirm_withdrawal_2fa_btc',
		'confirm_withdrawal_2fa_bank',
		'confirm_withdrawal_email_bank',
		'notify_deposit_btc',
		'notify_deposit_bank',
		'notify_withdraw_btc',
		'notify_withdraw_bank',
		'no_logins',
		'notify_login',
		'deactivated',
		'locked',
		'default_currency');
		
		$return = array();
		foreach (User::$info as $key => $value) {
			if (in_array($key,$return_values))
				$return[$key] = $value;
		}
		
		if ($return['country_code'] > 0) {
			$s = strlen($return['country_code']);
			$return['country_code'] = str_repeat('x',$s);
		}
		
		if ($return['tel'] > 0) {
			$s = strlen($return['tel']) - 2;
			$return['tel'] = str_repeat('x',$s).substr($return['tel'], -2);
		}
		
		if (User::$info['default_currency'] > 0) {
			$currency = DB::getRecord('currencies',User::$info['default_currency'],0,1);
			$return['default_currency_abbr'] = $currency['currency'];
		}
		
		return array('message'=>'logged-in','info'=>$return);
	}
	
	public static function logOut($session_id=false) {
		if (!($session_id > 0))
			return false;
		
		$session_id = preg_replace("/[^0-9]/", "",$session_id);
		
		return db_delete('sessions',$session_id,'session_id');
	}
	
	public static function getOnHold($for_update=false,$user_id=false) {
		global $CFG;
		
		if (!$CFG->session_active)
			return false;
		
		$user_info = ($user_id > 0) ? DB::getRecord('site_users',$user_id,0,1,false,false,false,$for_update) : User::$info;
		$user_fee = FeeSchedule::getRecord($user_info['fee_schedule']);
		$lock = ($for_update) ? 'FOR UPDATE' : '';
		$on_hold = array();
	
		$sql = " SELECT currencies.currency AS currency, requests.amount AS amount FROM requests LEFT JOIN currencies ON (currencies.id = requests.currency) WHERE requests.site_user = ".$user_info['id']." AND requests.request_type = {$CFG->request_widthdrawal_id} AND (requests.request_status = {$CFG->request_pending_id} OR requests.request_status = {$CFG->request_awaiting_id}) ".$lock;
		$result = db_query_array($sql);
		if ($result) {
			foreach ($result as $row) {
				if (!empty($on_hold[$row['currency']]['withdrawal']))
					$on_hold[$row['currency']]['withdrawal'] += floatval($row['amount']);
				else
					$on_hold[$row['currency']]['withdrawal'] = floatval($row['amount']);
					
				if (!empty($on_hold[$row['currency']]['total']))
					$on_hold[$row['currency']]['total'] += floatval($row['amount']);
				else
					$on_hold[$row['currency']]['total'] = floatval($row['amount']);
			}
		}
	
		$sql = " SELECT currencies.currency AS currency, orders.fiat AS amount, orders.btc AS btc_amount, orders.order_type AS type FROM orders LEFT JOIN currencies ON (currencies.id = orders.currency) WHERE orders.site_user = ".$user_info['id']." ".$lock;
		$result = db_query_array($sql);
		if ($result) {
			foreach ($result as $row) {
				if ($row['type'] == $CFG->order_type_bid) {
					if (!empty($on_hold[$row['currency']]['order']))
						$on_hold[$row['currency']]['order'] += round(floatval($row['amount']) + (floatval($row['amount']) * ($user_fee['fee'] * 0.01)),2,PHP_ROUND_HALF_UP);
					else
						$on_hold[$row['currency']]['order'] = round(floatval($row['amount']) + (floatval($row['amount']) * ($user_fee['fee'] * 0.01)),2,PHP_ROUND_HALF_UP);
					
					if (!empty($on_hold[$row['currency']]['total']))
						$on_hold[$row['currency']]['total'] += round(floatval($row['amount']) + (floatval($row['amount']) * ($user_fee['fee'] * 0.01)),2,PHP_ROUND_HALF_UP);
					else
						$on_hold[$row['currency']]['total'] = round(floatval($row['amount']) + (floatval($row['amount']) * ($user_fee['fee'] * 0.01)),2,PHP_ROUND_HALF_UP);
				}
				else {
					if (!empty($on_hold['BTC']['order']))
						$on_hold['BTC']['order'] += floatval($row['btc_amount']);
					else 
						$on_hold['BTC']['order'] = floatval($row['btc_amount']);
						
					if (!empty($on_hold['BTC']['total']))
						$on_hold['BTC']['total'] += floatval($row['btc_amount']);
					else 
						$on_hold['BTC']['total'] = floatval($row['btc_amount']);
				}
			}
		}
		self::$on_hold = $on_hold;
		return $on_hold;
	}
	
	public static function getAvailable() {
		global $CFG;
		
		if (!$CFG->session_active)
			return false;
	
		self::$on_hold = (is_array(self::$on_hold)) ? self::$on_hold : self::getOnHold();
		if ($CFG->currencies) {
			$on_hold = (!empty(self::$on_hold['BTC']['total'])) ? self::$on_hold['BTC']['total'] : 0;
			$available['BTC'] = User::$info['btc'] - $on_hold;
			$available['BTC'] = ($available['BTC'] < 0.00000001) ? 0 : $available['BTC'];
			foreach ($CFG->currencies as $currency) {
				if (empty(User::$info[strtolower($currency['currency'])]))
					continue;
					
				$on_hold = (!empty(self::$on_hold[$currency['currency']]['total'])) ? self::$on_hold[$currency['currency']]['total'] : 0;
				if (User::$info[strtolower($currency['currency'])] - $on_hold <= 0)
					continue;
	
				$available[$currency['currency']] = round(User::$info[strtolower($currency['currency'])] - $on_hold,2,PHP_ROUND_HALF_UP);
			}
		}
		return $available;
	}
	
	public static function hasCurrencies() {
		global $CFG;
		
		if (!$CFG->session_active)
			return false;
		
		$found = false;
		if (!(User::$info['btc'] > 0)) {
			foreach ($CFG->currencies as $currency => $info) {
				if (User::$info[strtolower($currency)] > 0) {
					$found = true;
					break;
				}
			}
		}
		else
			$found = true;
		
		return $found;
	}
	
	public static function getVolume() {
		global $CFG;
		
		if (!$CFG->session_active)
			return false;
	
		$sql = "SELECT ROUND(SUM(transactions.btc * transactions.btc_price * currencies.usd_ask),2) AS volume FROM transactions
				LEFT JOIN currencies ON (currencies.id = transactions.currency)
				WHERE (site_user = ".User::$info['id']." OR site_user1 = ".User::$info['id'].")
				AND transactions.date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
				LIMIT 0,1";
		$result = db_query_array($sql);
		return $result[0]['volume'];
	}
	
	public static function getNewId() {
		$sql = 'SELECT FLOOR(10000000 + RAND() * 89999999) AS random_num
				FROM site_users
				WHERE "random_num" NOT IN (SELECT user FROM site_users)
				LIMIT 1 ';
		$result = db_query_array($sql);
		
		if (!$result) {
			$sql = 'SELECT FLOOR(10000000 + RAND() * 89999999) AS random_num ';
			$result = db_query_array($sql);
		}
		
		return $result[0]['random_num'];
	}
	
	public static function randomPassword($length = 8) {
		$chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%&*?";
		$password = substr(str_shuffle($chars),0,$length);
		return $password;
	}
	
	public static function sendSMS($authy_id=false) {
		global $CFG;
		
		$authy_id = preg_replace("/[^0-9]/", "",$authy_id);
		
		if (!($CFG->session_active || $CFG->session_locked))
			return false;
		
		$authy_id = ($authy_id > 0) ? $authy_id : User::$info['authy_id'];
		$response = shell_exec('curl "https://api.authy.com/protected/json/sms/'.$authy_id.'?force=true&api_key='.$CFG->authy_api_key.'"');
		$response1 = json_decode($response,true);

		return $response1;
	}
	
	public static function confirmToken($token,$authy_id=false) {
		global $CFG;
		
		if (!($CFG->session_active || $CFG->session_locked))
			return false;
		
		$token1 = preg_replace("/[^0-9]/", "",$token);
		$authy_id1 = preg_replace("/[^0-9]/", "",$authy_id);
		$authy_id = ($authy_id > 0) ? $authy_id : User::$info['authy_id'];
		
		if (!($token1 > 0) || !($authy_id > 0))
			return false;
	
			$authy_id = ($authy_id > 0) ? $authy_id : User::$info['authy_id'];
			$response = shell_exec('curl "https://api.authy.com/protected/json/verify/'.$token.'/'.$authy_id.'?api_key='.$CFG->authy_api_key.'"');
			$response1 = json_decode($response,true);
			
			return $response1;
	}
	
	public static function disableNeverLoggedIn($pass) {
		$pass = preg_replace($CFG->pass_regex, "",$pass);
		if (strlen($pass) < $CFG->pass_min_chars)
			return false;
		
		$pass = Encryption::hash($pass);
		return db_update('site_users',User::$info['id'],array('no_logins'=>'N','pass'=>$pass));
	}
	
	public static function firstLoginPassChange($pass) {
		global $CFG;
		
		$pass = preg_replace($CFG->pass_regex, "",$pass);
		
		if (!$CFG->session_active || strlen($pass) < $CFG->pass_min_chars || User::$info['no_logins'] != 'Y')
			return false;
		
		$pass = Encryption::hash($pass);
		return db_update('site_users',User::$info['id'],array('pass'=>$pass));
	}
	
	public static function userExists($email) {
		$email = preg_replace("/[^0-9a-zA-Z@\.\!#\$%\&\*+_\~\?\-]/", "",$email);
		
		if (!$email)
			return false;
		
		$sql = "SELECT id FROM site_users WHERE email = '$email'";
		$result = db_query_array($sql);
		
		if ($result)
			return $result[0]['id'];
		else
			return false;
	}
	
	public static function resetUser($email) {
		global $CFG;
		
		$email = preg_replace("/[^0-9a-zA-Z@\.\!#\$%\&\*+_\~\?\-]/", "",$email);
		
		if (!$email)
			return false;
		
		$id = self::userExists($email);
		if (!($id > 0))
			return false;
		
		$user = DB::getRecord('site_users',$id,0,1);
		//$new_id = self::getNewId();
		//$user['new_user'] = $new_id;
		$user['new_password'] = self::randomPassword(12);
		$pass1 = Encryption::hash($user['new_password']);

		db_update('site_users',$id,array(/*'user'=>$user['new_user'],*/'pass'=>$pass1,'no_logins'=>'Y'));
		
		$sql = "DELETE FROM sessions WHERE user_id = $id";
		db_query($sql);
		
		$email1 = SiteEmail::getRecord('forgot');
		Email::send($CFG->form_email,$email,$email1['title'],$CFG->form_email_from,false,$email1['content'],$user);
	}
	
	public static function registerNew($info) {
		global $CFG;
		
		if (!is_array($info))
			return false;
		
		$info['email'] = preg_replace("/[^0-9a-zA-Z@\.\!#\$%\&\*+_\~\?\-]/", "",$info['email']);
		$exist_id = self::userExists($info['email']);
		if ($exist_id > 0) {
			$user_info = DB::getRecord('site_users',$exist_id,0,1);
			$email = SiteEmail::getRecord('register-existing');
			Email::send($CFG->form_email,$info['email'],$email['title'],$CFG->form_email_from,false,$email['content'],$user_info);
			return false;
		}

		$new_id = self::getNewId();
		if ($new_id > 0) {
			$sql = 'SELECT id FROM fee_schedule ORDER BY from_usd ASC LIMIT 0,1';
			$result = db_query_array($sql);
			
			$pass1 = self::randomPassword(12);
			$info['first_name'] = preg_replace("/[^\da-z ]/i", "",$info['first_name']);
			$info['last_name'] = preg_replace("/[^\da-z ]/i", "",$info['last_name']);
			$info['country'] = preg_replace("/[^0-9]/", "",$info['country']);
			$info['user'] = $new_id;
			$info['pass'] = Encryption::hash($pass1);
			$info['date'] = date('Y-m-d H:i:s');
			$info['confirm_withdrawal_email_btc'] = 'Y';
			$info['confirm_withdrawal_email_bank'] = 'Y';
			$info['notify_deposit_btc'] = 'Y';
			$info['notify_deposit_bank'] = 'Y';
			$info['notify_withdraw_btc'] = 'Y';
			$info['notify_withdraw_bank'] = 'Y';
			$info['notify_login'] = 'Y';
			$info['no_logins'] = 'Y';
			$info['fee_schedule'] = $result[0]['id'];
			$info['default_currency'] = preg_replace("/[^0-9]/", "",$info['default_currency']);
			unset($info['terms']);
			
			$record_id = db_insert('site_users',$info);
		
			require_once('../lib/easybitcoin.php');
			$bitcoin = new Bitcoin($CFG->bitcoin_username,$CFG->bitcoin_passphrase,$CFG->bitcoin_host,$CFG->bitcoin_port,$CFG->bitcoin_protocol);
			$new_address = $bitcoin->getnewaddress($CFG->bitcoin_accountname);
			db_insert('bitcoin_addresses',array('address'=>$new_address,'site_user'=>$record_id,'date'=>date('Y-m-d H:i:s')));
		
			$info['pass'] = $pass1;
			$email = SiteEmail::getRecord('register');
			Email::send($CFG->form_email,$info['email'],$email['title'],$CFG->form_email_from,false,$email['content'],$info);
		
			if ($CFG->email_notify_new_users) {
				$email = SiteEmail::getRecord('register-notify');
				$info['pass'] = false;
				Email::send($CFG->form_email,$CFG->support_email,$email['title'],$CFG->form_email_from,false,$email['content'],$info);
			}
			return true;
		}
	}
	
	public static function registerAuthy($cell,$country_code) {
		global $CFG;
		
		$cell = preg_replace("/[^0-9]/", "",$cell);
		$country_code = preg_replace("/[^0-9]/", "",$country_code);
		
		if (!$CFG->session_active || User::$info['verified_authy'] == 'Y')
			return false;
		
		$response = shell_exec("
				curl https://api.authy.com/protected/json/users/new?api_key=$CFG->authy_api_key \
				-d user[email]='".User::$info['email']."' \
				-d user[cellphone]='$cell' \
				-d user[country_code]='$country_code'");
		$response1 = json_decode($response,true);
		return $response1;
	}
	
	public static function enableAuthy($cell,$country_code,$authy_id,$using_sms) {
		global $CFG;
		
		$cell = preg_replace("/[^0-9]/", "",$cell);
		$country_code = preg_replace("/[^0-9]/", "",$country_code);
		$authy_id = preg_replace("/[^0-9]/", "",$authy_id);
		
		if (!$CFG->session_active || User::$info['verified_authy'] == 'Y' || User::$info['verified_google'] == 'Y')
			return false;
		
		return db_update('site_users',User::$info['id'],array('tel'=>$cell,'country_code'=>$country_code,'authy_requested'=>'Y','verified_authy'=>'N','authy_id'=>$authy_id,'using_sms'=>$using_sms,'google_2fa_code'=>'','confirm_withdrawal_2fa_btc'=>'Y','confirm_withdrawal_2fa_bank'=>'Y'));
	}
	
	public static function enableGoogle2fa($cell,$country_code) {
		global $CFG;
	
		$cell = preg_replace("/[^0-9]/", "",$cell);
		$country_code = preg_replace("/[^0-9]/", "",$country_code);
	
		if (!$CFG->session_active || User::$info['verified_authy'] == 'Y' || User::$info['verified_google'] == 'Y')
			return false;
	
		$key = Google2FA::generate_secret_key();
		if (!$key)
			return false;
		
		$result = db_update('site_users',User::$info['id'],array('tel'=>$cell,'country_code'=>$country_code,'google_2fa_code'=>$key,'verified_google'=>'N','using_sms'=>'N','authy_id'=>'','confirm_withdrawal_2fa_btc'=>'Y','confirm_withdrawal_2fa_bank'=>'Y'));
		if ($result)
			return $key;
	}
	
	public static function getGoogleSecret() {
		global $CFG;
		
		if (!($CFG->session_active) || User::$info['verified_google'] == 'Y')
			return false;
		
		return array('secret'=>User::$info['google_2fa_code'],'label'=>$CFG->exchange_name);
	}
	
	public static function verifiedAuthy() {
		global $CFG;
	
		if (!($CFG->session_active && $CFG->token_verified && $CFG->email_2fa_verified) || User::$info['verified_google'] == 'Y')
			return false;
	
		return db_update('site_users',User::$info['id'],array('verified_authy'=>'Y'));
	}
	
	public static function verifiedGoogle() {
		global $CFG;
	
		if (!($CFG->session_active && $CFG->email_2fa_verified) || User::$info['verified_authy'] == 'Y')
			return false;
			
		return db_update('site_users',User::$info['id'],array('verified_google'=>'Y'));
	}
	
	public static function disable2fa() {
		global $CFG;
		
		if (!($CFG->session_active && $CFG->token_verified))
			return false;

		return db_update('site_users',User::$info['id'],array('google_2fa_code'=>'','verified_google'=>'N','using_sms'=>'N','authy_id'=>'','verified_authy'=>'N'));
	}
	
	public static function updatePersonalInfo($info) {
		global $CFG;

		if (!($CFG->session_active && ($CFG->token_verified || $CFG->email_2fa_verified)))
			return false;

		if (!is_array($info))
			return false;

		$update['pass'] = preg_replace($CFG->pass_regex, "",$info['pass']);
		$update['first_name'] = preg_replace("/[^\da-z ]/i", "",$info['first_name']);
		$update['last_name'] = preg_replace("/[^\da-z ]/i", "",$info['last_name']);
		$update['country'] = preg_replace("/[^0-9]/", "",$info['country']);
		$update['email'] = preg_replace("/[^0-9a-zA-Z@\.\!#\$%\&\*+_\~\?\-]/", "",$info['email']);
		
		if (!$update['pass'])
			unset($update['pass']);

		if (($update['pass'] && strlen($update['pass']) < $CFG->pass_min_chars) || !$update['first_name'] || !$update['last_name'] || !$update['email'])
			return false;
		
		if ($CFG->session_id) {
		    $sql = "DELETE FROM sessions WHERE user_id = ".User::$info['id']." AND session_id != {$CFG->session_id}";
		    db_query($sql);
		}

		if ($update['pass'])
			$update['pass'] = Encryption::hash($update['pass']);
		
		return db_update('site_users',User::$info['id'],$update);
	}
	
	public static function updateSettings($confirm_withdrawal_2fa_btc1,$confirm_withdrawal_email_btc1,$confirm_withdrawal_2fa_bank1,$confirm_withdrawal_email_bank1,$notify_deposit_btc1,$notify_deposit_bank1,$notify_login1,$notify_withdraw_btc1,$notify_withdraw_bank1) {
		global $CFG;
		
		if (!($CFG->session_active && ($CFG->token_verified || $CFG->email_2fa_verified)))
			return false;
			
		$confirm_withdrawal_2fa_btc2 = ($confirm_withdrawal_2fa_btc1) ? 'Y' : 'N';
		$confirm_withdrawal_email_btc2 = ($confirm_withdrawal_email_btc1) ? 'Y' : 'N';
		$confirm_withdrawal_2fa_bank2 = ($confirm_withdrawal_2fa_bank1) ? 'Y' : 'N';
		$confirm_withdrawal_email_bank2 = ($confirm_withdrawal_email_bank1) ? 'Y' : 'N';
		$notify_deposit_btc2 = ($notify_deposit_btc1) ? 'Y' : 'N';
		$notify_deposit_bank2 = ($notify_deposit_bank1) ? 'Y' : 'N';
		$notify_withdraw_btc2 = ($notify_withdraw_btc1) ? 'Y' : 'N';
		$notify_withdraw_bank2 = ($notify_withdraw_bank1) ? 'Y' : 'N';
		$notify_login2 = ($notify_login1) ? 'Y' : 'N';
			
		return db_update('site_users',User::$info['id'],array('confirm_withdrawal_2fa_btc'=>$confirm_withdrawal_2fa_btc2,'confirm_withdrawal_email_btc'=>$confirm_withdrawal_email_btc2,'confirm_withdrawal_2fa_bank'=>$confirm_withdrawal_2fa_bank2,'confirm_withdrawal_email_bank'=>$confirm_withdrawal_email_bank2,'notify_deposit_btc'=>$notify_deposit_btc2,'notify_deposit_bank'=>$notify_deposit_bank2,'notify_withdraw_btc'=>$notify_withdraw_btc2,'notify_withdraw_bank'=>$notify_withdraw_bank2,'notify_login'=>$notify_login2));
	}
	
	public static function deactivateAccount() {
		global $CFG;
		
		if (!($CFG->session_active && ($CFG->token_verified || $CFG->email_2fa_verified)))
			return false;

		$found = false;
		if (!(User::$info['btc'] > 0)) {
			foreach ($CFG->currencies as $currency => $info) {
				if (User::$info[strtolower($currency)] > 0) {
					$found = true;
					break;
				}
			}
		}
		else
			$found = true;

		if (!$found)
			return db_update('site_users',User::$info['id'],array('deactivated'=>'Y'));
	}
	
	public static function reactivateAccount() {
		global $CFG;

		if (!(($CFG->session_locked || $CFG->session_active) && ($CFG->token_verified || $CFG->email_2fa_verified)))
			return false;
	
		return db_update('site_users',User::$info['id'],array('deactivated'=>'N'));
	}
	
	function lockAccount() {
		global $CFG;
	
		if (!($CFG->session_active && ($CFG->token_verified || $CFG->email_2fa_verified)))
			return false;
	
		return db_update('site_users',User::$info['id'],array('locked'=>'Y'));
	}
	
	public static function unlockAccount() {
		global $CFG;
	
		if (!(($CFG->session_locked || $CFG->session_active) && ($CFG->token_verified || $CFG->email_2fa_verified)))
			return false;
	
		return db_update('site_users',User::$info['id'],array('locked'=>'N'));
	}
	
	public static function settingsEmail2fa($request=false,$security_page=false) {
		global $CFG;

		if (!($CFG->session_locked || $CFG->session_active))
			return false;
		
		$sql = "DELETE FROM change_settings WHERE site_user = ".User::$info['id'];
		db_query($sql);
		
		$request_id = db_insert('change_settings',array('date'=>date('Y-m-d H:i:s'),'request'=>base64_encode(serialize($request)),'site_user'=>User::$info['id']));
		if ($request_id > 0) {
			$vars = User::$info;
			$vars['authcode'] = urlencode(Encryption::encrypt($request_id));
		
			if (!$security_page)
				$email = SiteEmail::getRecord('settings-auth');
			else
				$email = SiteEmail::getRecord('security-auth');
				
			return Email::send($CFG->form_email,User::$info['email'],$email['title'],$CFG->form_email_from,false,$email['content'],$vars);
		}
	}
	
	public static function getSettingsChangeRequest($settings_change_id1) {
		global $CFG;

		if (!$settings_change_id1)
			return false;
		
		$request_id = Encryption::decrypt(urldecode($settings_change_id1));
		if (!($request_id > 0))
			return false;
		
		$change_request = DB::getRecord('change_settings',$request_id,0,1);
		return $change_request['request'];

	}
	
	public static function notifyLogin() {
		global $CFG;
		
		if (!$CFG->session_active)
			return false;
		
		$ipaddress1 = $CFG->client_ip;
		
		db_insert('history',array('date'=>date('Y-m-d H:i:s'),'ip'=>$ipaddress1,'history_action'=>$CFG->history_login_id,'site_user'=>User::$info['id']));
		
		if (User::$info['notify_login'] != 'Y')
			return false;
		
		$email = SiteEmail::getRecord('login-notify');
		$info = User::$info;
		$info['ipaddress'] = $ipaddress1;

		Email::send($CFG->form_email,User::$info['email'],$email['title'],$CFG->form_email_from,false,$email['content'],$info);
	}
	
	public static function getCountries() {
		$sql = "SELECT * FROM iso_countries ORDER BY name ASC";
		return db_query_array($sql);
	}
	
	public static function setLang($lang) {
		if (!$lang)
			return false;
		
		$lang = preg_replace("/[^a-z]/", "",$lang);
		return db_update('site_users',User::$info['id'],array('last_lang'=>$lang));
	}
	
	public static function banIP($ip) {
		global $CFG;
		
		if (empty($ip) || empty($CFG->cloudflare_api_key) || empty($CFG->cloudflare_email))
			return false;
		
		trigger_error($ip,E_USER_WARNING);
	}
}

?>