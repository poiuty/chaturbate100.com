<?php
// example: cacheResult('getList', [], 30)
function cacheResult($name, $params = [], $time = 600, $json = false){
	global $redis;
	$key = md5($name.implode('.',$params));
	$result = $redis->get($key);
	if($result === false || (php_sapi_name() == "cli" && $redis->ttl($key) < 120)){
		$result = call_user_func($name, $params);
		if(!empty($result)){
			if($json){
				$result = json_encode($result);
			}
			$redis->setex($key, $time, $result);
		}
	}
	if($json){
		$result = json_decode($result, true);
	}
	return $result;
}

function getList(){
	return file_get_contents('https://statbate.com/list/');
}

function dotFormat($v){
	return number_format($v, 0, ',', ',');
}

function toUSD($v){
	$x = $v*0.05; // One TK = 0.05 USD
	if($x < 10){
		return round($x, 2);
	}
	return round($x); 
}

function trackCount(){
	if(!$list = cacheResult('getList', [], 180)){
		return 0;
	}
	return count(json_decode($list, true));
}

function showRoomList(){
	global $redis;
	if(isset($_GET['list'])){
		$arr = json_decode(cacheResult('getList', [], 30), true);
		uasort($arr, function($a, $b){
			return $a['online'] < $b['online'];
		});
		echo "<title>tracking ".count($arr)." rooms</title>";
		echo "<meta http-equiv='refresh' content='60'>";
		echo "<style>body {background-color: #eeeeee;}table, th, td {border: 1px solid black;border-collapse: collapse;} td {min-width: 100px; height: 25px; text-align: center; vertical-align: middle;}</style>";
		echo "<pre>";
		echo "<a href='/'>main page</a>\n\n";
		echo "statbate.com сollects data from open sources\n";
		echo "- room name or nickname\n";
		echo "- chat log\n\n";
		echo "excluded from rating\n";
		echo "- rooms with an average tips of more than 1000\n";
		echo "- donators with an average tips of more than 20000\n\n\n";
		$cb_list = $redis->get('chaturbateList');
		if($cb_list !== false){
			$count = [0, 0, 0, 0];
			$a = json_decode($cb_list, true);
			foreach($a as $val){
				if($val['num_users'] > 100){
					$count['0']++;
				}
				if($val['num_users'] > 50){
					$count['1']++;
				}
				if($val['num_users'] > 25){
					$count['2']++;
				}
				$count['3'] += $val['num_users'];
			}
			echo "<table><tr><td>online more</td><td>25</td><td>50</td><td>100</td></tr><tr><td>rooms</td><td>{$count['2']}</td><td>{$count['1']}</td><td>{$count['0']}</td></tr><tr><td>total rooms</td><td colspan='3'>".count($arr)."</td></tr><tr><td>total online</td><td colspan='3'>{$count['3']}</td></tr></table>\n\n";
		}
		echo "<table><tr><td></td><td>room</td><td>online</td><td>$ income</td><td title='In minutes'>duration</td></tr>";
		$i=0;
		$time = time();
		foreach($arr as $key => $val){
			$i++;
			if($val['online'] == 0){
				$val['online'] = 'new';
			}			
			echo "<tr><td>$i</td><td>$key</td><td>{$val['online']}</td> <td> ".toUSD($val['income'])." </td> <td> ".round(($time - $val['start'])/60)." </td> </tr>";
		}
		echo "</table></pre>";
		die;
	}
}
// https://www.w3schools.in/php-script/time-ago-function/
function get_time_ago($time){
    $time_difference = time() - $time;
    if($time_difference < 1) { return '1 second'; }
    $condition = [ 12 * 30 * 24 * 60 * 60 =>  'year',
                30 * 24 * 60 * 60       =>  'month',
                24 * 60 * 60            =>  'day',
                60 * 60                 =>  'hour',
                60                      =>  'minute',
                1                       =>  'second'
    ];
    foreach($condition as $secs => $str){
        $d = $time_difference/$secs;
        if($d >= 1){
            $t = round($d);
            return $t.' '.$str.($t > 1 ? 's' : '');
        }
    }
}

function createUrl($name){
	$name = strip_tags($name);
	return "<a href='https://chaturbate.com/{$name}' target='_blank' rel='nofollow'>{$name}</a>";
}
