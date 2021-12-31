package main

type tID struct {
	Id     int64   `db:"id"`
}

type saveData struct {
	rid     int64
	donator string
	token   int64
}

type Save struct {
	donate chan *saveData
}

func saveDonate(name string, rid, token int64) {
	donator := new(tID)
	err := Mysql.Get(donator, "SELECT id FROM donator WHERE name=?", name)
	if err != nil {		
		res, _ := Mysql.Exec("INSERT INTO donator (`name`) VALUES (?)", name)
		donator.Id, _ = res.LastInsertId()
	}
	res, _ := Mysql.Exec("INSERT INTO `stat` (`did`, `rid`, `token`, `time`) VALUES (?, ?, ?, unix_timestamp(now()))", donator.Id, rid, token)
	id, err := res.LastInsertId(); if err == nil {
		tx, _ := Clickhouse.Begin()
		tx.Exec("INSERT INTO stat VALUES (?, ?, ?, ?, toUInt64(now()))", id, donator.Id, rid, token)
		tx.Commit()
	}
}

func getRoomInfo(name string) (*tID, bool) {
	result := true
	room := new(tID)
	err := Mysql.Get(room, "SELECT id FROM room WHERE name=?", name)
	if err != nil {
		result = false
	}
	return room, result
}

func saveBase(s *Save){	
	for {
		select {
			case info := <-s.donate:
			saveDonate(info.donator, info.rid, info.token)
		}
	}
}
