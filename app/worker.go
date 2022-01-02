package main

import (
	"fmt"
	"github.com/gorilla/websocket"
	"net/url"
	"strconv"
	"time"
)

type Input struct {
	Args   []string `json:"args"`
	Method string   `json:"method"`
}

type Donate struct {
	From   string `json:"from_username"`
	Amount int64  `json:"amount"`
}

type AnnounceCount struct {
	Count int `json:"count"`
}

type AnnounceDonate struct {
	Room    string `json:"room"`
	Donator string `json:"donator"`
	Amount  int64  `json:"amount"`
}

func countRooms() int {
	rooms.RLock()
	defer rooms.RUnlock()
	return len(rooms.Name)
}

func announceCount() {
	for {
		time.Sleep(30 * time.Second)
		msg, err := json.Marshal(AnnounceCount{Count: countRooms()})
		if err == nil {
			hub.broadcast <- msg
		}
	}
}

func statRoom(done chan struct{}, room, server string, u url.URL) {
	c, _, err := websocket.DefaultDialer.Dial(u.String(), nil)
	if err != nil {
		fmt.Println(err.Error())
		return
	}
	defer c.Close()
	
	info, ok := getRoomInfo(room)
	if !ok {
		fmt.Println("No room in MySQL:", room)
		
		//m := &sync.Mutex{}
		
		//m.Lock()
		delete(chWorker, room)
		//m.Unlock()
		
		return
	}
	
	now := time.Now().Unix()
	addRoom(room, server, now) // Lock
	donID := make(map[string]int64)
	timeout := now + 60*60
	for {
		
		select {
			case <-done:
				fmt.Println("Exit room:", room)
				return
			default:
		}
		
		_, message, err := c.ReadMessage()
		if err != nil {
			fmt.Println(err.Error())
			break
		}
				
		now = time.Now().Unix()
		if now > timeout {
			fmt.Println("Timeout room:", room)
			break
		}

		m := string(message)

		if m == "o" {
			c.WriteMessage(websocket.TextMessage, []byte(`["{\"method\":\"connect\",\"data\":{\"user\":\"__anonymous__777\",\"password\":\"anonymous\",\"room\":\"`+room+`\",\"room_password\":\"12345\"}}"]`))
			continue
		}

		if m == "h" {
			c.WriteMessage(websocket.TextMessage, []byte(`["{\"method\":\"updateRoomCount\",\"data\":{\"model_name\":\"`+room+`\",\"private_room\":\"false\"}}"]`))
			continue
		}

		// remove a[...]
		if len(m) > 3 && m[0:2] == "a[" {
			m, _ = strconv.Unquote(m[2 : len(m)-1])
		}

		input := Input{}
		if err := json.Unmarshal([]byte(m), &input); err != nil {
			fmt.Println(err.Error())
			continue
		}

		if input.Method == "onAuthResponse" {
			c.WriteMessage(websocket.TextMessage, []byte(`["{\"method\":\"joinRoom\",\"data\":{\"room\":\"`+room+`\"}}"]`))
			continue
		}

		if input.Method == "onRoomCountUpdate" {
			updateRoomOnline(room, input.Args[0]) // Lock
			continue
		}

		donate := Donate{}
		if input.Method == "onNotify" {

			timeout = now + 60*60
			updateRoomLast(room, now)

			if err := json.Unmarshal([]byte(input.Args[0]), &donate); err != nil {
				fmt.Println(err.Error())
				continue
			}
			if len(donate.From) > 3 {
				if _, ok := donID[donate.From]; !ok {
					donID[donate.From] = getDonId(donate.From)
				}
				saveDonate(donID[donate.From], info.Id, donate.Amount, now)
				updateRoomIncome(room, donate.Amount) // Lock

				if donate.Amount > 99 {
					msg, err := json.Marshal(AnnounceDonate{Room: room, Donator: donate.From, Amount: donate.Amount})
					if err == nil {
						hub.broadcast <- msg
					}
				}

				//fmt.Println(donate.From)
				//fmt.Println(donate.Amount)
			}
		}
	}
	if checkRoom(room) {
		removeRoom(room)
	}
}
