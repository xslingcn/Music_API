<?php

namespace app\api\controller;

use app\api\BaseController;
use think\facade\Db;
use app\model\Room as RoomModel;
use app\model\Song as SongModel;
use app\model\User as UserModel;
use app\model\Attach as AttachModel;
use think\App;

class Song extends BaseController
{
    public function __construct(App $app)
    {
        parent::__construct($app);
        //查询列表时允许的字段
        $this->selectList = "*";
        //查询详情时允许的字段
        $this->selectDetail = "*";
        //筛选字段
        $this->searchFilter = [];
        $this->insertFields = [
            //允许添加的字段列表
        ];
        $this->updateFields = [
            //允许更新的字段列表
        ];
        $this->insertRequire = [
            //添加时必须填写的字段
            // "字段名称"=>"该字段不能为空"

        ];
        $this->updateRequire = [
            //修改时必须填写的字段
            // "字段名称"=>"该字段不能为空"

        ];
        $this->model = new SongModel();
    }
    public function search()
    {
        if (input('isHots')) {
            //获取本周热门歌曲
            $cache = cache('week_song_play_rank') ?? false;
            if ($cache) {
                return jok('from redis', $cache);
            }
            $result = Db::query("select sum(song_week) as week,song_mid as mid,song_id as id,song_pic as pic,song_singer as singer,song_name as name from sa_song where song_week > 0 group by song_mid order by week desc limit 0,50");
            cache('week_song_play_rank', $result, 10);
            return jok('success', $result);
        }
        $list = [];
        $cookie = cache('cookie');
        if (!cache('hot_keywords_0')) {
            $result = curlHelper(config('startadmin.163_api') . '/search/hot' . "?cookie=" . urlencode($cookie));
            $hotArray = json_decode($result['body'], true);
            $hotArray = $hotArray['result']['hots'];
            for ($i = 0; $i < count($hotArray); $i++) {
                cache('hot_keywords_' . $i, $hotArray[$i]['first'], 7200);
            }
        }
        $keyword = cache('hot_keywords_' . rand(0, 9));
        if (input("keyword")) {
            $keyword = input('keyword');
        }
        $list = [];
        $search_list = [];
        $result = curlHelper(config('startadmin.163_api') . '/search?keywords=' . rawurlencode($keyword) . '&limit=15' . "&cookie=" . urlencode($cookie));
        $arr = json_decode($result['body'], true);
        if ($arr['code'] == 200) {
            try {
                $search_list = $arr['result']['songs'];
            } catch (\Exception $e) {
                return jerr('搜索失败,建议重试');
            }
        } else {
            return jerr('搜索失败,建议重试');
        }
        if (count($search_list) > 0) {
            $songModel = new SongModel();
            for ($i = 0; $i < count($search_list); $i++) {
                $song = $search_list[$i];

                $songPictureFromCache = cache("song_picture_" . $song['id']) ?? false;
                if ($songPictureFromCache) {
                    $songPicture = $songPictureFromCache;
                } else {
                    $songFromDatabase = $songModel->where('song_mid', $song['id'])->find();
                    if ($songFromDatabase) {
                        $songPicture = $songFromDatabase['song_pic'];
                    } else {
                        $result = curlHelper(config('startadmin.163_api') . "/song/detail?ids=" . $song['id'] . "&cookie=" . urlencode($cookie));
                        $arr = json_decode($result['body'], true);
                        if ($arr['code'] != 200) {
                            return false;
                        }
                        $songPicture = $arr['songs'][0]['al']['picUrl'];
                    }
                }

                $temp = [
                    'mid' => $song['id'],
                    'name' => html_entity_decode($song['name']),
                    'pic' => $songPicture,
                    'length' => floor($song['duration'] / 1000),
                    'singer' => $song['artists'][0]['name'],
                    'album' => $song['album']['name']
                ];
                array_push($list, $temp);
                cache('song_detail_' . $song['id'], $temp, 3600);
            }
            cache("music_search_list_keyword_new_" . sha1($keyword), $search_list, 3600);
            return jok('', $list);
        } else {
            return jok('success', $list);
        }
    }
    public function deleteMySong()
    {
        if (input('access_token') == getTempToken()) {
            return jerr('请登录后体验完整功能!', 401);
        }
        $error = $this->access();
        if ($error) {
            return $error;
        }

        if (!input('mid') || !input('room_id')) {
            return jerr("参数错误,缺少song_mid/room_id");
        }
        $room_id = input('room_id');

        $roomModel = new RoomModel();
        $room = $roomModel->getRoomById($room_id);
        if (!$room) {
            return jerr("房间信息查询失败");
        }

        $mid = input('mid');
        $this->model->where('song_mid', $mid)->where('song_user', $this->user['user_id'])->delete();
        return jok('删除歌单的歌曲成功');
    }
    public function addMySong()
    {
        if (input('access_token') == getTempToken()) {
            return jerr('请登录后体验完整功能!', 401);
        }
        $error = $this->access();
        if ($error) {
            return $error;
        }

        if (!input('mid') || !input('room_id')) {
            return jerr("参数错误,缺少song_mid/room_id");
        }
        $room_id = input('room_id');

        $roomModel = new RoomModel();
        $room = $roomModel->getRoomById($room_id);
        if (!$room) {
            return jerr("房间信息查询失败");
        }

        $mid = input('mid');
        $song = $this->model->where('song_mid', $mid)->where('song_user', $this->user['user_id'])->find();
        if ($song) {
            return jerr('你已经搜藏过这首歌');
        }

        $song = cache('song_detail_' . $mid) ?? false;
        if (!$song) {
            return jerr('歌曲信息获取失败，搜藏失败');
        }
        $this->model->insert([
            'song_mid' => $song['mid'],
            'song_name' => $song['name'],
            'song_singer' => $song['singer'],
            'song_mid' => $song['mid'],
            'song_pic' => $song['pic'],
            'song_length' => $song['length'],
            'song_user' => $this->user['user_id'],
            'song_createtime' => time(),
            'song_updatetime' => time(),
        ]);

        $msg = [
            "content" => rawurldecode($this->user['user_name']) . ' 收藏了当前播放的歌曲',
            "type" => "system",
            "time" => date('H:i:s'),
        ];
        sendWebsocketMessage('channel', $room_id, $msg);
        return jok('歌曲搜藏成功，快去你的已点列表看看吧');
    }
    public function addNewSong()
    {
        if (input('access_token') == getTempToken()) {
            return jerr('请登录后体验完整功能!', 401);
        }
        $error = $this->access();
        if ($error) {
            return $error;
        }

        if (!input('song_length') || !input('song_name') || !input('song_singer') || !input('song_mid')) {
            return jerr("参数错误，缺少 song_length/song_name/song_singer/song_mid");
        }

        $song = [
            'song_mid' => 0 - intval(input('song_mid')),
            'song_name' => input('song_name'),
            'song_singer' => input('song_singer'),
            'song_pic' => input('song_pic'),
            'song_length' => intval(input('song_length')),
            'song_user' => $this->user['user_id'],
            'song_createtime' => time(),
            'song_updatetime' => time(),
        ];

        $this->model->insert($song);

        return jok('添加成功');
    }
    public function playSong()
    {
        if (input('access_token') == getTempToken()) {
            return jerr('请登录后体验完整功能!', 401);
        }
        $error = $this->access();
        if ($error) {
            return $error;
        }

        if (!input('mid') || !input('room_id')) {
            return jerr("参数错误,缺少song_mid/room_id");
        }
        $room_id = input('room_id');

        $roomModel = new RoomModel();
        $room = $roomModel->getRoomById($room_id);
        if (!$room) {
            return jerr("房间信息查询失败");
        }
        if ($room['room_type'] != 4) {
            return jerr("该房间下不允许播放");
        }

        $isVip = cache('guest_room_' . $room_id . '_user_' . $this->user['user_id']) ?? false;

        if (!getIsAdmin($this->user) && $this->user['user_id'] != $room['room_user'] && !$isVip) {
            return jerr("你没有权限播放");
        }

        $mid = input('mid');
        $song = cache('song_detail_' . $mid) ?? false;
        if (!$song) {
            $temp = $this->model->where('song_mid', $mid)->find();
            if (!$temp) {
                return jerr("歌曲信息读取失败，无法播放");
            } else {
                $song = [
                    'mid' => $temp['song_mid'],
                    'name' => $temp['song_name'],
                    'pic' => $temp['song_pic'] ?? '',
                    'length' => $temp['song_length'],
                    'singer' => $temp['song_singer'],
                ];
            }
        }
        //将歌曲置顶
        $songList = cache('SongList_' . $room_id) ?? [];
        $isPushed = false;
        for ($i = 0; $i < count($songList); $i++) {
            $item = $songList[$i];
            if ($item['song']['mid'] == $mid) {
                array_splice($songList, $i, 1);
                array_unshift($songList, $item);
                $isPushed = true;
                break;
            }
        }


        $songModel = new SongModel();
        if ($song['mid'] > 0) {
            //尝试同步一个图片回来
            $cookie = cache('cookie');
            $result = curlHelper(config('startadmin.163_api') . "/song/detail?ids=" . $song['mid'] . "&cookie=" . urlencode($cookie));
            $arr = json_decode($result['body'], true);
            if ($arr['code'] != 200) {
                return jerr("歌曲信息获取失败");
            }
            try {
                $songDetailTemp = $arr['songs'];
                $songModel->where('song_mid', $song['mid'])->update([
                    'song_pic' => $songDetailTemp['al']['picUrl'],
                    'song_length' => floor($songDetailTemp['dt'] / 1000),
                    'song_updatetime' => time(),
                ]);
                $song['pic'] = $songDetailTemp['al']['picUrl'];
                cache("song_picture_" . $song['mid'], $song['pic']);
            } catch (\Exception $e) {
            }
        }
        cache('song_detail_' . $song['mid'], $song, 3600);


        if (!$isPushed) {
            array_unshift($songList, [
                'user' => getUserData($this->user),
                'song' => $song,
                'at' => false,
            ]);
        }
        cache('SongList_' . $room_id, $songList, 86400);
        //切掉正在播放
        cache('SongNow_' . $room_id, null);

        $songExist = $songModel->where('song_mid', $song['mid'])->where('song_user', $this->user['user_id'])->find();
        if (!$songExist) {
            $songModel->insert([
                'song_mid' => $song['mid'],
                'song_name' => $song['name'],
                'song_singer' => $song['singer'],
                'song_mid' => $song['mid'],
                'song_pic' => $song['pic'],
                'song_length' => $song['length'],
                'song_user' => $this->user['user_id'],
                'song_createtime' => time(),
                'song_updatetime' => time(),
            ]);
        } else {
            $songModel->where('song_id', $songExist['song_id'])->update([
                'song_updatetime' => time(),
            ]);
        }
        return jok('播放成功');
    }
    public function addSong()
    {
        if (input('access_token') == getTempToken()) {
            return jerr('请登录后体验完整功能!', 401);
        }
        $error = $this->access();
        if ($error) {
            return $error;
        }

        if (!input('mid') || !input('room_id')) {
            return jerr("参数错误,缺少song_mid/room_id");
        }
        $room_id = input('room_id');

        $roomModel = new RoomModel();
        $room = $roomModel->getRoomById($room_id);
        if (!$room) {
            return jerr("房间信息查询失败");
        }

        if ($room['room_type'] != 1 && $room['room_type'] != 4) {
            return jerr("该房间下不允许点歌");
        }

        $mid = input('mid');
        $song = cache('song_detail_' . $mid) ?? false;
        if (!$song) {
            $temp = $this->model->where('song_mid', $mid)->find();
            if (!$temp) {
                return jerr("歌曲数据获取失败,请重新搜索后点歌");
            } else {
                $song = [
                    'mid' => $temp['song_mid'],
                    'name' => $temp['song_name'],
                    'pic' => $temp['song_pic'] ?? '',
                    'length' => $temp['song_length'],
                    'singer' => $temp['song_singer'],
                ];
            }
        }
        $songModel = new SongModel();
        if ($song['mid'] > 0) {
            //尝试同步一个图片回来
            $cookie = cache('cookie');
            $result = curlHelper(config('startadmin.163_api') . "/song/detail?ids=" . $song['mid'] . "&cookie=" . urlencode($cookie));
            $arr = json_decode($result['body'], true);
            if ($arr['code'] != 200) {
                return jerr("歌曲信息获取失败");
            }
            try {
                $songDetailTemp = $arr['songs'];
                $songModel->where('song_mid', $song['mid'])->update([
                    'song_pic' => $songDetailTemp['al']['picUrl'],
                    'song_length' => floor($songDetailTemp['dt'] / 1000),
                    'song_updatetime' => time(),
                ]);
                $song['pic'] = $songDetailTemp['al']['picUrl'];
                cache("song_picture_" . $song['mid'], $song['pic']);
            } catch (\Exception $e) {
            }
        }
        cache('song_detail_' . $song['mid'], $song, 3600);

        $at = input('at');
        if ($at) {
            $user = $this->userModel->where('user_id', $at)->find();
            if ($user) {
                $at = getUserData($user);
                if ($at['user_id'] == $this->user['user_id']) {
                    return jerr("“自己给自己送歌，属实不高端”——佚名");
                }
            } else {
                return jerr("被送歌人信息查询失败");
            }
        } else {
            $at = false;
        }
        $isBan = cache('songdown_room_' . $room_id . '_user_' . $this->user['user_id']);
        if ($isBan) {
            return jerr("你被房主禁止了点歌权限!");
        }
        $songList = cache('SongList_' . $room_id) ?? [];
        $existSong = null;
        $mySong = 0;
        foreach ($songList as $item) {
            if ($item['user']['user_id'] == $this->user['user_id']) {
                $mySong++;
            }
            if ($item['song']['mid'] == $song['mid']) {
                $existSong = $item['song']['name'];
            }
        }
        if ($existSong) {
            return jerr('歌曲《' . $existSong . '》正在等待播放呢!');
        }
        $addSongCDTime = $room['room_addsongcd'];

        $isVip = cache('guest_room_' . $room_id . '_user_' . $this->user['user_id']) ?? false;

        if (!getIsAdmin($this->user) && $this->user['user_id'] != $room['room_user'] && !$isVip) {
            if ($room['room_addsong'] == 1) {
                return jerr('点歌失败,当前房间仅房主可点歌');
            }
            $addSongLastTime = cache('song_' . $this->user['user_id']) ?? 0;
            $addSongNeedTime = $addSongCDTime - (time() - $addSongLastTime);
            if ($addSongNeedTime > 0) {
                return jerr('点歌太频繁，请' . $addSongNeedTime . 's后再试');
            }
            if ($mySong >= $room['room_addcount']) {
                return jerr('你还有' . $mySong . '首歌没有播，请稍候再点歌吧~');
            }
        }

        cache('song_' . $this->user['user_id'], time(), $addSongCDTime);

        if (count($songList) == 1 && $songList[0]["user"]["user_id"] == 1) {
            //如果待播放只有机器人点的歌了 删除
            array_splice($songList, 0, 1);
        }

        array_push($songList, [
            'user' => getUserData($this->user),
            'song' => $song,
            'at' => $at ?? false,
        ]);
        cache('SongList_' . $room_id, $songList, 86400);

        $msg = [
            'user' => getUserData($this->user),
            'song' => $song,
            "type" => "addSong",
            'at' => $at ?? false,
            "time" => date('H:i:s'),
        ];
        sendWebsocketMessage('channel', $room_id, $msg);

        $songExist = $songModel->where('song_mid', $song['mid'])->where('song_user', $this->user['user_id'])->find();
        if (!$songExist) {
            $songModel->insert([
                'song_mid' => $song['mid'],
                'song_name' => $song['name'],
                'song_singer' => $song['singer'],
                'song_mid' => $song['mid'],
                'song_pic' => $song['pic'],
                'song_play' => 1,
                'song_week' => 1,
                'song_length' => $song['length'],
                'song_user' => $this->user['user_id'],
            ]);
        } else {
            $songModel->where('song_id', $songExist['song_id'])->inc('song_play')->update();
            $songModel->where('song_id', $songExist['song_id'])->inc('song_week')->update();
            $songModel->where('song_id', $songExist['song_id'])->update([
                'song_updatetime' => time(),
            ]);
        }
        return jok('歌曲' . $song['name'] . '已经添加到播放列表！', $song);
    }
    public function mySongList()
    {
        $page = 1;
        if (input('page')) {
            $page = intval(input('page'));
        }
        $per_page = 20;
        if (input('per_page')) {
            $per_page = intval(input('per_page'));
        }
        if (input('access_token') == getTempToken()) {
            return jerr('请登录后体验完整功能!', 401);
        }
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $songModel = new SongModel();
        $list = $songModel->field('song_mid as mid,song_length as length,song_name as name,song_singer as singer,song_play as played,song_pic as pic')->where('song_user', $this->user['user_id'])->order('song_updatetime desc,song_play desc,song_id desc')->limit($per_page)->page($page)->select();
        return jok('success', $list);
    }
    public function getUserSongs()
    {
        if (!input("user_id")) {
            return jerr("user_id 为必传参数");
        }
        $user_id = intval(input('user_id'));
        $songModel = new SongModel();
        $list = $songModel->field('song_mid as mid,song_length as length,song_name as name,song_singer as singer,song_play as played,song_pic as pic')->where('song_user', $user_id)->order('song_updatetime desc,song_play desc,song_id desc')->limit(50)->select();
        return jok('success', $list);
    }
    public function now()
    {
        if (!input('room_id')) {
            return jerr("参数错误,缺少room_id");
        }
        $room_id = input('room_id');
        $roomModel = new RoomModel();
        $room = $roomModel->getRoomById($room_id);
        if (!$room) {
            return jerr("房间信息查询失败");
        }
        $result = [];
        switch ($room['room_type']) {
            case 1:
            case 4:
                $now = cache('SongNow_' . $room_id) ?? [];
                $result = [
                    'type' => 'playSong',
                    'time' => date('H:i:s'),
                    'user' => null,
                    'song' => null,
                ];
                if ($now) {
                    $result['user'] = $now['user'];
                    $result['at'] = $now['at'] ?? false;
                    $result['song'] = $now['song'];
                    $result['since'] = $now['since'];
                    $result['now'] = time();
                }
                break;
            case 2:
                $now = cache('MusicGameNow_' . $room_id) ?? [];
                $result = [
                    'type' => 'playSong',
                    'time' => date('H:i:s'),
                    'song' => null,
                ];
                if ($now) {
                    $now['song']['name'] = '猜歌游戏';
                    $now['song']['singer'] = '进行中';
                    $now['song']['pic'] = '/images/nohead.jpg';
                    $result['song'] = $now['song'];
                    $result['since'] = $now['since'];
                    $result['now'] = time();
                }
                break;
            case 3:
                $story = cache('story_play_' . $room_id) ?? false;
                $result = [
                    'type' => 'story',
                    'time' => date('H:i:s'),
                    'story' => null,
                    'since' => 0,
                    'now' => time(),
                ];
                if ($story) {
                    $result['story'] = $story;
                    $result['since'] = $story['since'];
                }
                break;
            default:
        }

        return json($result);
    }
    public function pass()
    {
        if (input('access_token') == getTempToken()) {
            return jerr('请登录后体验完整功能!', 401);
        }
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!input('mid') || !input('room_id')) {
            return jerr("参数错误,缺少song_mid/room_id");
        }
        $room_id = input('room_id');

        $roomModel = new RoomModel();
        $room = $roomModel->getRoomById($room_id);
        if (!$room) {
            return jerr("房间信息查询失败");
        }

        $mid = input('mid');
        $song = cache('song_detail_' . $mid) ?? false;
        if (!$song) {
            $temp = $this->model->where('song_mid', $mid)->find();
            if (!$temp) {
                return jerr("歌曲数据获取失败,请重新搜索后点歌");
            } else {
                $song = [
                    'mid' => $temp['song_mid'],
                    'name' => $temp['song_name'],
                    'pic' => $temp['song_pic'] ?? '',
                    'length' => $temp['song_length'],
                    'singer' => $temp['song_singer'],
                ];
            }
        }

        $now = cache('SongNow_' . $room_id) ?? '';
        $SongList = cache('SongList_' . $room_id) ?? [];
        $time = cache('SongNextTime_' . $room_id) ?? 0;
        if (!$now) {
            return jerr('当前没有正在播放的歌曲');
        }

        cache('SongNextTime_' . $room_id, time());
        if ($room['room_user'] != $this->user['user_id'] && !getIsAdmin($this->user) && $now['user']['user_id'] != $this->user['user_id']) {
            //其他人
            $isVip = cache('guest_room_' . $room_id . '_user_' . $this->user['user_id']) ?? false;
            if (!$isVip) {
                if ($room['room_votepass'] == 0) {
                    return jok("该房间未开启投票切歌");
                }
                $onlineCount = $room['room_online'] - 2; //取消机器人的在线数
                $limitCount = ceil($onlineCount * $room['room_votepercent'] / 100);
                if ($limitCount > 10) {
                    $limitCount = 10;
                }
                if ($limitCount < 2) {
                    $limitCount = 2;
                }
                $songNextCount = cache('song_next_count_' . $room_id . '_mid_' . $now['song']['mid']) ?? 0;
                $isMeNexted = cache('song_next_user_' . $this->user['user_id']) ?? '';
                if ($isMeNexted == $now['song']['mid']) {
                    return jok('已有' . $songNextCount . '人不想听,在线' . $room['room_votepercent'] . '%(' . $limitCount . '人)不想听即可自动切歌');
                }
                cache('song_next_user_' . $this->user['user_id'], $now['song']['mid'], 3600);
                $songNextCount++;
                $msg = [
                    "content" => rawurldecode($this->user['user_name']) . ' 表示不太喜欢当前播放的歌(' . $songNextCount . '/' . $limitCount . ')',
                    "type" => "system",
                    "time" => date('H:i:s'),
                ];
                sendWebsocketMessage('channel', $room_id, $msg);
                if ($songNextCount >= $limitCount) {
                    cache('SongNow_' . $room_id, null);
                    $msg = [
                        "content" => $room['room_votepercent'] . '%在线用户(' . $limitCount . '人)不想听这首歌，系统已自动切歌!',
                        "type" => "system",
                        "time" => date('H:i:s'),
                    ];
                    sendWebsocketMessage('channel', $room_id, $msg);
                }
                cache('song_next_count_' . $room_id . '_mid_' . $now['song']['mid'], $songNextCount, 3600);

                return jok('你的不想听态度表态成功!');
            }
        }

        cache('SongNow_' . $room_id, null);
        $msg = [
            "user" => getUserData($this->user),
            "song" => $song,
            "type" => "pass",
            "time" => date('H:i:s'),
        ];
        sendWebsocketMessage('channel', $room_id, $msg);

        return jok('切歌成功');
    }
    public function songList()
    {
        if (!input('room_id')) {
            return jerr("参数错误,缺少room_id");
        }
        $room_id = input('room_id');
        $songList = cache('SongList_' . $room_id) ?? [];
        return jok('', $songList ?? []);
    }
    public function getLrc()
    {
        if (!input('mid')) {
            return jerr('参数错误,mid缺失');
        }
        $mid = input('mid');
        if (intval($mid) < 0) {
            return jok('', [
                [
                    'lineLyric' => '歌曲为用户上传,暂无歌词',
                    'time' => 0
                ],
            ]);
        }
        $cookie = cache('cookie');
        $res = curlHelper(config('startadmin.163_api') . "/lyric?id=" . $mid);
        $data = json_decode($res['body'], true);
        if ($data['code'] == 200) {
            $lrc = $data['lrc']['lyric'] ?? '';
            if ($lrc) {
                $lrc = explode("\n", $lrc);
                $lrc = array_filter($lrc, function ($v) {
                    return strlen($v) > 0;
                });
                $lrc = array_map(function ($v) {
                    $v = explode(']', $v);
                    $v[0] = str_replace('[', '', $v[0]);
                    $v[1] = str_replace('&#58;', ':', $v[1]);
                    return [
                        'lineLyric' => $v[1],
                        'time' => timeToSeconds($v[0])
                    ];
                }, $lrc);
                return jok('', $lrc);
            } else {
                return jok('', [
                    [
                        'lineLyric' => '很尴尬呀,没有查到歌词~',
                        'time' => 0
                    ],
                ]);
            }
        }
    }
    public function push()
    {
        if (input('access_token') == getTempToken()) {
            return jerr('请登录后体验完整功能!', 401);
        }
        $error = $this->access();
        if ($error) {
            return $error;
        }

        if (!input('mid') || !input('room_id')) {
            return jerr("参数错误,缺少song_mid/room_id");
        }
        $room_id = input('room_id');

        $roomModel = new RoomModel();
        $room = $roomModel->getRoomById($room_id);
        if (!$room) {
            return jerr("房间信息查询失败");
        }
        $isVip = cache('guest_room_' . $room_id . '_user_' . $this->user['user_id']) ?? false;

        $mid = input('mid');
        $song = cache('song_detail_' . $mid) ?? false;
        if (!$song) {
            $temp = $this->model->where('song_mid', $mid)->find();
            if (!$temp) {
                return jerr("歌曲数据获取失败,请重新搜索后点歌");
            } else {
                $song = [
                    'mid' => $temp['song_mid'],
                    'name' => $temp['song_name'],
                    'pic' => $temp['song_pic'] ?? '',
                    'length' => $temp['song_length'],
                    'singer' => $temp['song_singer'],
                ];
            }
        }

        $songList = cache('SongList_' . $room_id) ?? [];
        $pushSong = false;
        for ($i = 0; $i < count($songList); $i++) {
            $item = $songList[$i];
            if ($item['song']['mid'] == $mid) {
                $pushSong = $item;
                array_splice($songList, $i, 1);
                array_unshift($songList, $item);
                break;
            }
        }
        if (!$pushSong) {
            return jerr("顶歌失败，歌曲ID不存在");
        }
        if ($room['room_user'] != $this->user['user_id'] && !getIsAdmin($this->user) && !$isVip) {
            $pushCount = $room['room_pushdaycount'];
            $pushCache = cache('push_' . date('Ymd') . '_' . $this->user['user_id']);
            $pushCache = $pushCache ? intval($pushCache) : 0;

            $push_last_time = cache('push_last_' . $this->user['user_id']) ?? 0;
            $pushTimeLimit = $room['room_pushsongcd'];
            if (time() - $push_last_time < $pushTimeLimit) {
                $timeStr = '';
                $minute = floor(($pushTimeLimit - (time() - $push_last_time)) / 60);
                if ($minute > 0) {
                    $timeStr .= $minute . "分";
                }
                $second = ($pushTimeLimit - (time() - $push_last_time)) % 60;
                if ($second > 0) {
                    $timeStr .= $second . "秒";
                }
                return jerr("顶歌太频繁啦，请" . $timeStr . "后再试！");
            }
            cache('push_last_' . $this->user['user_id'], time());
            if ($pushCache >= $pushCount) {
                if (!in_array($this->user['user_group'], [1, 6, 7])) {
                    return jerr("你的" . $pushCount . "次顶歌机会已使用完啦");
                }
            }
            $pushCache++;
            cache('push_' . date('Ymd') . '_' . $this->user['user_id'], $pushCache, 86400);
        }

        cache('SongList_' . $room_id, $songList, 86400);
        $msg = [
            'user' => getUserData($this->user),
            'song' => $pushSong['song'],
            "type" => "push",
            "time" => date('H:i:s'),
        ];
        sendWebsocketMessage('channel', $room_id, $msg);

        if ($room['room_user'] != $this->user['user_id'] && !getIsAdmin($this->user) && !$isVip) {
            return jok('顶歌成功,今日剩余' . ($pushCount - $pushCache) . '次顶歌机会!');
        }
        return jok('顶歌成功');
    }
    public function remove()
    {
        if (input('access_token') == getTempToken()) {
            return jerr('请登录后体验完整功能!', 401);
        }
        $error = $this->access();
        if ($error) {
            return $error;
        }

        if (!input('mid') || !input('room_id')) {
            return jerr("参数错误,缺少mid/room_id");
        }
        $room_id = input('room_id');

        $roomModel = new RoomModel();
        $room = $roomModel->getRoomById($room_id);
        if (!$room) {
            return jerr("房间信息查询失败");
        }

        $mid = input('mid');

        $songList = cache('SongList_' . $room_id) ?? [];
        $removeSong = false;
        for ($i = 0; $i < count($songList); $i++) {
            $item = $songList[$i];
            if ($item['song']['mid'] == $mid) {
                $removeSong = $item;
                array_splice($songList, $i, 1);
                break;
            }
        }
        if (!$removeSong) {
            return jerr("移除失败，歌曲ID不存在");
        }

        if ($room['room_user'] != $this->user['user_id'] && !getIsAdmin($this->user) && $this->user['user_id'] != $removeSong['user']['user_id'] && $this->user['user_id'] != $removeSong['at']['user_id']) {
            return jerr("你没有权限操作");
        }
        cache('SongList_' . $room_id, $songList, 86400);
        $msg = [
            'user' => getUserData($this->user),
            'song' => $removeSong['song'],
            "type" => "removeSong",
            "time" => date('H:i:s'),
        ];
        sendWebsocketMessage('channel', $room_id, $msg);
        return jok('移除成功');
    }
    public function getPlayUrl()
    {
        if (!input('mid')) {
            return jerr('参数错误mid');
            exit;
        }
        $mid = input('mid');
        $url = cache('song_play_temp_url_new_' . $mid) ?? false;
        if ($url) {
            return jok('', [
                'url' => $url,
            ]);
        }
        if ($mid < 0) {
            //自己上传的
            $attachModel = new AttachModel();
            $attach = $attachModel->where('attach_id', (0 - intval($mid)))->find();
            if (!$attach) {
                header("status: 404 Not Found");
                die;
            }
            $path = config('startadmin.static_url') . 'uploads/' . $attach['attach_path'];
            cache('song_play_temp_url_new_' . $mid, $path, 30);
            return jok('', [
                'url' => $path,
            ]);
            die;
        }

        $cookie = cache('cookie');
        $result = curlHelper(config('startadmin.163_api') . '/song/url?id=' . $mid . "&cookie=" . urlencode($cookie));
        $arr = json_decode($result['body'], true);
        if ($arr['code'] != 200) {
            return jerr('歌曲链接获取失败');
        } else {
            if ($arr['data'][0]['url']) {
                $tempList = cache('song_waiting_download_list') ?? [];
                array_push($tempList, [
                    'mid' => $mid,
                    'url' => $arr['data'][0]['url']
                ]);
                cache('song_waiting_download_list', $tempList);
                cache('song_play_temp_url_new_' . $mid, $arr['data'][0]['url'], 30);
                return jok('', [
                    'url' => $arr['data'][0]['url'],
                ]);
            } else {
                return jerr('歌曲链接获取失败');
            }
        }
        die;

        $url = config('startadmin.163_api') . '/song/url?id=' . $mid . "&cookie=" . urlencode($cookie);
        $result = curlHelper($url);
        $arr = json_decode($result['body'], true);
        if ($arr['code'] != 200) {
            return jerr('歌曲链接获取失败');
        } else {
            if ($arr['data'][0]['url']) {
                $tempList = cache('song_waiting_download_list') ?? [];
                array_push($tempList, [
                    'mid' => $mid,
                    'url' => $arr['data'][0]['url']
                ]);
                cache('song_waiting_download_list', $tempList);
                cache('song_play_temp_url_new_' . $mid, $arr['data'][0]['url'], 30);
                return jok('', [
                    'url' => $arr['data'][0]['url'],
                ]);
            } else {
                return jerr('歌曲链接获取失败');
            }
        }
    }
    public function getSongList()
    {
        if (!input('room_id')) {
            return jerr('room_id为必传参数');
        }
        $room_id = input('room_id');
        $songList = cache('SongList_' . $room_id) ?? [];
        return jok('success', $songList);
    }
    public function playUrl()
    {
        if (!input('mid')) {
            header("status: 404 Not Found");
            exit;
        }
        $mid = input('mid');
        $url = cache('song_play_temp_url_new_' . $mid) ?? false;
        if ($url && $mid != 7149583) {
            header("Cache: From Redis");
            header("Location: " . $url);
            die;
        }
        if ($mid < 0) {
            //自己上传的
            $attachModel = new AttachModel();
            $attach = $attachModel->where('attach_id', (0 - intval($mid)))->find();
            if (!$attach) {
                header("status: 404 Not Found");
                die;
            }
            $path = config('startadmin.static_url') . 'uploads/' . $attach['attach_path'];
            cache('song_play_temp_url_new_' . $mid, $path, 30);
            header("Location: " . $path);
            die;
        }

        $cookie = cache('cookie');
        $url = config('startadmin.163_api') . '/song/url?id=' . $mid . "&cookie=" . urlencode($cookie);
        $result = curlHelper($url);
        $arr = json_decode($result['body'], true);
        if ($arr['code'] != 200) {
            //获取播放地址失败了
            die;
        } else {
            if ($arr['data'][0]['url']) {
                $tempList = cache('song_waiting_download_list') ?? [];
                array_push($tempList, [
                    'mid' => $mid,
                    'url' => $arr['data'][0]['url']
                ]);
                cache('song_waiting_download_list', $tempList);
                cache('song_play_temp_url_new_' . $mid, $arr['data'][0]['url'], 30);
                header("Location: " . $arr['data'][0]['url']);
            } else {
                header("status: 404 Not Found");
                return;
            }
        }
        die;

        $url = config('startadmin.163_api') . '/song/url?id=' . $mid . "&cookie=" . urlencode($cookie);
        $result = curlHelper($url, 'GET', null, [], $cookie);
        $arr = json_decode($result['body'], true);
        if ($arr['code'] != 200) {
            //获取播放地址失败了
            die;
        } else {
            if ($arr['data'][0]['url']) {
                $tempList = cache('song_waiting_download_list') ?? [];
                array_push($tempList, [
                    'mid' => $mid,
                    'url' => $arr['data'][0]['url']
                ]);
                cache('song_waiting_download_list', $tempList);
                cache('song_play_temp_url_' . $mid, $arr['data'][0]['url'], 30);
                header("Location: " . $arr['data'][0]['url']);
            } else {
                header("status: 404 Not Found");
                return;
            }
        }
    }
}
