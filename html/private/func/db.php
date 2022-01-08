<?php

function getFinStat(){ // cache done
	global $clickhouse;
	$query = $clickhouse->query("SELECT SUM(token), AVG(token), count(DISTINCT rid) FROM stat WHERE time > toUInt64(toDateTime(DATE_SUB(NOW(), INTERVAL 1 month)))");
	$row =  $query->fetch();
	return ['total' => toUSD($row['0']), 'avg' => toUSD($row['1'], 2), 'count' => $row['2']];
}

function getModalCharts($a){ // cache done
	global $clickhouse; $arr = [];
	$c = 'did';
	if($a['type'] == 'income'){
		$c = 'rid';
	}
	$query = $clickhouse->query("SELECT FROM_UNIXTIME(time, '%Y-%m-%d') as ndate, SUM(token) as total FROM stat WHERE $c = {$a['id']} AND `time` > toUInt64(toDateTime(DATE_SUB((DATE_SUB(NOW(), INTERVAL 1 MONTH)), INTERVAL 1 DAY))) GROUP BY ndate ORDER BY ndate DESC");
	if($query->rowCount() == 0){
		return false;
	}
	while($row = $query->fetch()){
		$arr[] = ['date' => $row['ndate'], 'value' => toUSD($row['total'])];
		$last = $row['total'];
	}
	return json_encode($arr, JSON_NUMERIC_CHECK);
}

function getModalTable($room) {
	global $clickhouse; $result = '';
	$c1 = 'rid'; $c2 = 'did';
	if($room['type'] == 'income'){
		$c1 = 'did'; $c2 = 'rid';
	}
	$tmpl = '<tr><td>{URL}</td><td>{TOTAL}</td><td>{AVG}</td></tr>';
	$query = $clickhouse->query("SELECT $c1, SUM(token) as total, AVG(token) as avg FROM stat WHERE $c2 = {$room['id']} AND time > toUInt64(toDateTime(DATE_SUB(NOW(), INTERVAL 1 month))) GROUP BY $c1 ORDER BY total DESC LIMIT 20");
	$row =  $query->fetchAll();
	
	foreach($row as $val) {
		$tr = str_replace('{URL}', createUrl(cacheResult('getDonName', ['id' => $val[$c1]], 86000)), $tmpl);
		$tr = str_replace('{TOTAL}', toUSD($val['total']), $tr);
		$tr = str_replace('{AVG}', toUSD($val['avg'], 2), $tr);
		$result .= $tr;
	}	
	return $result;
}

function getTopDons(){ // cache done
	global $clickhouse; $result = ''; 
	$tmpl = '<tr><td>{ID}</td><td>{URL}</td><td>{LAST}</td><td>{COUNT}</td><td>{AVG}</td><td>{TOTAL}</td></tr>';
	$query = $clickhouse->query("SELECT did, COUNT(DISTINCT rid) as count, MAX(time) as max, SUM(token) as total, AVG(token) as avg FROM stat WHERE time > toUInt64(toDateTime(DATE_SUB(NOW(), INTERVAL 1 month))) GROUP BY did HAVING avg < 2000 ORDER BY total DESC LIMIT 100");
	$row =  $query->fetchAll();
	
	$i = 0;
	
	$arr = [];
	
	
	foreach($row as $val) {
		$i++;
		
		$name = cacheResult('getDonName', ['id' => $val['did']], 86000);
		$tr = str_replace('{ID}', $i, $tmpl);
		$tr = str_replace('{URL}', createUrl($name), $tr);
		$tr = str_replace('{LAST}', get_time_ago($val['max']), $tr);
		$tr = str_replace('{COUNT}', $val['count'], $tr);
		$tr = str_replace('{AVG}', toUSD($val['avg'], 2), $tr);
		$tr = str_replace('{TOTAL}', "<a href='#' data-modal-info data-modal-id={$val['did']} data-modal-type=spend data-modal-name=$name>".toUSD($val['total'])."</a>", $tr);
		$result .= $tr;
		
		//$arr[] = $val['did']; 
		
	}	
	
	return $result;
}

function getModalAmount($arr){ // cache done
	global $clickhouse;
	$c = 'did';
	if($arr['type'] == 'income'){
		$c = 'rid';
	}
	$query = $clickhouse->query("SELECT SUM(token) as total FROM stat WHERE $c = {$arr['id']}");
	return toUSD($query->fetch()['0']);
}

function getDonName($arr){ // cache done
	global $db;
	$select = $db->prepare('SELECT `name` FROM `donator` WHERE `id` = :id');
	$select->bindParam(':id', $arr['id']);
	$select->execute();
	return $select->fetch()['name'];
}

function getRoomName($arr){ // cache done
	global $db;
	$select = $db->prepare('SELECT `name` FROM `room` WHERE `id` = :id');
	$select->bindParam(':id', $arr['id']);
	$select->execute();
	return $select->fetch()['name'];
}

function getRoomInfo($arr){ // cache done
	global $db;
	$query = $db->prepare('SELECT * FROM `room` WHERE `name` = :name');
	$query->bindParam(':name', $arr['name']);
	$query->execute();
	if($query->rowCount() == 1){
		return $query->fetch();
	}
	$query = $db->prepare('INSERT INTO `room` (`name`) VALUES (:name)');
	$query->bindParam(':name', $arr['name']);
	$query->execute();
	$id = $db->lastInsertId();
	return ['id' => $id, 'name' => $arr['name'], 'gender' => 0, 'last' => 0];		
}

function getTop(){ // cache done
	global $db, $clickhouse;
	$data = [];
	$query = $clickhouse->query("SELECT rid, SUM(token) as total FROM stat WHERE time > toUInt64(toDateTime(DATE_SUB(NOW(), INTERVAL 1 month))) AND did not in (SELECT did FROM stat WHERE time > toUInt64(toDateTime(DATE_SUB(NOW(), INTERVAL 1 month))) GROUP BY did HAVING AVG(token) > 20000) GROUP BY rid HAVING AVG(token) < 1000 ORDER BY total DESC LIMIT 100");
	$row =  $query->fetchAll();
	foreach($row as $val) {
		$data[$val['rid']]['token'] = $val['total'];
	}		
	$in = implode(', ', array_column($row, 'rid'));
	$query = $db->query("SELECT `id`, `name`, `gender`, `fans`, `last` FROM `room` WHERE `id` IN ($in)");
	while($row = $query->fetch()){
		$data[$row['id']]['name'] = $row['name'];
		$data[$row['id']]['last'] = $row['last'];
		$data[$row['id']]['gender'] = $row['gender'];
		$data[$row['id']]['fans'] = round($row['fans']/1000);
	}
	return $data;
}

function prepareTable(){ // cache done
	$i = 0; $stat = ''; $list = [];
	$gender = ['boy', 'girl', 'trans', 'couple'];
	$data = cacheResult('getTop', [], 600, true);
	$online = json_decode(cacheResult('getList', [], 180), true);	
	$tmpl = '<tr><td>{ID}</td><td>{URL}</td><td>{GENDER}</td><td>{LAST}</td><td>{FANS}</td><td>{USD}</td></tr>';
	foreach($data as $key => $val){
		$i++;
		$list[] = $val['name'];
		$val['token'] = toUSD($val['token']);
		$val['last'] = get_time_ago($val['last']);
		if(array_key_exists($val['name'], $online)){
			//$val['last'] = ' <font color="green">'.$online[$val['name']]['online'].'</font>';
			$val['last'] = '<font color="green">online</font>';
		}
		$tr = str_replace('{ID}', $i, $tmpl);
		$tr = str_replace('{URL}', createUrl($val['name']), $tr);
		$tr = str_replace('{GENDER}', $gender[$val['gender']], $tr);
		$tr = str_replace('{LAST}', $val['last'], $tr);
		$tr = str_replace('{FANS}', $val['fans'], $tr);
		$tr = str_replace('{USD}', "<a href=\"#\" data-modal-info data-modal-id=$key data-modal-type=income data-modal-name=".strip_tags($val['name']).">{$val['token']}</a>", $tr);
		$stat .= $tr;
	}
	return $stat;
}

function genderIncome(){ // cache done
	global $clickhouse;
	$arr = [];
	$query = $clickhouse->query("SELECT room.gender, SUM(token) as total, FROM_UNIXTIME(time, '%Y-%m-%d') as ndate FROM `stat` LEFT JOIN `room` ON stat.rid = room.id WHERE time > toUInt64(toDateTime(DATE_SUB((DATE_SUB(NOW(), INTERVAL 1 MONTH)), INTERVAL 1 DAY))) GROUP by `gender`, ndate ORDER BY ndate ASC");
	while($row = $query->fetch()){
		@$arr[$row['gender']][$row['ndate']] = ['value' => toUSD($row['total'])];
		@$arr['4'][$row['ndate']]['value'] += toUSD($row['total']);
		if($row['gender'] != 1){
			@$arr['5'][$row['ndate']]['value'] += toUSD($row['total']);
		}
	}
	ksort($arr);
	return $arr;
}

function getCharts(){ // cache done
	$data = [];
	$arr = cacheResult('genderIncome', [], 900, true);
	foreach($arr as $key => $val){
		if($key == 0 || $key == 2 || $key == 3){
			continue;
		}
		foreach($val as $k => $v){
			$data[$key][] = ['date' => $k, 'value' => $v['value']];
		}
	}
	$data = array_values($data);
	$stat = json_encode($data, JSON_NUMERIC_CHECK);
	return $stat;
}

function getPieStat(){
	$r = []; $x = [];
	$names = ['Boys', 'Girls', 'Trans', 'Couple'];
	$arr = cacheResult('genderIncome', [], 900, true);
	foreach($arr as $key => $val){
		if($key == 4 || $key == 5){
			continue;
		}
		foreach($val as $k => $v){
			@$x[$key] += $v['value'];
		}
	}
	foreach($x as $k => $v){
		$r[] = ['name' => $names[$k], 'y' => $v];
	}
	return json_encode($r);
}
